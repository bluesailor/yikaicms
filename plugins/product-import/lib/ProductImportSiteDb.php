<?php
/**
 * 产品导入：ProductImportSite 的数据库实现（按一种语言看本站分类与产品）。
 */

declare(strict_types=1);

final class ProductImportSiteDb implements ProductImportSite
{
    public function __construct(private string $lang, private string $siteRoot)
    {
    }

    public function categories(): array
    {
        $rows = db()->fetchAll('SELECT id, slug, name, parent_id, image FROM ' . DB_PREFIX . 'product_categories WHERE lang = ? ORDER BY id', [$this->lang]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'slug' => (string) $row['slug'], 'name' => (string) $row['name'],
            'parent_id' => (int) $row['parent_id'], 'image' => trim((string) $row['image']),
        ], $rows);
    }

    public function productByImportKey(string $key): ?array
    {
        $row = db()->fetchOne(
            'SELECT p.id, p.title, p.slug FROM ' . DB_PREFIX . 'metas m JOIN ' . DB_PREFIX . 'products p ON p.id = m.owner_id'
            . " WHERE m.owner_type = 'product' AND m.meta_key = 'import_key' AND m.meta_value = ? AND p.lang = ? AND p.deleted_at IS NULL ORDER BY p.id LIMIT 1",
            [$key, $this->lang]
        );
        return self::product($row);
    }

    public function productBySlug(string $slug): ?array
    {
        return self::product(db()->fetchOne(
            'SELECT id, title, slug FROM ' . DB_PREFIX . 'products WHERE slug = ? AND lang = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$slug, $this->lang]
        ));
    }

    public function productByModelTitle(string $model, string $title): ?array
    {
        return self::product(db()->fetchOne(
            'SELECT id, title, slug FROM ' . DB_PREFIX . 'products WHERE model = ? AND title = ? AND lang = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$model, $title, $this->lang]
        ));
    }

    public function sitePath(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!str_starts_with($path, '/uploads/') || str_contains($path, '..')) {
            return null;
        }
        $file = $this->siteRoot . rawurldecode($path);
        return is_file($file) ? $file : null;
    }

    /** @param array<string,mixed>|null|false $row @return array{id:int,title:string,slug:string}|null */
    private static function product(mixed $row): ?array
    {
        return is_array($row) ? ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug']] : null;
    }
}
