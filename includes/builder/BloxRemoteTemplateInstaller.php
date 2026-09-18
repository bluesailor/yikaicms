<?php
/** Blox 远程模板安装：授权下载、完整性校验与本地落库的单一入口。 */

declare(strict_types=1);

final class BloxRemoteTemplateInstaller
{
    private BloxRemoteTemplateProvider $provider;

    public function __construct(?BloxRemoteTemplateProvider $provider = null)
    {
        $this->provider = $provider ?? new BloxRemoteTemplateProvider();
    }

    /**
     * 一键安装兼容入口：管理页已改走 prepare/confirm 两段式，本方法保留给测试与
     * 未来插件侧直连安装。
     * @psalm-suppress PossiblyUnusedMethod
     * @return array{id:int,type:string,name:string,sections:int,previous_sections:int,version:string,updated:bool,backup_created:bool}
     */
    public function install(string $slug, int $adminId = 0): array
    {
        if (bloxTemplateModel()->findWhere(['source' => 'remote', 'source_ref' => $slug])) {
            throw new RuntimeException(__('blox_tpl_remote_already_imported'));
        }
        $stateModel = bloxRemoteTemplateStateModel();
        if (!$stateModel->provenanceReady()) {
            throw new RuntimeException(__('blox_tpl_remote_state_table_missing'));
        }
        $package = $this->provider->fetchVerifiedPackage($slug);
        $json = $package['json'];
        $version = trim((string) ($package['item']['version'] ?? ''));
        $existing = bloxTemplateModel()->findWhere(['source' => 'remote', 'source_ref' => $slug]);

        if (!$existing) {
            $created = BloxTemplateImporter::importJson($json, $adminId, 'remote', $slug);
            try {
                $stateModel->rememberInstall((int) $created['id'], $version, (string) $package['item']['catalog_origin']);
            } catch (Throwable $e) {
                db()->delete('blox_templates', 'id = ?', [(int) $created['id']]);
                throw $e;
            }
            return $created + [
                'previous_sections' => 0,
                'version' => $version,
                'updated' => false,
                'backup_created' => false,
            ];
        }

        throw new RuntimeException(__('blox_tpl_remote_already_imported'));
    }

    /**
     * 一键副本兼容入口：管理页已改走 prepare/confirm 两段式，本方法保留给测试。
     * @psalm-suppress PossiblyUnusedMethod
     * @return array{id:int,type:string,name:string,sections:int}
     */
    public function importCopy(string $slug, int $adminId = 0): array
    {
        $package = $this->provider->fetchVerifiedPackage($slug);
        // Imported copies retain provenance but never match the managed remote source.
        return BloxTemplateImporter::importJson($package['json'], $adminId, 'import', $slug);
    }

    /**
     * 检查阶段（安装）：下载验证 + 依赖诊断 + 登记服务端待确认记录；不写模板。
     * @return array{review_id:string,operation:string,slug:string,version:string,name:string,type:string,sections:int,previous_sections:int,requirements:array<string,mixed>,design_diagnostics:array<string,mixed>}
     */
    public function prepareInstall(string $slug, int $adminId): array
    {
        if (bloxTemplateModel()->findWhere(['source' => 'remote', 'source_ref' => $slug])) {
            throw new RuntimeException(__('blox_tpl_remote_already_imported'));
        }
        $this->assertStateTable();
        return $this->preparePackage('install', $slug, $adminId, 0, '');
    }

    /**
     * 确认阶段（安装）：只消费服务端记录里的已验证包；映射经服务端重校验。
     * @param array<string,mixed> $designOptions
     * @return array{id:int,type:string,name:string,sections:int,previous_sections:int,version:string,updated:bool,backup_created:bool}
     */
    public function confirmInstall(string $reviewId, array $designOptions, int $adminId): array
    {
        $opened = $this->openReview($reviewId, $adminId, 'install');
        if ($opened['replay'] !== null) {
            return $opened['replay'];
        }
        $review = $opened['review'];
        $slug = (string) $review['source_key'];
        $this->assertStateTable();
        $origin = self::reviewOrigin($review);
        if (!BloxImportReview::claim($reviewId)) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        try {
            if (bloxTemplateModel()->findWhere(['source' => 'remote', 'source_ref' => $slug])) {
                throw new RuntimeException(__('blox_tpl_remote_already_imported'));
            }
            $created = BloxTemplateImporter::importJson(
                (string) $review['package_json'],
                $adminId,
                'remote',
                $slug,
                $designOptions
            );
            try {
                bloxRemoteTemplateStateModel()->rememberInstall((int) $created['id'], (string) $review['package_version'], $origin);
            } catch (Throwable $e) {
                db()->delete('blox_templates', 'id = ?', [(int) $created['id']]);
                throw $e;
            }
            BloxImportReview::recordResult($reviewId, 'template:' . $created['id']);
            return $created + [
                'previous_sections' => 0,
                'version' => (string) $review['package_version'],
                'updated' => false,
                'backup_created' => false,
            ];
        } catch (Throwable $e) {
            bloxImportReviewModel()->release($reviewId);
            throw $e;
        }
    }

    /**
     * 检查阶段（另存副本）：诊断 + 登记；确认后以 import 来源落库，不留 managed 状态。
     * @return array{review_id:string,operation:string,slug:string,version:string,name:string,type:string,sections:int,previous_sections:int,requirements:array<string,mixed>,design_diagnostics:array<string,mixed>}
     */
    public function prepareCopy(string $slug, int $adminId): array
    {
        return $this->preparePackage('import_copy', $slug, $adminId, 0, '');
    }

    /**
     * 确认阶段（另存副本）。
     * @param array<string,mixed> $designOptions
     * @return array{id:int,type:string,name:string,sections:int}
     */
    public function confirmCopy(string $reviewId, array $designOptions, int $adminId): array
    {
        $opened = $this->openReview($reviewId, $adminId, 'import_copy');
        if ($opened['replay'] !== null) {
            return $opened['replay'];
        }
        $review = $opened['review'];
        $slug = (string) $review['source_key'];
        if (!BloxImportReview::claim($reviewId)) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        try {
            // Imported copies retain provenance but never match the managed remote source.
            $created = BloxTemplateImporter::importJson((string) $review['package_json'], $adminId, 'import', $slug, $designOptions);
            BloxImportReview::recordResult($reviewId, 'template:' . $created['id']);
            return $created;
        } catch (Throwable $e) {
            bloxImportReviewModel()->release($reviewId);
            throw $e;
        }
    }

    /**
     * 检查阶段（更新）：校验目标草稿基线后下载新包并登记；确认前不改草稿。
     * @return array{review_id:string,operation:string,slug:string,version:string,name:string,type:string,sections:int,previous_sections:int,requirements:array<string,mixed>,design_diagnostics:array<string,mixed>}
     */
    public function prepareUpdate(int $id, string $baseRevision, int $adminId): array
    {
        $this->assertStateTable();
        $existing = bloxTemplateModel()->findForExport($id);
        if (!$existing || ($existing['source'] ?? '') !== 'remote') {
            throw new RuntimeException(__('blox_tpl_not_found'));
        }
        if ($baseRevision === '' || !hash_equals(self::revision($existing), $baseRevision)) {
            throw new RuntimeException(__('blox_save_conflict'));
        }
        $origin = bloxRemoteTemplateStateModel()->requireOrigin($id);
        $prepared = $this->preparePackage('update', (string) $existing['source_ref'], $adminId, $id, $baseRevision, $origin);
        if ($prepared['type'] !== (string) $existing['type']) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }
        $prepared['previous_sections'] = self::sectionCount((string) ($existing['draft_data'] ?? ''));
        return $prepared;
    }

    /**
     * 确认阶段（更新）：领取与写入同事务，保留锁内 revision/版本/备份语义，只改草稿。
     * @param array<string,mixed> $designOptions
     * @return array{id:int,type:string,name:string,sections:int,previous_sections:int,version:string,updated:bool,backup_created:bool}
     */
    public function confirmUpdate(string $reviewId, array $designOptions, int $adminId): array
    {
        $opened = $this->openReview($reviewId, $adminId, 'update');
        if ($opened['replay'] !== null) {
            return $opened['replay'];
        }
        $review = $opened['review'];
        $this->assertStateTable();
        $id = (int) $review['target_id'];
        $slug = (string) $review['source_key'];
        $baseRevision = (string) $review['target_revision'];
        $version = (string) $review['package_version'];
        $origin = self::reviewOrigin($review);
        bloxRemoteTemplateStateModel()->requireOrigin($id, $origin);

        $existing = bloxTemplateModel()->findForExport($id);
        if (!$existing || ($existing['source'] ?? '') !== 'remote') {
            throw new RuntimeException(__('blox_tpl_not_found'));
        }
        if (!hash_equals(self::revision($existing), $baseRevision)) {
            throw new RuntimeException(__('blox_save_conflict'));
        }
        $prepared = BloxTemplateImporter::prepare((string) $review['package_json'], $designOptions);
        if ($prepared['type'] !== (string) $existing['type']) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }

        db()->beginTransaction();
        try {
            // Re-read after the check window and lock before changing draft or backup.
            $current = bloxTemplateModel()->findForExport($id, true);
            if (!$current || ($current['source'] ?? '') !== 'remote'
                || ($current['source_ref'] ?? '') !== $slug
                || ($current['type'] ?? '') !== $prepared['type']
                || !hash_equals(self::revision($current), $baseRevision)) {
                throw new RuntimeException(__('blox_save_conflict'));
            }
            if (!BloxImportReview::claim($reviewId)) {
                throw new RuntimeException(__('blox_import_review_invalid'));
            }
            $existingDraft = (string) ($current['draft_data'] ?? '');
            $previousSections = self::sectionCount($existingDraft);
            // 保留已发布文档、显示条件和发布状态；更新前草稿与依赖留作一次回退点。
            bloxRemoteTemplateStateModel()->stageUpdate(
                $id,
                $version,
                $existingDraft,
                (string) ($current['requirements'] ?? ''),
                (string) ($current['metadata'] ?? ''),
                $origin
            );
            bloxTemplateModel()->updateDraft(
                $id,
                $prepared['draft_json'],
                $prepared['requirements'],
                $existingDraft
            );
            bloxTemplateModel()->saveMetadata($id, $prepared['metadata']);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }
        BloxImportReview::recordResult($reviewId, 'template:' . $id);

        return [
            'id' => $id,
            'type' => $prepared['type'],
            'name' => $prepared['name'],
            'sections' => count($prepared['sections']),
            'previous_sections' => $previousSections,
            'version' => $version,
            'updated' => true,
            'backup_created' => true,
        ];
    }

    /**
     * 打开待确认记录：命中幂等重放时 replay 为首次结果（调用方直接返回），
     * 否则为 null 且 review 已通过属主/操作/TTL/包/设计校验。
     * @return array{review:array<string,mixed>,replay:array<string,mixed>|null}
     */
    private function openReview(string $reviewId, int $adminId, string $operation): array
    {
        $review = BloxImportReview::find($reviewId);
        if ($review === null) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        $replayRef = BloxImportReview::replayableResult($review, $adminId, $operation);
        if ($replayRef !== '') {
            $replayed = $this->replayTemplateResult($replayRef, $operation, $review);
            if ($replayed === null) {
                throw new RuntimeException(__('blox_import_review_invalid'));
            }
            return ['review' => $review, 'replay' => $replayed];
        }
        BloxImportReview::verify($review, $adminId, $operation);
        return ['review' => $review, 'replay' => null];
    }

    /** 幂等重放：网络丢失后重试返回首次结果，不重复建模板或备份。 */
    private function replayTemplateResult(string $replayRef, string $operation, array $review): ?array
    {
        if (preg_match('/^template:(\d+)$/', $replayRef, $match) !== 1) {
            return null;
        }
        $id = (int) $match[1];
        $row = bloxTemplateModel()->findForExport($id);
        if (!$row || ($row['source'] ?? '') !== ($operation === 'import_copy' ? 'import' : 'remote')
            || ($row['source_ref'] ?? '') !== (string) $review['source_key']) {
            return null;
        }
        $sections = self::sectionCount((string) ($row['draft_data'] ?? ''));
        if ($operation === 'install') {
            return [
                'id' => $id,
                'type' => (string) $row['type'],
                'name' => (string) $row['name'],
                'sections' => $sections,
                'previous_sections' => 0,
                'version' => (string) $review['package_version'],
                'updated' => false,
                'backup_created' => false,
            ];
        }
        if ($operation === 'import_copy') {
            return ['id' => $id, 'type' => (string) $row['type'], 'name' => (string) $row['name'], 'sections' => $sections];
        }
        $state = bloxRemoteTemplateStateModel()->forTemplate($id);
        return [
            'id' => $id,
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'sections' => $sections,
            'previous_sections' => self::sectionCount((string) ($state['backup_draft'] ?? '')),
            'version' => (string) $review['package_version'],
            'updated' => true,
            'backup_created' => true,
        ];
    }

    /**
     * 检查阶段共用段：下载验证 + 诊断 + 登记待确认记录。
     * @return array{review_id:string,operation:string,slug:string,version:string,name:string,type:string,sections:int,previous_sections:int,requirements:array<string,mixed>,design_diagnostics:array<string,mixed>}
     */
    private function preparePackage(string $operation, string $slug, int $adminId, int $targetId, string $targetRevision, string $expectedOrigin = ''): array
    {
        $package = $this->provider->fetchVerifiedPackage($slug, $expectedOrigin);
        $json = $package['json'];
        $version = trim((string) ($package['item']['version'] ?? ''));
        $prepared = BloxTemplateImporter::prepare($json);
        $review = BloxImportReview::issue([
            'operation' => $operation,
            'source_key' => $slug,
            'source_type' => 'remote',
            'catalog_origin' => (string) $package['item']['catalog_origin'],
            'template_type' => $prepared['type'],
            'package_json' => $json,
            'package_version' => $version,
            'target_id' => $targetId,
            'target_revision' => $targetRevision,
            'admin_id' => $adminId,
        ]);
        return [
            'review_id' => (string) $review['id'],
            'operation' => $operation,
            'slug' => $slug,
            'version' => $version,
            'name' => $prepared['name'],
            'type' => $prepared['type'],
            'sections' => count($prepared['sections']),
            'previous_sections' => 0,
            'requirements' => $prepared['requirements'],
            'design_diagnostics' => $prepared['design_diagnostics'],
        ];
    }

    private function assertStateTable(): void
    {
        if (!bloxRemoteTemplateStateModel()->provenanceReady()) {
            throw new RuntimeException(__('blox_tpl_remote_state_table_missing'));
        }
    }

    private static function reviewOrigin(array $review): string
    {
        $origin = (string) ($review['catalog_origin'] ?? '');
        if (!in_array($origin, ['official', 'community'], true)) {
            throw new RuntimeException(__('blox_import_review_invalid'));
        }
        return $origin;
    }

    /** @param array<string,mixed> $template */
    public static function revision(array $template): string
    {
        return hash('sha256', json_encode([
            (string) ($template['draft_data'] ?? ''),
            (string) ($template['requirements'] ?? ''),
            (string) ($template['metadata'] ?? ''),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * 一键更新兼容入口：管理页已改走 prepare/confirm 两段式，本方法保留给测试。
     * @psalm-suppress PossiblyUnusedMethod
     * @return array{id:int,type:string,name:string,sections:int,previous_sections:int,version:string,updated:bool,backup_created:bool}
     */
    public function update(int $id, string $baseRevision, string $expectedVersion): array
    {
        $stateModel = bloxRemoteTemplateStateModel();
        if (!$stateModel->provenanceReady()) {
            throw new RuntimeException(__('blox_tpl_remote_state_table_missing'));
        }
        $existing = bloxTemplateModel()->findForExport($id);
        if (!$existing || ($existing['source'] ?? '') !== 'remote') {
            throw new RuntimeException(__('blox_tpl_not_found'));
        }
        if ($baseRevision === '' || !hash_equals(self::revision($existing), $baseRevision)) {
            throw new RuntimeException(__('blox_save_conflict'));
        }
        $slug = (string) $existing['source_ref'];
        $origin = $stateModel->requireOrigin($id);
        $package = $this->provider->fetchVerifiedPackage($slug, $origin);
        $version = (string) ($package['item']['version'] ?? '');
        if ($expectedVersion === '' || !hash_equals($expectedVersion, $version)) {
            throw new RuntimeException(__('blox_tpl_remote_version_conflict'));
        }
        $prepared = BloxTemplateImporter::prepare($package['json']);
        if ($prepared['type'] !== $existing['type']) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }
        db()->beginTransaction();
        try {
            // Re-read after the network request and lock before changing draft or backup.
            $current = bloxTemplateModel()->findForExport($id, true);
            if (!$current || ($current['source'] ?? '') !== 'remote'
                || ($current['source_ref'] ?? '') !== $slug
                || ($current['type'] ?? '') !== $prepared['type']
                || !hash_equals(self::revision($current), $baseRevision)) {
                throw new RuntimeException(__('blox_save_conflict'));
            }
            $existingDraft = (string) ($current['draft_data'] ?? '');
            $previousSections = self::sectionCount($existingDraft);
            // 保留已发布文档、显示条件和发布状态；更新前草稿与依赖留作一次回退点。
            $stateModel->stageUpdate(
                $id,
                $version,
                $existingDraft,
                (string) ($current['requirements'] ?? ''),
                (string) ($current['metadata'] ?? ''),
                $origin
            );
            bloxTemplateModel()->updateDraft(
                $id,
                $prepared['draft_json'],
                $prepared['requirements'],
                $existingDraft
            );
            bloxTemplateModel()->saveMetadata($id, $prepared['metadata']);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }

        return [
            'id' => $id,
            'type' => $prepared['type'],
            'name' => $prepared['name'],
            'sections' => count($prepared['sections']),
            'previous_sections' => $previousSections,
            'version' => $version,
            'updated' => true,
            'backup_created' => true,
        ];
    }

    /** @return array{id:int,version:string,sections:int} */
    public function rollback(int $templateId): array
    {
        $stateModel = bloxRemoteTemplateStateModel();
        if (!$stateModel->tableReady()) {
            throw new RuntimeException(__('blox_tpl_remote_state_table_missing'));
        }
        $template = bloxTemplateModel()->findForExport($templateId);
        $state = $stateModel->forTemplate($templateId);
        if (!$template || (string) ($template['source'] ?? '') !== 'remote') {
            throw new RuntimeException(__('blox_tpl_not_found'));
        }
        $backupDraft = is_array($state) ? (string) ($state['backup_draft'] ?? '') : '';
        if ($backupDraft === '') {
            throw new RuntimeException(__('blox_tpl_remote_no_backup'));
        }

        $requirements = self::decodeArray((string) ($state['backup_requirements'] ?? ''));
        $metadata = self::decodeArray((string) ($state['backup_metadata'] ?? ''));
        $currentDraft = (string) ($template['draft_data'] ?? '');
        // 备份来自同一模板的旧草稿：以当前草稿为可信基线，旧专业配置不能借回滚重新开启或改动。
        BloxDocumentPipeline::assertAuthoringAllowed(
            BloxDocumentPipeline::decode($backupDraft)['sections'],
            trim($currentDraft) !== '' ? $currentDraft : '[]'
        );
        $restoredVersion = trim((string) ($state['backup_version'] ?? ''));
        db()->beginTransaction();
        try {
            bloxTemplateModel()->updateDraft($templateId, $backupDraft, $requirements, $currentDraft);
            bloxTemplateModel()->saveMetadata($templateId, $metadata);
            $stateModel->finishRollback($templateId, $restoredVersion);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollback();
            throw $e;
        }

        return [
            'id' => $templateId,
            'version' => $restoredVersion,
            'sections' => self::sectionCount($backupDraft),
        ];
    }

    /** @return array<string,mixed> */
    private static function decodeArray(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }
        return is_array($decoded) ? $decoded : [];
    }

    private static function sectionCount(string $json): int
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 0;
        }
        return is_array($decoded) ? count(BloxDocumentPipeline::extractSections($decoded)) : 0;
    }
}
