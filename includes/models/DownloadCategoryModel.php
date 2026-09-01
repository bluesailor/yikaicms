<?php
declare(strict_types=1);

class DownloadCategoryModel extends Model
{
    protected string $table = 'download_categories';
    protected string $defaultOrder = 'sort_order DESC, id ASC';

    /**
     * 获取有效分类
     */
    public function getActive(): array
    {
        $rows = $this->where(['status' => 1], 'sort_order ASC, id ASC');
        foreach ($rows as &$row) {
            $row['name'] = self::localizedName($row);
        }
        unset($row);
        return $rows;
    }

    /**
     * 下载分类是全局记录，名称按当前前台语言读取 name_en/name_ja，缺失时回退中文。
     */
    public static function localizedName(array $category, ?string $lang = null): string
    {
        $lang ??= function_exists('siteLang') ? siteLang() : 'zh-CN';
        $field = match ($lang) {
            'en' => 'name_en',
            'ja' => 'name_ja',
            default => 'name',
        };
        $localized = trim((string) ($category[$field] ?? ''));
        return $localized !== '' ? $localized : (string) ($category['name'] ?? '');
    }

    /** 按 slug 查分类（伪静态路由用）。slug 列未建（未跑迁移）时容错返回 null。 */
    public function findBySlug(string $slug): ?array
    {
        if ($slug === '') return null;
        try {
            return db()->fetchOne(
                "SELECT * FROM {$this->tableName()} WHERE slug = ? LIMIT 1",
                [$slug]
            ) ?: null;
        } catch (\Throwable $e) {
            return null;   // slug 列不存在 → 当作查不到，前端回退 ?cat=id
        }
    }

    /**
     * 把前端 cat 参数解析成分类 id：
     *   - 纯数字 → 直接当 id（兼容旧 ?cat=1）
     *   - 其它   → 当 slug 查（伪静态 /download/{slug}.html）
     * 解析不到返回 0（= 全部）。
     */
    public function resolveId(string $cat): int
    {
        $cat = trim($cat);
        if ($cat === '') return 0;
        if (ctype_digit($cat)) return (int) $cat;
        $row = $this->findBySlug($cat);
        return $row ? (int) $row['id'] : 0;
    }

    /**
     * 获取分类列表（含文件数量统计）
     */
    public function getAllWithCount(): array
    {
        return db()->fetchAll(
            "SELECT c.*, (SELECT COUNT(*) FROM " . DB_PREFIX . "downloads d WHERE d.category_id = c.id) as file_count
             FROM {$this->tableName()} c
             ORDER BY c.sort_order DESC, c.id ASC"
        );
    }

    /**
     * 检查分类下是否有文件
     */
    public function hasFiles(int $categoryId): bool
    {
        $count = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "downloads WHERE category_id = ?",
            [$categoryId]
        );
        return $count > 0;
    }
}
