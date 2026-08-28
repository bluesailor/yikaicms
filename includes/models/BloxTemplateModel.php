<?php
/** Blox 模板数据模型。 */

declare(strict_types=1);

final class BloxTemplateModel extends Model
{
    protected string $table = 'blox_templates';
    protected string $defaultOrder = 'updated_at DESC, id DESC';

    public const TYPES = ['section', 'page', 'header', 'footer', 'popup'];
    private const SOURCES = ['user', 'import', 'builtin', 'plugin', 'remote'];

    public static function validType(string $type): bool
    {
        return in_array($type, self::TYPES, true);
    }

    public static function conditionalType(string $type): bool
    {
        return in_array($type, ['header', 'footer', 'popup'], true);
    }

    /**
     * @param array{elements?:list<string>,plugins?:list<string>,design_tokens?:list<string>,design_styles?:list<string>} $requirements
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
        string $sourceRef = '',
        array $metadata = []
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
            'metadata' => json_encode(
                self::normalizeMetadata($metadata),
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
                    'SELECT id,type,name,source,source_ref,schema_version,requirements,metadata,conditions,thumbnail,status,admin_id,created_at,updated_at,published_at'
                    . ' FROM ' . DB_PREFIX . 'blox_templates ORDER BY updated_at DESC, id DESC'
                );
            }
            return db()->fetchAll(
                'SELECT id,type,name,source,source_ref,schema_version,requirements,metadata,conditions,thumbnail,status,admin_id,created_at,updated_at,published_at'
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
            'SELECT id,type,name,source,source_ref,schema_version,draft_data,published_data,requirements,metadata,conditions,thumbnail,status,updated_at,published_at'
            . ' FROM ' . DB_PREFIX . 'blox_templates WHERE id = ?',
            [$id]
        );
    }

    /** @return list<array<string,mixed>> */
    /**
     * 指定区域（header/footer）的已发布候选模板（供激活裁决）。
     *
     * @return list<array<string,mixed>>
     */
    public function publishedAreaTemplates(string $area): array
    {
        if (!self::conditionalType($area)) {
            return [];
        }
        try {
            return db()->fetchAll(
                'SELECT id,name,published_data,conditions,published_at FROM ' . DB_PREFIX . 'blox_templates'
                . ' WHERE status = 1 AND type = ? AND published_data IS NOT NULL'
                . ' ORDER BY id ASC',
                [$area]
            );
        } catch (Throwable) {
            return [];
        }
    }

    public function publishedEditorCatalog(): array
    {
        try {
            return db()->fetchAll(
                'SELECT id,type,name,source,source_ref,requirements,metadata,thumbnail,updated_at,published_at'
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
            'SELECT id,type,name,source,source_ref,schema_version,published_data,requirements,metadata,thumbnail,updated_at,published_at'
            . ' FROM ' . DB_PREFIX . 'blox_templates'
            . ' WHERE id = ? AND status = 1 AND type IN (?, ?) AND published_data IS NOT NULL',
            [$id, 'section', 'page']
        );
    }

    /** 保存草稿正文（编辑器模板模式）；requirements 由重扫推导保持最新。 */
    public function updateDraft(
        int $id,
        string $sectionsJson,
        array $requirements,
        ?string $expectedDraft = null
    ): void
    {
        $where = ' WHERE id = ?';
        $params = [
            $sectionsJson,
            json_encode($requirements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            time(),
            $id,
        ];
        if ($expectedDraft !== null) {
            $where .= ' AND draft_data = ?';
            $params[] = $expectedDraft;
        }
        $affected = db()->execute(
            'UPDATE ' . DB_PREFIX . 'blox_templates SET draft_data = ?, requirements = ?, updated_at = ?' . $where,
            $params
        );
        if ($affected < 1) {
            $current = $this->findForExport($id);
            if ($current && (string) ($current['draft_data'] ?? '') === $sectionsJson) {
                return;
            }
            if ($expectedDraft !== null && $current) {
                throw new RuntimeException(__('blox_save_conflict'));
            }
            throw new RuntimeException(__('blox_tpl_draft_missing'));
        }
    }

    /** 保存激活条件（已经 BloxAreaResolver::parse 净化后的结构）。 */
    public function saveConditions(int $id, array $conditions): void
    {
        $affected = db()->execute(
            'UPDATE ' . DB_PREFIX . 'blox_templates SET conditions = ?, updated_at = ? WHERE id = ?',
            [
                $conditions === [] ? null : json_encode($conditions, JSON_UNESCAPED_UNICODE),
                time(),
                $id,
            ]
        );
        if ($affected < 1) {
            throw new RuntimeException(__('blox_tpl_not_found'));
        }
    }

    /** 保存目录推荐元数据；正文和发布状态不受影响。 */
    public function saveMetadata(int $id, array $metadata): void
    {
        $affected = db()->execute(
            'UPDATE ' . DB_PREFIX . 'blox_templates SET metadata = ?, updated_at = ? WHERE id = ?',
            [
                json_encode(
                    self::normalizeMetadata($metadata),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                time(),
                $id,
            ]
        );
        if ($affected < 1 && !$this->find($id)) {
            throw new RuntimeException(__('blox_tpl_not_found'));
        }
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

    /** @param array{elements?:list<string>,plugins?:list<string>,design_tokens?:list<string>,design_styles?:list<string>} $requirements
     *  @return array{elements:list<string>,plugins:list<string>,design_tokens:list<string>,design_styles:list<string>}
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
            'design_tokens' => $normalize($requirements['design_tokens'] ?? []),
            'design_styles' => $normalize($requirements['design_styles'] ?? []),
        ];
    }

    /** @return array<string,mixed> */
    private static function normalizeMetadata(array $metadata): array
    {
        if (!class_exists('BloxSectionMetadata', false)) {
            require_once ROOT_PATH . '/includes/builder/BloxSectionMetadata.php';
        }
        return BloxSectionMetadata::normalize($metadata);
    }
}
