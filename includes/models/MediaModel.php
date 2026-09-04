<?php
declare(strict_types=1);

class MediaModel extends Model
{
    public const SORT_DEFAULT = 'default';
    public const SORT_NEWEST = 'newest';
    public const SORT_OLDEST = 'oldest';
    public const SORT_LARGEST = 'largest';
    public const SORT_SMALLEST = 'smallest';
    public const SORT_NAME = 'name';

    protected string $table = 'media';
    protected string $defaultOrder = 'id DESC';

    /** @param list<string> $fallback @return list<string> */
    private static function configuredExtensions(string $constantName, array $fallback): array
    {
        if (!defined($constantName)) {
            return $fallback;
        }
        return array_values(array_filter(array_map(
            static fn(mixed $extension): string => strtolower(ltrim(trim((string) $extension), '.')),
            (array) constant($constantName)
        )));
    }

    /** @return list<string> */
    public static function supportedExtensions(): array
    {
        return array_values(array_unique(array_merge(
            self::configuredExtensions('UPLOAD_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']),
            self::configuredExtensions('UPLOAD_FILE_TYPES', ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z']),
            self::configuredExtensions('UPLOAD_VIDEO_TYPES', ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v'])
        )));
    }

    public static function typeForExtension(string $extension): string
    {
        $extension = strtolower(ltrim(trim($extension), '.'));
        $imageExtensions = self::configuredExtensions('UPLOAD_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']);
        $videoExtensions = self::configuredExtensions('UPLOAD_VIDEO_TYPES', ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v']);

        if (in_array($extension, $imageExtensions, true)) {
            return 'image';
        }
        if (in_array($extension, $videoExtensions, true)) {
            return 'video';
        }
        return 'file';
    }

    public static function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_DEFAULT,
            self::SORT_NEWEST,
            self::SORT_OLDEST,
            self::SORT_LARGEST,
            self::SORT_SMALLEST,
            self::SORT_NAME,
        ], true) ? $sort : self::SORT_DEFAULT;
    }

    /**
     * 与数据库 ORDER BY 使用同一规则，供内置素材和上传素材合并分页。
     *
     * @param array<string,mixed> $left
     * @param array<string,mixed> $right
     */
    public static function compareItems(array $left, array $right, string $sort, int $preferredMinWidth = 0): int
    {
        $sort = self::normalizeSort($sort);
        $preferredMinWidth = max(0, min(10000, $preferredMinWidth));
        if ($preferredMinWidth > 0) {
            $leftPreferred = (int) ($left['width'] ?? 0) >= $preferredMinWidth ? 0 : 1;
            $rightPreferred = (int) ($right['width'] ?? 0) >= $preferredMinWidth ? 0 : 1;
            if ($leftPreferred !== $rightPreferred) {
                return $leftPreferred <=> $rightPreferred;
            }
        }

        if ($sort === self::SORT_DEFAULT) {
            $leftBuiltin = !empty($left['builtin']) ? 0 : 1;
            $rightBuiltin = !empty($right['builtin']) ? 0 : 1;
            if ($leftBuiltin !== $rightBuiltin) {
                return $leftBuiltin <=> $rightBuiltin;
            }
        }

        $comparison = match ($sort) {
            self::SORT_NEWEST => (int) ($right['created_at'] ?? 0) <=> (int) ($left['created_at'] ?? 0),
            self::SORT_OLDEST => (int) ($left['created_at'] ?? 0) <=> (int) ($right['created_at'] ?? 0),
            self::SORT_LARGEST => (int) ($right['size'] ?? 0) <=> (int) ($left['size'] ?? 0),
            self::SORT_SMALLEST => (int) ($left['size'] ?? 0) <=> (int) ($right['size'] ?? 0),
            self::SORT_NAME => strnatcasecmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? '')),
            default => (int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0),
        };
        if ($comparison !== 0) {
            return $comparison;
        }

        $ascendingId = in_array($sort, [self::SORT_OLDEST, self::SORT_SMALLEST], true);
        $leftId = is_numeric($left['id'] ?? null) ? (int) $left['id'] : 0;
        $rightId = is_numeric($right['id'] ?? null) ? (int) $right['id'] : 0;
        $idComparison = $ascendingId ? ($leftId <=> $rightId) : ($rightId <=> $leftId);
        return $idComparison !== 0
            ? $idComparison
            : strcmp((string) ($left['url'] ?? ''), (string) ($right['url'] ?? ''));
    }

    /**
     * 获取媒体列表（分页+筛选）
     */
    public function getList(array $filters = [], int $limit = 24, int $offset = 0): array
    {
        $where = [];
        $params = [];
        $preferredMinWidth = max(0, min(10000, (int) ($filters['preferred_min_width'] ?? 0)));
        $sort = self::normalizeSort((string) ($filters['sort'] ?? self::SORT_DEFAULT));

        if (!empty($filters['type'])) {
            $where[] = 'type = ?';
            $params[] = $filters['type'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = 'name LIKE ?';
            $params[] = '%' . $filters['keyword'] . '%';
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} {$whereSQL}",
            $params
        );

        $order = match ($sort) {
            self::SORT_NEWEST => 'created_at DESC, id DESC',
            self::SORT_OLDEST => 'created_at ASC, id ASC',
            self::SORT_LARGEST => 'size DESC, id DESC',
            self::SORT_SMALLEST => 'size ASC, id ASC',
            self::SORT_NAME => 'name ASC, id DESC',
            default => $this->defaultOrder,
        };
        if ($preferredMinWidth > 0 && (($filters['type'] ?? '') === 'image')) {
            $order = "CASE WHEN width >= {$preferredMinWidth} THEN 0 ELSE 1 END ASC, {$order}";
        }

        $items = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} {$whereSQL} ORDER BY {$order} LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    public function getByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE id IN ({$placeholders})",
            $ids
        );
    }

    public function countImages(): int
    {
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} WHERE type = ?",
            ['image']
        );
    }

    /** @return list<array<string,mixed>> */
    public function getImageBatchAfterId(int $afterId, int $limit): array
    {
        $afterId = max(0, $afterId);
        $limit = max(1, min(MediaOptimization::MAX_BATCH, $limit));

        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE type = ? AND id > ? ORDER BY id ASC LIMIT ?",
            ['image', $afterId, $limit]
        );
    }

    /** @psalm-api Called by the standalone media admin endpoint. */
    public function deleteById(int|string $id): int
    {
        $result = parent::deleteById($id);
        $this->deleteRemoteImportMappings([(int) $id]);
        return $result;
    }

    /** @psalm-api Called by the standalone media admin endpoint. */
    public function deleteByIds(array $ids): int
    {
        $normalized = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        $result = parent::deleteByIds($normalized);
        $this->deleteRemoteImportMappings($normalized);
        return $result;
    }

    /** @param list<int> $ids */
    private function deleteRemoteImportMappings(array $ids): void
    {
        if ($ids === [] || !db()->tableExists('media_remote_imports')) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        db()->execute(
            "DELETE FROM " . DB_PREFIX . "media_remote_imports WHERE media_id IN ({$placeholders})",
            $ids
        );
    }
}
