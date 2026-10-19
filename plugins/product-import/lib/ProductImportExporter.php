<?php
/**
 * 从本站导出导入包（本地整理好 → 线上用同一个向导导入）。
 *
 * 选中的分类连同子分类里的产品；分类带上上级链，键用本站别名。图片按 MD5 去重放进 images/，
 * 主图、产品图、参数图（metas）和详情里的 /uploads/ 图都改成包内路径；详情末尾的参数图区块去掉（导入时按参数图重建）。
 * 本站文件已丢的图保留原地址并记入 missing，线上导入预览会把它报成「找不到图片」。
 */

declare(strict_types=1);

final class ProductImportExporter
{
    /** @var array<string,string> 本站网址 => 包内路径 */
    private array $refs = [];
    /** @var array<string,string> md5 => 包内路径 */
    private array $byMd5 = [];
    /** @var list<string> */
    private array $missing = [];

    public function __construct(private string $siteRoot)
    {
    }

    /**
     * 本语言的分类（带产品数），给导出界面选。
     * @return list<array{id:int,parent_id:int,name:string,slug:string,products:int}>
     */
    public static function categories(string $lang): array
    {
        $p = DB_PREFIX;
        $rows = db()->fetchAll(
            "SELECT c.id, c.parent_id, c.name, c.slug, (SELECT COUNT(*) FROM {$p}products x WHERE x.category_id = c.id AND x.deleted_at IS NULL) AS products"
            . " FROM {$p}product_categories c WHERE c.lang = ? ORDER BY c.parent_id, c.sort_order, c.id",
            [$lang]
        );
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'parent_id' => (int) $r['parent_id'],
            'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'products' => (int) $r['products']], $rows);
    }

    /**
     * @param list<int> $categoryIds 空 = 本语言全部产品
     * @return array{products:int,categories:int,images:int,missing:list<string>}
     */
    public function export(array $categoryIds, string $lang, string $zipPath): array
    {
        $p = DB_PREFIX;
        $all = db()->fetchAll("SELECT * FROM {$p}product_categories WHERE lang = ? ORDER BY sort_order, id", [$lang]);
        $byId = [];
        $children = [];
        foreach ($all as $row) {
            $byId[(int) $row['id']] = $row;
            $children[(int) $row['parent_id']][] = (int) $row['id'];
        }
        // 选中的分类 + 全部子孙；导出的分类再补上上级链
        $wanted = [];
        $stack = array_values(array_filter(array_map('intval', $categoryIds), static fn (int $id): bool => isset($byId[$id])));
        while ($stack !== []) {
            $id = array_pop($stack);
            if (!isset($wanted[$id])) {
                $wanted[$id] = true;
                array_push($stack, ...($children[$id] ?? []));
            }
        }
        $exported = $categoryIds === [] ? array_fill_keys(array_keys($byId), true) : $wanted;
        foreach (array_keys($exported) as $id) {
            for ($parent = (int) $byId[$id]['parent_id']; $parent > 0 && isset($byId[$parent]); $parent = (int) $byId[$parent]['parent_id']) {
                $exported[$parent] = true;
            }
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('export_failed');
        }
        $categories = [];
        foreach ($all as $row) {
            if (isset($exported[(int) $row['id']])) {
                $parent = $byId[(int) $row['parent_id']] ?? null;
                $categories[] = ['key' => (string) $row['slug'], 'name' => (string) $row['name'], 'parent' => $parent !== null ? (string) $parent['slug'] : '',
                    'image' => $this->ref($zip, (string) $row['image']), 'sort_order' => (int) $row['sort_order']];
            }
        }

        $where = 'lang = ? AND deleted_at IS NULL';
        $params = [$lang];
        if ($categoryIds !== []) {
            $ids = array_keys($wanted);
            if ($ids === []) {
                $ids = [0];
            }
            $where .= ' AND category_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $products = [];
        foreach (db()->fetchAll("SELECT * FROM {$p}products WHERE {$where} ORDER BY sort_order, id", $params) as $row) {
            $id = (int) $row['id'];
            $images = json_decode((string) $row['images'], true);
            $paramImages = json_decode((string) metaModel()->get('product', $id, 'param_images', '[]'), true);
            $content = (string) preg_replace('~\s*<div class="' . ProductImportRunner::PARAM_BLOCK_CLASS . '">.*?</div>~s', '', (string) $row['content']);
            [$contentImages] = ProductImportPackage::contentImages($content);
            $contentRefs = [];
            foreach ($contentImages as $src) {
                if (str_starts_with($src, '/uploads/')) {
                    $contentRefs[$src] = $this->ref($zip, $src);
                }
            }
            $category = $byId[(int) $row['category_id']] ?? null;
            $products[] = [
                'key' => (string) metaModel()->get('product', $id, 'import_key', ''),
                'source_url' => (string) metaModel()->get('product', $id, 'source_url', ''),
                'slug' => (string) $row['slug'],
                'category' => $category !== null ? (string) $category['slug'] : '',
                'title' => (string) $row['title'],
                'model' => (string) $row['model'],
                'cover' => $this->ref($zip, (string) $row['cover']),
                'images' => array_values(array_map(fn ($url): string => $this->ref($zip, (string) $url), is_array($images) ? array_filter($images, 'is_string') : [])),
                'param_images' => array_values(array_map(fn ($url): string => $this->ref($zip, (string) $url), is_array($paramImages) ? array_filter($paramImages, 'is_string') : [])),
                'summary' => (string) $row['summary'],
                'content' => ProductImportRunner::rewriteImages($content, $contentRefs),
                'specs' => (object) ProductImportPackage::normalizeSpecs(json_decode((string) $row['specs'], true)),
                'status' => (int) $row['status'],
                'sort_order' => (int) $row['sort_order'],
            ];
        }
        $zip->addFromString('products.json', (string) json_encode([
            'format' => ProductImportPackage::FORMAT, 'version' => 1, 'lang' => $lang,
            'exported_at' => date('c'), 'categories' => $categories, 'products' => $products,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        if (!$zip->close()) {
            throw new RuntimeException('export_failed');
        }
        return ['products' => count($products), 'categories' => count($categories), 'images' => count($this->byMd5), 'missing' => $this->missing];
    }

    /** 本站图片 → 包内路径（同一文件只放一次）；不是本站上传的图原样返回。 */
    private function ref(ZipArchive $zip, string $url): string
    {
        $url = trim($url);
        if ($url === '' || !str_starts_with($url, '/uploads/')) {
            return $url;
        }
        if (isset($this->refs[$url])) {
            return $this->refs[$url];
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $file = $this->siteRoot . rawurldecode($path);
        if (preg_match('~(^|/)\.\.(/|$)~', $path) === 1 || !is_file($file)) {
            $this->missing[] = $url;
            return $this->refs[$url] = $url;
        }
        $md5 = (string) md5_file($file);
        if (!isset($this->byMd5[$md5])) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $this->byMd5[$md5] = 'images/' . $md5 . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
            $zip->addFile($file, $this->byMd5[$md5]);
        }
        return $this->refs[$url] = $this->byMd5[$md5];
    }
}
