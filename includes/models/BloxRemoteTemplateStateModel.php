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

    public function rememberInstall(int $templateId, string $version): void
    {
        $now = time();
        $data = [
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
        string $metadata
    ): void {
        $current = $this->forTemplate($templateId);
        $data = [
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
