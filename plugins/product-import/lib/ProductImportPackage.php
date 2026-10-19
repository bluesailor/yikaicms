<?php
/**
 * 产品导入包（2.0，格式 yikai-product-package v1）：读 products.json，或表格（xlsx / csv）+ images/，
 * 归一成「分类」「产品」两张清单。只读不写库；问题记成代码，界面按语言翻译。
 * 设计见 yikaicms-docs/planning/current/product-import-v2-design.md。
 *
 * 图片引用：包内相对路径（不许 ..、绝对路径）或本站已有的 /uploads/…；外部网址不下载——
 * 抓取与验证码留在采集工具里，CMS 只收整理好的包。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/XlsxReader.php';

final class ProductImportPackage
{
    public const FORMAT = 'yikai-product-package';
    public const MAX_PRODUCTS = 5000;
    public const MAX_CATEGORIES = 500;
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    /** 表格列名（中英文都认，大小写、空格不敏感）→ 字段 */
    private const COLUMNS = [
        'category' => ['大类', '分类', '所属分类', '大分類', 'category'],
        'subcategory' => ['小类', '子分类', '小分類', 'subcategory'],
        'title' => ['名称', '产品名称', '商品名', 'title', 'name'],
        'model' => ['型号', '产品型号', '型番', 'model'],
        'cover' => ['主图', '封面', '封面图片', 'メイン画像', 'cover', 'mainimage'],
        'images' => ['产品图', '产品图集', '商品画像', 'images', 'gallery'],
        'param_images' => ['参数图', '仕様画像', 'paramimages', 'parameterimages', 'specimages'],
        'summary' => ['摘要', '概要', 'summary'],
        'content' => ['详情', '详情内容', '詳細', 'content', 'description'],
        'specs' => ['规格', '规格参数', '仕様', 'specs'],
        'key' => ['来源编号', '取得元id', 'sourceid', 'key', 'id'],
        'source_url' => ['来源链接', '取得元url', 'sourceurl', 'url'],
        'slug' => ['别名', 'url别名', 'スラッグ', 'slug'],
        'status' => ['状态', '状態', 'status'],
        'sort_order' => ['排序', '並び順', 'sort', 'sortorder'],
    ];

    public string $lang = '';
    /** @var list<array{key:string,name:string,parent:string,image:string,sort_order:int}> */
    public array $categories = [];
    /** @var list<array<string,mixed>> */
    public array $products = [];
    /** @var list<array{code:string,params:array<string,string>}> 读包时的问题（没有清单、格式不对、缺列…） */
    public array $errors = [];

    private string $dir;
    private string $siteRoot;

    private function __construct(string $dir, string $siteRoot)
    {
        $this->dir = $dir;
        $this->siteRoot = $siteRoot;
    }

    public static function fromDirectory(string $dir, string $siteRoot): self
    {
        $real = realpath($dir);
        $package = new self($real === false ? '' : str_replace('\\', '/', $real), rtrim(str_replace('\\', '/', $siteRoot), '/'));
        if ($package->dir === '' || !is_dir($package->dir)) {
            $package->error('package_missing');
            return $package;
        }
        if (is_file($package->dir . '/products.json')) {
            $package->readJson((string) file_get_contents($package->dir . '/products.json'));
        } elseif (($table = $package->firstTable()) !== null) {
            $package->readTable($table);
        } else {
            $package->error('package_no_manifest');
        }
        return $package;
    }

    /**
     * 图片引用 → 实际文件。kind=package 包内文件；site 本站 /uploads/ 下已有文件；missing 找不到；invalid 不允许的引用。
     *
     * @return array{kind:string,path:string,ref:string}
     */
    public function resolveImage(string $ref): array
    {
        $ref = trim(str_replace('\\', '/', $ref));
        if ($ref === '') {
            return ['kind' => 'missing', 'path' => '', 'ref' => $ref];
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $ref) === 1 || str_starts_with($ref, '//')) {
            return ['kind' => 'invalid', 'path' => '', 'ref' => $ref];
        }
        if (str_starts_with($ref, '/uploads/')) {
            $path = (string) parse_url($ref, PHP_URL_PATH);
            if (str_contains($path, '..')) {
                return ['kind' => 'invalid', 'path' => '', 'ref' => $ref];
            }
            $file = $this->siteRoot . $path;
            return is_file($file) && $this->isImage($file) ? ['kind' => 'site', 'path' => $file, 'ref' => $path] : ['kind' => 'missing', 'path' => '', 'ref' => $ref];
        }
        if (str_starts_with($ref, '/') || preg_match('#(^|/)\.\.(/|$)#', $ref) === 1 || str_contains($ref, ':')) {
            return ['kind' => 'invalid', 'path' => '', 'ref' => $ref];
        }
        foreach ([$ref, 'images/' . $ref] as $candidate) {
            $file = $this->dir . '/' . ltrim($candidate, '/');
            $real = realpath($file);
            if ($real !== false && is_file($real) && str_starts_with(str_replace('\\', '/', $real), $this->dir . '/') && $this->isImage($real)) {
                return ['kind' => 'package', 'path' => str_replace('\\', '/', $real), 'ref' => $candidate];
            }
        }
        return ['kind' => 'missing', 'path' => '', 'ref' => $ref];
    }

    // ── 读 products.json ─────────────────────────────────────────────

    private function readJson(string $json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->error('package_bad_json');
            return;
        }
        if (($data['format'] ?? '') !== self::FORMAT || (int) ($data['version'] ?? 0) !== 1) {
            $this->error('package_bad_format', ['format' => (string) ($data['format'] ?? '')]);
            return;
        }
        $this->lang = is_string($data['lang'] ?? null) ? trim($data['lang']) : '';
        foreach (array_slice(is_array($data['categories'] ?? null) ? $data['categories'] : [], 0, self::MAX_CATEGORIES) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $this->categories[] = [
                'key' => self::str($row['key'] ?? ''),
                'name' => self::str($row['name'] ?? ''),
                'parent' => self::str($row['parent'] ?? ''),
                'image' => self::str($row['image'] ?? ''),
                'sort_order' => (int) ($row['sort_order'] ?? 0),
            ];
        }
        $products = is_array($data['products'] ?? null) ? $data['products'] : [];
        if (count($products) > self::MAX_PRODUCTS) {
            $this->error('package_too_many', ['max' => (string) self::MAX_PRODUCTS]);
            $products = array_slice($products, 0, self::MAX_PRODUCTS);
        }
        foreach ($products as $row) {
            if (is_array($row)) {
                $this->products[] = self::product($row);
            }
        }
    }

    // ── 读表格 ───────────────────────────────────────────────────────

    private function firstTable(): ?string
    {
        foreach (['products.xlsx', 'products.csv'] as $name) {
            if (is_file($this->dir . '/' . $name)) {
                return $this->dir . '/' . $name;
            }
        }
        foreach (glob($this->dir . '/*.{xlsx,csv}', GLOB_BRACE) ?: [] as $file) {
            return str_replace('\\', '/', $file);
        }
        return null;
    }

    private function readTable(string $file): void
    {
        $table = str_ends_with(strtolower($file), '.xlsx') ? ProductImportXlsxReader::read($file) : self::readCsv($file);
        foreach ($table['errors'] as $message) {
            $this->error('table_unreadable', ['detail' => $message]);
        }
        $columns = [];
        foreach ($table['headers'] as $index => $header) {
            $normalized = strtolower((string) preg_replace('/[\s_\-()（）]+/u', '', (string) $header));
            foreach (self::COLUMNS as $field => $aliases) {
                if (!isset($columns[$field]) && in_array($normalized, array_map('strtolower', $aliases), true)) {
                    $columns[$field] = $index;
                }
            }
        }
        if (!isset($columns['title'])) {
            $this->error('table_missing_title');
            return;
        }
        $categoryKeys = [];
        foreach (array_slice($table['rows'], 0, self::MAX_PRODUCTS) as $cells) {
            $cell = static fn (string $field): string => isset($columns[$field]) ? trim((string) ($cells[$columns[$field]] ?? '')) : '';
            $parentName = $cell('category');
            $childName = $cell('subcategory');
            $categoryKey = '';
            if ($parentName !== '') {
                $parentKey = self::slugKey($parentName);
                $categoryKeys[$parentKey] ??= ['key' => $parentKey, 'name' => $parentName, 'parent' => '', 'image' => '', 'sort_order' => count($categoryKeys)];
                $categoryKey = $parentKey;
                if ($childName !== '') {
                    $childKey = $parentKey . '-' . self::slugKey($childName);
                    $categoryKeys[$childKey] ??= ['key' => $childKey, 'name' => $childName, 'parent' => $parentKey, 'image' => '', 'sort_order' => count($categoryKeys)];
                    $categoryKey = $childKey;
                }
            }
            $this->products[] = self::product([
                'key' => $cell('key'), 'source_url' => $cell('source_url'), 'slug' => $cell('slug'),
                'category' => $categoryKey, 'title' => $cell('title'), 'model' => $cell('model'),
                'cover' => $cell('cover'), 'images' => $cell('images'), 'param_images' => $cell('param_images'),
                'summary' => $cell('summary'), 'content' => $cell('content'), 'specs' => $cell('specs'),
                'status' => $cell('status') === '' ? 1 : (int) $cell('status'), 'sort_order' => (int) $cell('sort_order'),
            ]);
        }
        if (count($table['rows']) > self::MAX_PRODUCTS) {
            $this->error('package_too_many', ['max' => (string) self::MAX_PRODUCTS]);
        }
        $this->categories = array_values($categoryKeys);
    }

    /** @return array{headers:list<string>,rows:list<list<string>>,errors:list<string>} */
    private static function readCsv(string $file): array
    {
        $handle = fopen($file, 'r');
        if ($handle === false) {
            return ['headers' => [], 'rows' => [], 'errors' => ['csv']];
        }
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }
        $headers = [];
        $rows = [];
        while (($cells = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
            $cells = array_map(static fn ($c): string => (string) ($c ?? ''), $cells);
            if ($headers === []) {
                $headers = array_map('trim', $cells);
                continue;
            }
            if (implode('', array_map('trim', $cells)) !== '') {
                $rows[] = $cells;
            }
        }
        fclose($handle);
        return ['headers' => $headers, 'rows' => $rows, 'errors' => []];
    }

    // ── 归一 ─────────────────────────────────────────────────────────

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function product(array $row): array
    {
        return [
            'key' => mb_substr(self::str($row['key'] ?? ''), 0, 120),
            'source_url' => mb_substr(self::str($row['source_url'] ?? ''), 0, 500),
            'slug' => mb_substr(self::str($row['slug'] ?? ''), 0, 200),
            'category' => self::str($row['category'] ?? ''),
            'title' => mb_substr(self::str($row['title'] ?? ''), 0, 255),
            'model' => mb_substr(self::str($row['model'] ?? ''), 0, 100),
            'cover' => self::str($row['cover'] ?? ''),
            'images' => self::list($row['images'] ?? []),
            'param_images' => self::list($row['param_images'] ?? []),
            'summary' => self::str($row['summary'] ?? ''),
            'content' => is_string($row['content'] ?? null) ? $row['content'] : '',
            'specs' => self::specs($row['specs'] ?? []),
            'status' => isset($row['status']) ? (((int) $row['status']) === 0 ? 0 : 1) : 1,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }

    /** @return list<string> 列表或 | , 分隔的字符串 */
    private static function list(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = str_starts_with(trim($value), '[') ? json_decode($value, true) : null;
            $value = is_array($decoded) ? $decoded : preg_split('/[|\n]+/', $value);
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $item = self::str($item);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /** @return array<string,string> 对象，或每行「名称:值」 */
    private static function specs(mixed $value): array
    {
        if (is_string($value)) {
            $pairs = [];
            foreach (preg_split('/\r\n|\r|\n/', $value) ?: [] as $line) {
                $parts = explode(':', str_replace('：', ':', $line), 2);
                if (count($parts) === 2 && trim($parts[0]) !== '') {
                    $pairs[trim($parts[0])] = trim($parts[1]);
                }
            }
            return $pairs;
        }
        $out = [];
        foreach (is_array($value) ? $value : [] as $name => $item) {
            if (is_string($name) && trim($name) !== '' && is_scalar($item)) {
                $out[trim($name)] = trim((string) $item);
            }
        }
        return $out;
    }

    private static function slugKey(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');
        return $slug !== '' ? $slug : 'c-' . substr(md5($name), 0, 8);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function isImage(string $file): bool
    {
        return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true);
    }

    /** @param array<string,string> $params */
    private function error(string $code, array $params = []): void
    {
        $this->errors[] = ['code' => $code, 'params' => $params];
    }
}
