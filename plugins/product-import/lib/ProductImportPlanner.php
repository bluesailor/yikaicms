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
            $path = $category['image'] !== '' ? $this->site->sitePath($category['image']) : null;
            $md5 = $path !== null ? $this->fileMd5($path) : null;
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
            $images = ['cover' => [], 'images' => [], 'param_images' => []];
            if ($product['cover'] === '') {
                $errors[] = self::issue('cover_missing');
            }
            foreach (['cover' => [$product['cover']], 'images' => $product['images'], 'param_images' => $product['param_images']] as $role => $refs) {
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

            $products[] = [
                'index' => $index,
                'key' => $product['key'],
                'title' => $product['title'],
                'category' => $categoryKey,
                'action' => $errors !== [] ? 'error' : ($match !== null ? 'update' : 'create'),
                'match' => $match === null ? null : $match + ['by' => $matchedBy],
                'images' => $images,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $summary = ['categories' => count($categories), 'categories_new' => 0, 'products' => count($products),
            'create' => 0, 'update' => 0, 'error' => 0, 'warnings' => 0];
        foreach ($categories as $category) {
            $summary['categories_new'] += $category['action'] === 'create' ? 1 : 0;
            $summary['error'] += $category['errors'] !== [] ? 1 : 0;
        }
        foreach ($products as $product) {
            $summary[$product['action']]++;
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
