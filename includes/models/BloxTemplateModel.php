<?php
/** Blox 模板数据模型。 */

declare(strict_types=1);

final class BloxTemplateModel extends Model
{
    protected string $table = 'blox_templates';
    protected string $defaultOrder = 'updated_at DESC, id DESC';

    public const TYPES = ['section', 'page', 'header', 'footer'];
    private const SOURCES = ['user', 'import', 'builtin', 'plugin', 'remote'];

    public static function validType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    /**
     * @param array{elements?:list<string>,plugins?:list<string>} $requirements
     */
    public function createDraft(
        string $type,
        string $name,
        string $draftJson,
        string $source = 'user',
        int $schemaVersion = 1,
        array $requirements = [],
        string $thumbnail = '',
        int $adminId = 0,
        string $sourceRef = ''
    ): int {
        $type = trim($type);
        $name = trim($name);
        if (!self::validType($type)) {
            throw new InvalidArgumentException(__('blox_tpl_bad_type_short'));
        }
        if ($name === '' || mb_strlen($name) > 150) {
            throw new InvalidArgumentException(__('blox_tpl_bad_name'));
        }
        if (!in_array($source, self::SOURCES, true)) {
            $source = 'user';
        }

        $now = time();
        return (int) $this->create([
            'type' => $type,
            'name' => $name,
            'source' => $source,
            'source_ref' => mb_substr(trim($sourceRef), 0, 100),
            'schema_version' => max(1, $schemaVersion),
            'draft_data' => $draftJson,
            'published_data' => null,
            'requirements' => json_encode(
                self::normalizeRequirements($requirements),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'thumbnail' => mb_substr(trim($thumbnail), 0, 500),
            'status' => 0,
            'admin_id' => max(0, $adminId),
            'created_at' => $now,
            'updated_at' => $now,
            'published_at' => 0,
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function catalog(?string $type = null): array
    {
        if ($type !== null && !self::validType($type)) {
            throw new InvalidArgumentException(__('blox_tpl_bad_type_short'));
        }
        try {
            if ($type === null) {
                return db()->fetchAll(
                    'SELECT id,type,name,source,source_ref,schema_version,thumbnail,status,admin_id,created_at,updated_at,published_at'
                    . ' FROM ' . DB_PREFIX . 'blox_templates ORDER BY updated_at DESC, id DESC'
                );
            }
            return db()->fetchAll(
                'SELECT id,type,name,source,source_ref,schema_version,thumbnail,status,admin_id,created_at,updated_at,published_at'
                . ' FROM ' . DB_PREFIX . 'blox_templates WHERE type = ? ORDER BY updated_at DESC, id DESC',
                [$type]
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public function findForExport(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return db()->fetchOne(
            'SELECT id,type,name,source,source_ref,schema_version,draft_data,published_data,requirements,thumbnail,status,updated_at,published_at'
            . ' FROM ' . DB_PREFIX . 'blox_templates WHERE id = ?',
            [$id]
        );
    }

    /** @return list<array<string,mixed>> */
    public function publishedEditorCatalog(): array
    {
        try {
            return db()->fetchAll(
                'SELECT id,type,name,source,source_ref,requirements,thumbnail,updated_at,published_at'
                . ' FROM ' . DB_PREFIX . 'blox_templates'
                . ' WHERE status = 1 AND type IN (?, ?) AND published_data IS NOT NULL'
                . ' ORDER BY updated_at DESC, id DESC',
                ['section', 'page']
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public function findPublishedForEditor(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return db()->fetchOne(
            'SELECT id,type,name,source,source_ref,schema_version,published_data,requirements,thumbnail,updated_at,published_at'
            . ' FROM ' . DB_PREFIX . 'blox_templates'
            . ' WHERE id = ? AND status = 1 AND type IN (?, ?) AND published_data IS NOT NULL',
            [$id, 'section', 'page']
        );
    }

    public function publishDraft(int $id): void
    {
        $row = $this->find($id);
        if (!$row || trim((string) ($row['draft_data'] ?? '')) === '') {
            throw new RuntimeException(__('blox_tpl_draft_missing'));
        }
        $now = time();
        $this->updateById($id, [
            'published_data' => (string) $row['draft_data'],
            'status' => 1,
            'updated_at' => $now,
            'published_at' => $now,
        ]);
    }

    public function unpublish(int $id): void
    {
        if (!$this->find($id)) {
            throw new RuntimeException(__('blox_tpl_not_found'));
        }
        $this->updateById($id, [
            'status' => 0,
            'updated_at' => time(),
            'published_at' => 0,
        ]);
    }

    /** @param array{elements?:list<string>,plugins?:list<string>} $requirements
     *  @return array{elements:list<string>,plugins:list<string>}
     */
    private static function normalizeRequirements(array $requirements): array
    {
        $normalize = static function (mixed $items): array {
            if (!is_array($items)) {
                return [];
            }
            $values = [];
            foreach ($items as $item) {
                if (is_string($item) && trim($item) !== '') {
                    $values[] = trim($item);
                }
            }
            $values = array_values(array_unique($values));
            sort($values);
            return $values;
        };

        return [
            'elements' => $normalize($requirements['elements'] ?? []),
            'plugins' => $normalize($requirements['plugins'] ?? []),
        ];
    }
}
