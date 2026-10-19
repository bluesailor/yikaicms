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
        return self::match($row);
    }

    public function productBySlug(string $slug): ?array
    {
        return self::match(db()->fetchOne(
            'SELECT id, title, slug FROM ' . DB_PREFIX . 'products WHERE slug = ? AND lang = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$slug, $this->lang]
        ));
    }

    public function productByModelTitle(string $model, string $title): ?array
    {
        return self::match(db()->fetchOne(
            'SELECT id, title, slug FROM ' . DB_PREFIX . 'products WHERE model = ? AND title = ? AND lang = ? AND deleted_at IS NULL ORDER BY id LIMIT 1',
            [$model, $title, $this->lang]
        ));
    }

    public function sitePath(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!str_starts_with($path, '/uploads/') || preg_match('~(^|/)\.\.(/|$)~', $path) === 1) {
            return null;
        }
        $file = $this->siteRoot . rawurldecode($path);
        return is_file($file) ? $file : null;
    }

    public function imageMd5(string $url): ?string
    {
        $file = $this->sitePath($url);
        if ($file === null) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $source = db()->fetchColumn(
            'SELECT m.meta_value FROM ' . DB_PREFIX . 'media md JOIN ' . DB_PREFIX . 'metas m ON m.owner_id = md.id'
            . " WHERE md.url = ? AND m.owner_type = 'media' AND m.meta_key = 'source_md5' LIMIT 1",
            [$path]
        );
        return is_string($source) && $source !== '' ? $source : (md5_file($file) ?: null);
    }

    public function product(int $id): ?array
    {
        $row = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'products WHERE id = ?', [$id]);
        if (!is_array($row)) {
            return null;
        }
        $urls = static function (mixed $json): array {
            $list = is_string($json) ? json_decode($json, true) : null;
            return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
        };
        return [
            'title' => (string) $row['title'], 'model' => (string) $row['model'], 'summary' => (string) $row['summary'],
            'content' => (string) $row['content'], 'specs' => (string) $row['specs'], 'cover' => (string) $row['cover'],
            'images' => $urls($row['images']), 'param_images' => $urls(metaModel()->get('product', $id, 'param_images')),
            'category_id' => (int) $row['category_id'], 'updated_at' => (int) $row['updated_at'],
            'synced_at' => (int) metaModel()->get('product', $id, 'import_synced_at', 0),
        ];
    }

    /** @param array<string,mixed>|null|false $row @return array{id:int,title:string,slug:string}|null */
    private static function match(mixed $row): ?array
    {
        return is_array($row) ? ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'slug' => (string) $row['slug']] : null;
    }
}
