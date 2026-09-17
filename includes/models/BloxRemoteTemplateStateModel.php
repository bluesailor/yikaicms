<?php
/** Blox 远程模板版本与最近一次更新前草稿。 */

declare(strict_types=1);

final class BloxRemoteTemplateStateModel extends Model
{
    protected string $table = 'blox_remote_template_states';
    protected string $primaryKey = 'template_id';
    protected string $defaultOrder = 'updated_at DESC, template_id DESC';

    public function tableReady(): bool
    {
        return db()->tableExists($this->table);
    }

    public function provenanceReady(): bool
    {
        try {
            db()->fetchAll('SELECT catalog_origin FROM ' . DB_PREFIX . $this->table . ' WHERE 1 = 0');
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function requireOrigin(int $templateId, string $expected = '', bool $lock = false): string
    {
        $row = db()->fetchOne(
            'SELECT catalog_origin FROM ' . DB_PREFIX . $this->table . ' WHERE template_id = ?'
            . ($lock && !db()->isSqlite() ? ' FOR UPDATE' : ''),
            [$templateId]
        );
        $origin = (string) ($row['catalog_origin'] ?? '');
        if ($origin === '' && $expected !== 'community') {
            // 首次信任（2026-09-17 产品决定）：20260915 迁移前装的远程模板没有来源记录，当时远程模板
            // 只来自官方目录。按官方处理——下载时仍校验目录条目确为官方，写入时 stageUpdate 补记来源。
            // 社区条目仍拒绝，已记录的来源照常比对。
            $origin = 'official';
        }
        if (!in_array($origin, ['official', 'community'], true)) {
            throw new RuntimeException(__('blox_tpl_remote_origin_unknown'));
        }
        if ($expected !== '' && $origin !== $expected) {
            throw new RuntimeException(__('blox_tpl_remote_origin_changed'));
        }
        return $origin;
    }

    /** @return array<string,mixed>|null */
    public function forTemplate(int $templateId): ?array
    {
        return $templateId > 0 ? $this->find($templateId) : null;
    }

    /** @param list<int> $templateIds @return array<int,array<string,mixed>> */
    public function mapForTemplates(array $templateIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $templateIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === [] || !$this->tableReady()) {
            return [];
        }

        $rows = db()->fetchAll(
            'SELECT * FROM ' . DB_PREFIX . $this->table
            . ' WHERE template_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $mapped = [];
        foreach ($rows as $row) {
            $mapped[(int) ($row['template_id'] ?? 0)] = $row;
        }
        return $mapped;
    }

    public function rememberInstall(int $templateId, string $version, string $origin): void
    {
        if (!in_array($origin, ['official', 'community'], true)) {
            throw new RuntimeException(__('blox_tpl_remote_origin_unknown'));
        }
        if ($this->forTemplate($templateId)) {
            $this->requireOrigin($templateId, $origin);
        }
        $now = time();
        $data = [
            'catalog_origin' => $origin,
            'installed_version' => self::version($version),
            'backup_version' => '',
            'backup_draft' => null,
            'backup_requirements' => null,
            'backup_metadata' => null,
            'backup_created_at' => 0,
            'updated_at' => $now,
        ];
        if ($this->forTemplate($templateId)) {
            $this->updateById($templateId, $data);
            return;
        }
        $this->create(['template_id' => $templateId] + $data);
    }

    public function stageUpdate(
        int $templateId,
        string $newVersion,
        string $draft,
        string $requirements,
        string $metadata,
        string $origin
    ): void {
        if (!in_array($origin, ['official', 'community'], true)) {
            throw new RuntimeException(__('blox_tpl_remote_origin_unknown'));
        }
        $this->requireOrigin($templateId, $origin, true);
        $current = $this->forTemplate($templateId);
        $data = [
            // 首次信任通过后在此补记来源，之后的更新按记录比对
            'catalog_origin' => $origin,
            'installed_version' => self::version($newVersion),
            'backup_version' => self::version((string) ($current['installed_version'] ?? '')),
            'backup_draft' => $draft,
            'backup_requirements' => $requirements,
            'backup_metadata' => $metadata,
            'backup_created_at' => time(),
            'updated_at' => time(),
        ];
        if ($current) {
            $this->updateById($templateId, $data);
            return;
        }
        $this->create(['template_id' => $templateId] + $data);
    }

    public function finishRollback(int $templateId, string $restoredVersion): void
    {
        $this->updateById($templateId, [
            'installed_version' => self::version($restoredVersion),
            'backup_version' => '',
            'backup_draft' => null,
            'backup_requirements' => null,
            'backup_metadata' => null,
            'backup_created_at' => 0,
            'updated_at' => time(),
        ]);
    }

    private static function version(string $version): string
    {
        $version = trim($version);
        return preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,49}$/', $version) === 1 ? $version : '';
    }
}
