<?php
/**
 * 产品导入计划（2.0）：把导入包和本站现状对上——分类新建还是沿用、每款产品新增还是更新，
 * 以及导入前的图片校验。只读不写库，结果给预览界面，也是执行器的输入。
 *
 * 错误（这一行不导入）：没有名称、分类找不到、没有主图、图片找不到、主图是别的分类 / 上级分类的图。
 * 警告（照常导入、预览标黄）：同一张图被多个产品当主图、图片短边过小、包里用了参数图而这一款没有。
 * 「主图是分类图」按文件 MD5 比，本小类自己的图除外（小类图常取自它的产品）——
 * 这条就是 FHZN Washer 三款产品显示大类展会拼图的那种错。
 */

declare(strict_types=1);

interface ProductImportSite
{
    /** @return list<array{id:int,slug:string,name:string,parent_id:int,image:string}> 本语言的产品分类 */
    public function categories(): array;

    /** @return array{id:int,title:string,slug:string}|null */
    public function productByImportKey(string $key): ?array;

    /** @return array{id:int,title:string,slug:string}|null */
    public function productBySlug(string $slug): ?array;

    /** @return array{id:int,title:string,slug:string}|null */
    public function productByModelTitle(string $model, string $title): ?array;

    /** 本站 /uploads/… 文件的绝对路径；不存在返回 null。 */
    public function sitePath(string $url): ?string;

    /** 本站图片的 MD5；导入时压过尺寸的图返回原文件的 MD5（与导入包里的同一张图对得上）。 */
    public function imageMd5(string $url): ?string;

    /**
     * 与导入包比较用的产品现值。images / param_images 是网址列表，specs 是 JSON 原文。
     * @return array{title:string,model:string,summary:string,content:string,specs:string,cover:string,images:list<string>,
     *   param_images:list<string>,category_id:int,updated_at:int,synced_at:int}|null
     */
    public function product(int $id): ?array;
}

final class ProductImportPlanner
{
    public const MIN_IMAGE_SIDE = 300;

    /** @var array<string,string|null> 绝对路径 => md5 */
    private array $md5 = [];

    public function __construct(private ProductImportPackage $package, private ProductImportSite $site)
    {
    }

    /**
     * @return array{
     *   categories:list<array<string,mixed>>,
     *   products:list<array<string,mixed>>,
     *   summary:array<string,int>,
     *   errors:list<array{code:string,params:array<string,string>}>
     * }
     */
    public function plan(): array
    {
        $siteCategories = $this->site->categories();
        $siteBySlug = [];
        foreach ($siteCategories as $category) {
            $siteBySlug[$category['slug']] = $category;
        }

        // ── 分类 ──
        $categories = [];
        $byKey = [];
        $siteSlugForKey = [];
        foreach ($this->package->categories as $index => $category) {
            $issues = [];
            if ($category['key'] === '' || $category['name'] === '') {
                $issues[] = self::issue('category_incomplete');
            } elseif (isset($byKey[$category['key']])) {
                $issues[] = self::issue('category_duplicate_key', ['key' => $category['key']]);
            }
            $existing = $siteBySlug[$category['key']] ?? $this->findByName($siteCategories, $category['name'], $category['parent'], $siteBySlug);
            $row = $category + [
                'index' => $index,
                'action' => $existing !== null ? 'existing' : 'create',
                'site_id' => $existing['id'] ?? 0,
                'errors' => $issues,
                'warnings' => [],
            ];
            if ($existing !== null && $existing['name'] !== $category['name']) {
                $row['warnings'][] = self::issue('category_name_differs', ['site' => $existing['name']]);
            }
            if ($issues === []) {
                $byKey[$category['key']] = count($categories);
                if ($existing !== null) {
                    $siteSlugForKey[$category['key']] = $existing['slug'];
                }
            }
            $categories[] = $row;
        }
        foreach ($categories as $i => $category) {
            $parent = (string) $category['parent'];
            if ($parent !== '' && !isset($byKey[$parent]) && !isset($siteBySlug[$parent])) {
                $categories[$i]['errors'][] = self::issue('category_parent_missing', ['parent' => $parent]);
            }
        }

        // 分类图 MD5 → 分类键（包里的 + 本站的）；本站分类以 slug 为键，与包里的键同一命名空间
        $categoryImages = [];
        foreach ($categories as $category) {
            $md5 = $this->md5Of((string) $category['image']);
            if ($md5 !== null) {
                $categoryImages[$md5][] = (string) $category['key'];
            }
        }
        foreach ($siteCategories as $category) {
            $md5 = $category['image'] !== '' ? $this->site->imageMd5($category['image']) : null;
            if ($md5 !== null) {
                $categoryImages[$md5][] = $category['slug'];
            }
        }
        $categoryImages = array_map('array_unique', $categoryImages);

        // ── 产品 ──
        $usesParamImages = false;
        $coverCount = [];
        foreach ($this->package->products as $product) {
            $usesParamImages = $usesParamImages || $product['param_images'] !== [];
            $md5 = $this->md5Of((string) $product['cover']);
            if ($md5 !== null) {
                $coverCount[$md5] = ($coverCount[$md5] ?? 0) + 1;
            }
        }
        $products = [];
        $seenKeys = [];
        $seenSlugs = [];
        foreach ($this->package->products as $index => $product) {
            $errors = [];
            $warnings = [];
            if ($product['title'] === '') {
                $errors[] = self::issue('title_missing');
            }
            $categoryKey = (string) $product['category'];
            if ($categoryKey !== '' && !isset($byKey[$categoryKey]) && !isset($siteBySlug[$categoryKey])) {
                $errors[] = self::issue('category_unknown', ['category' => $categoryKey]);
            }
            foreach (['key' => $seenKeys, 'slug' => $seenSlugs] as $field => $seen) {
                $value = (string) $product[$field];
                if ($value !== '' && isset($seen[$value])) {
                    $errors[] = self::issue('duplicate_in_package', ['field' => $field, 'value' => $value, 'row' => (string) ($seen[$value] + 1)]);
                }
            }
            if ($product['key'] !== '') {
                $seenKeys[$product['key']] ??= $index;
            }
            if ($product['slug'] !== '') {
                $seenSlugs[$product['slug']] ??= $index;
            }

            // 图片
            $images = ['cover' => [], 'images' => [], 'param_images' => [], 'content' => []];
            if ($product['cover'] === '') {
                $errors[] = self::issue('cover_missing');
            }
            $roles = ['cover' => [$product['cover']], 'images' => $product['images'], 'param_images' => $product['param_images'], 'content' => $product['content_images']];
            foreach ($roles as $role => $refs) {
                foreach ($refs as $ref) {
                    if ($ref === '') {
                        continue;
                    }
                    $resolved = $this->package->resolveImage((string) $ref);
                    if ($resolved['kind'] === 'missing' || $resolved['kind'] === 'invalid') {
                        $errors[] = self::issue($resolved['kind'] === 'invalid' ? 'image_not_allowed' : 'image_not_found', ['image' => (string) $ref]);
                        continue;
                    }
                    $images[$role][] = $resolved + ['md5' => (string) $this->fileMd5($resolved['path'])];
                }
            }
            $cover = $images['cover'][0] ?? null;
            if ($cover !== null) {
                // 本分类自己的图不算（包里的键与按名称对上的本站分类 slug 都算「自己」）
                $own = array_filter([$categoryKey, $siteSlugForKey[$categoryKey] ?? '']);
                $others = array_values(array_diff($categoryImages[$cover['md5']] ?? [], $own));
                if ($others !== []) {
                    $errors[] = self::issue('cover_is_category_image', ['categories' => implode(', ', $others)]);
                }
                if (($coverCount[$cover['md5']] ?? 0) > 1) {
                    $warnings[] = self::issue('cover_shared', ['count' => (string) $coverCount[$cover['md5']]]);
                }
                $size = @getimagesize($cover['path']);
                if (is_array($size) && min((int) $size[0], (int) $size[1]) < self::MIN_IMAGE_SIDE) {
                    $warnings[] = self::issue('image_small', ['size' => $size[0] . '×' . $size[1]]);
                }
            }
            if ($usesParamImages && $product['param_images'] === []) {
                $warnings[] = self::issue('param_image_missing');
            }
            if ($product['content_external'] > 0) {
                $warnings[] = self::issue('content_external_image', ['count' => (string) $product['content_external']]);
            }

            // 重复：导入键 → 别名 → 型号 + 名称
            $match = null;
            $matchedBy = '';
            if ($product['key'] !== '' && ($match = $this->site->productByImportKey($product['key'])) !== null) {
                $matchedBy = 'key';
            } elseif ($product['slug'] !== '' && ($match = $this->site->productBySlug($product['slug'])) !== null) {
                $matchedBy = 'slug';
            } elseif ($product['model'] !== '' && $product['title'] !== ''
                && ($match = $this->site->productByModelTitle($product['model'], $product['title'])) !== null) {
                $matchedBy = 'model_title';
            }

            // 与本站比较：哪些字段真的变了；全没变的更新行，预览里默认跳过
            $changes = null;
            if ($match !== null && $errors === []) {
                $current = $this->site->product((int) $match['id']);
                if ($current !== null) {
                    $changes = $this->changes($product, $images, $current, $siteSlugForKey[$categoryKey] ?? $categoryKey, $siteCategories);
                    if ((int) $current['synced_at'] > 0 && (int) $current['updated_at'] > (int) $current['synced_at']) {
                        $warnings[] = self::issue('edited_on_site');
                    }
                }
            }

            $products[] = [
                'index' => $index,
                'key' => $product['key'],
                'title' => $product['title'],
                'category' => $categoryKey,
                'action' => $errors !== [] ? 'error' : ($match !== null ? 'update' : 'create'),
                'match' => $match === null ? null : $match + ['by' => $matchedBy],
                'changes' => $changes,
                'images' => $images,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $summary = ['categories' => count($categories), 'categories_new' => 0, 'products' => count($products),
            'create' => 0, 'update' => 0, 'unchanged' => 0, 'error' => 0, 'warnings' => 0];
        foreach ($categories as $category) {
            $summary['categories_new'] += $category['action'] === 'create' ? 1 : 0;
            $summary['error'] += $category['errors'] !== [] ? 1 : 0;
        }
        foreach ($products as $product) {
            $summary[$product['action']]++;
            $summary['unchanged'] += $product['changes'] === [] ? 1 : 0;
            $summary['warnings'] += $product['warnings'] !== [] ? 1 : 0;
        }
        return ['categories' => $categories, 'products' => $products, 'summary' => $summary, 'errors' => $this->package->errors];
    }

    /**
     * @param list<array{id:int,slug:string,name:string,parent_id:int,image:string}> $site
     * @param array<string,array<string,mixed>> $siteBySlug
     * @return array{id:int,slug:string,name:string,parent_id:int,image:string}|null
     */
    private function findByName(array $site, string $name, string $parentKey, array $siteBySlug): ?array
    {
        $normalize = static fn (string $value): string => strtolower((string) preg_replace('/\s+/u', ' ', trim($value)));
        $parentId = $parentKey === '' ? 0 : (int) ($siteBySlug[$parentKey]['id'] ?? -1);
        foreach ($site as $category) {
            if ($normalize($category['name']) === $normalize($name) && ($parentKey === '' ? $category['parent_id'] === 0 : $category['parent_id'] === $parentId)) {
                return $category;
            }
        }
        return null;
    }

    /**
     * 包里给了、且与本站现值不同的字段。图片按文件 MD5 比，详情里的图也是；详情末尾的参数图区块不算详情。
     * @param array<string,mixed> $product @param array<string,list<array<string,string>>> $images
     * @param array<string,mixed> $current @param list<array{id:int,slug:string,name:string,parent_id:int,image:string}> $siteCategories
     * @return list<string>
     */
    private function changes(array $product, array $images, array $current, string $categorySlug, array $siteCategories): array
    {
        $changed = [];
        if ($product['title'] !== trim((string) $current['title'])) {
            $changed[] = 'title';
        }
        foreach (['model', 'summary'] as $field) {
            if ($product[$field] !== '' && $product[$field] !== trim((string) $current[$field])) {
                $changed[] = $field;
            }
        }
        if ($product['category'] !== '') {
            $slug = '';
            foreach ($siteCategories as $category) {
                $slug = $category['id'] === (int) $current['category_id'] ? $category['slug'] : $slug;
            }
            if ($slug !== $categorySlug) {
                $changed[] = 'category';
            }
        }
        if ($product['specs'] !== [] && $product['specs'] !== ProductImportPackage::normalizeSpecs(json_decode((string) $current['specs'], true))) {
            $changed[] = 'specs';
        }
        $site = fn (array $urls): array => array_values(array_unique(array_map(fn (string $url): string => (string) $this->site->imageMd5($url), $urls)));
        $mine = static fn (array $list): array => array_values(array_unique(array_column($list, 'md5')));
        if ($images['cover'] !== [] && $images['cover'][0]['md5'] !== (string) $this->site->imageMd5((string) $current['cover'])) {
            $changed[] = 'cover';
        }
        foreach (['images', 'param_images'] as $role) {
            if ($product[$role] !== [] && $mine($images[$role]) !== $site($current[$role])) {
                $changed[] = $role;
            }
        }
        if ($product['content'] !== '') {
            $siteContent = (string) preg_replace('~\s*<div class="yk-product-param-images">.*?</div>~s', '', (string) $current['content']);
            $packageMd5 = function (string $src): ?string {
                $resolved = $this->package->resolveImage($src);
                return $resolved['path'] !== '' ? $this->fileMd5($resolved['path']) : null;
            };
            if (self::comparableContent($product['content'], $packageMd5) !== self::comparableContent($siteContent, fn (string $src): ?string => $this->site->imageMd5($src))) {
                $changed[] = 'content';
            }
        }
        return $changed;
    }

    /** 图片地址换成文件 MD5、空白归一，两边写法不同但内容相同就算没变。 */
    private static function comparableContent(string $html, callable $md5): string
    {
        $html = (string) preg_replace_callback('~(<img\b[^>]*?\ssrc\s*=\s*)(["\'])(.*?)\2~is', static function (array $m) use ($md5): string {
            $src = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5));
            return $m[1] . '"' . ($md5($src) ?? $src) . '"';
        }, $html);
        return trim((string) preg_replace('/\s+/u', ' ', $html));
    }

    private function md5Of(string $ref): ?string
    {
        if ($ref === '') {
            return null;
        }
        $resolved = $this->package->resolveImage($ref);
        return $resolved['path'] !== '' ? $this->fileMd5($resolved['path']) : null;
    }

    private function fileMd5(string $path): ?string
    {
        if (!array_key_exists($path, $this->md5)) {
            $hash = is_file($path) ? md5_file($path) : false;
            $this->md5[$path] = $hash === false ? null : $hash;
        }
        return $this->md5[$path];
    }

    /** @param array<string,string> $params @return array{code:string,params:array<string,string>} */
    private static function issue(string $code, array $params = []): array
    {
        return ['code' => $code, 'params' => $params];
    }
}
