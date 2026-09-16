<?php
/** 导入检查-确认协议：检查阶段登记已验证包，确认只消费服务端记录。 */

declare(strict_types=1);

final class BloxImportReview
{
    public const OPERATIONS = ['install', 'import_copy', 'update', 'canvas_insert'];
    public const TTL_SECONDS = 1800;
    public const MAX_PENDING_PER_ADMIN = 5;

    /**
     * 检查阶段登记一条待确认记录：包内容只留在服务端，浏览器拿不到也改不了。
     *
     * @param array<string,mixed> $fields operation/source_key/source_type/template_type/
     *        package_json/package_version/target_id/target_revision/admin_id
     * @return array<string,mixed> 完整记录行
     */
    public static function issue(array $fields): array
    {
        $model = bloxImportReviewModel();
        if (!$model->tableReady()) {
            throw new RuntimeException(__('blox_import_review_table_missing'));
        }
        $operation = (string) ($fields['operation'] ?? '');
        if (!in_array($operation, self::OPERATIONS, true)) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        $packageJson = (string) ($fields['package_json'] ?? '');
        if ($packageJson === '' || strlen($packageJson) > BloxTemplateImporter::MAX_BYTES) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        $adminId = (int) ($fields['admin_id'] ?? 0);
        if ($adminId <= 0) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        $origin = (string) ($fields['catalog_origin'] ?? '');
        if (!in_array($origin, ['', 'official', 'community'], true)) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        $now = time();
        // 容量控制只动当前管理员的过期/最旧记录，不扫全表。
        $model->deleteExpired($adminId, $now);
        $pending = $model->pendingFor($adminId);
        while (count($pending) >= self::MAX_PENDING_PER_ADMIN) {
            $evict = array_pop($pending);
            db()->delete('blox_import_reviews', 'id = ?', [(string) ($evict['id'] ?? '')]);
        }

        $row = [
            'id' => bin2hex(random_bytes(16)),
            'admin_id' => $adminId,
            'operation' => $operation,
            'source_key' => substr(trim((string) ($fields['source_key'] ?? '')), 0, 128),
            'source_type' => substr(trim((string) ($fields['source_type'] ?? '')), 0, 32),
            'catalog_origin' => $origin,
            'template_type' => substr(trim((string) ($fields['template_type'] ?? '')), 0, 64),
            'package_sha256' => hash('sha256', $packageJson),
            'package_json' => $packageJson,
            'package_version' => substr(trim((string) ($fields['package_version'] ?? '')), 0, 50),
            'target_id' => max(0, (int) ($fields['target_id'] ?? 0)),
            'target_revision' => substr(trim((string) ($fields['target_revision'] ?? '')), 0, 64),
            'result_ref' => '',
            'design_revision' => (int) (BloxDesignSystem::snapshot()['revision'] ?? 0),
            'created_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
            'consumed_at' => 0,
        ];
        $model->insert($row);
        return $row;
    }

    /** @return array<string,mixed>|null */
    public static function find(string $reviewId): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $reviewId)) {
            return null;
        }
        return bloxImportReviewModel()->find($reviewId);
    }

    /**
     * 确认前的服务端校验：属主/操作/TTL/包自洽 + 检查后设计未变。
     * 任何一项不符都要求重新检查，客户端提交的任何字段都不参与信任。
     *
     * @param array<string,mixed> $row
     */
    public static function verify(array $row, int $adminId, string $operation): void
    {
        if ((int) ($row['admin_id'] ?? 0) !== $adminId) {
            throw new RuntimeException(__('blox_import_review_owner'));
        }
        if ((string) ($row['operation'] ?? '') !== $operation) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        if ((int) ($row['expires_at'] ?? 0) < time()) {
            throw new RuntimeException(__('blox_import_review_expired'));
        }
        if (!hash_equals((string) ($row['package_sha256'] ?? ''), hash('sha256', (string) ($row['package_json'] ?? '')))) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        if ((int) ($row['design_revision'] ?? -1) !== (int) (BloxDesignSystem::snapshot()['revision'] ?? 0)) {
            throw new RuntimeException(__('blox_import_design_changed'));
        }
    }

    /**
     * 写路径领取：必须在写事务内调用（失败回滚即归还）。返回 false 表示已被消费。
     */
    public static function claim(string $reviewId): bool
    {
        return bloxImportReviewModel()->claim($reviewId, time());
    }

    /** 写路径成功后回填结果引用，供同 TTL 内的幂等重放。 */
    public static function recordResult(string $reviewId, string $resultRef): void
    {
        db()->update(
            'blox_import_reviews',
            ['result_ref' => substr($resultRef, 0, 128)],
            'id = ? AND consumed_at > 0',
            [$reviewId]
        );
    }

    /**
     * 领取失败后的幂等重放：同管理员、同 TTL、已回填结果 → 原样返回结果引用。
     * 网络丢失重试因此不会产生第二条模板或第二次备份。
     *
     * @param array<string,mixed> $row
     * @return string 已记录的结果引用；空串表示不可重放
     */
    public static function replayableResult(array $row, int $adminId, string $operation): string
    {
        if ((int) ($row['admin_id'] ?? 0) !== $adminId
            || (string) ($row['operation'] ?? '') !== $operation
            || (int) ($row['expires_at'] ?? 0) < time()
            || (int) ($row['consumed_at'] ?? 0) === 0) {
            return '';
        }
        return trim((string) ($row['result_ref'] ?? ''));
    }
}
