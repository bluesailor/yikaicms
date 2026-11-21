<?php
/**
 * 产品导入执行器（2.0）：按计划写库，分批跑，每步都记进任务，可整批撤销。
 *
 * - 图片进媒体库：同一文件（MD5）已在库里就复用，新图放 uploads/images/<年月>/products/。
 *   压过尺寸的图另记原文件 MD5（metas media/source_md5），重导同一个包不会再复制一份。
 * - 分类：包里的分类本站没有才新建；已有的不改。
 * - 产品：新增写全部字段；更新只改包里给了的字段，别名、状态、排序保持本站的。
 * - 参数图：metas product/param_images + 详情末尾 <div class="yk-product-param-images"> 区块，更新时整块替换。
 * - 撤销：删本批新建的产品、分类、媒体（仍被别处用着的媒体保留），被更新的产品恢复原值；
 *   导入后又在后台改过的产品不动，记为冲突。撤销可以重复执行（中途断了再撤一次，已恢复的不报冲突）。
 *
 * 中断后续跑（2.3.0 后台执行）：
 * - 新建的产品、分类、媒体在同一个数据库事务里打上「属于哪个任务的哪一行」的标记（metas import_job）；
 *   续跑时先按标记找，找到就认领，不再新建——写进库、还没来得及记进任务文件就中断，也不会重复。
 * - 更新产品前，先把原值记进任务并存盘（$checkpoint），再改库：中断后撤销仍能恢复到导入前。
 */

declare(strict_types=1);

final class ProductImportRunner
{
    public const BATCH = 20;
    public const PARAM_BLOCK_CLASS = 'yk-product-param-images';
    /** 更新时可能改动、撤销时要恢复的列 */
    private const PRODUCT_FIELDS = ['title', 'category_id', 'model', 'cover', 'images', 'summary', 'content', 'specs'];
    /** 新建的行属于哪个任务（metas.meta_key）；值：产品 "<任务>#<行号>"，分类 "<任务>#cat:<键>"，媒体 "<任务>" */
    public const TAG = 'import_job';
    /** 任务格式版本：2 = 2.3.0 起（后台执行、可续跑）；没有这个键的是升级前建的任务 */
    public const VERSION = 2;

    /** @var null|Closure(array<string,mixed>, bool): void 存盘（第二个参数 true = 必须马上存，例如改库之前记下的原值） */
    private ?Closure $checkpoint = null;
    /** @var null|Closure(string, int): void 测试用：每写进库一行之后调用（种类、行 id） */
    private ?Closure $afterWrite = null;

    public function __construct(private ProductImportPackage $package, private string $siteRoot)
    {
    }

    /** @param null|Closure(string, int): void $afterWrite */
    public function onWrite(?Closure $afterWrite): void
    {
        $this->afterWrite = $afterWrite;
    }

    /**
     * 由计划生成任务。$decisions：产品行号 => create|update|skip（不给就按计划）；有错误的行一律不导入。
     * @param array<string,mixed> $plan @param array<int,string> $decisions @return array<string,mixed>
     */
    public static function newJob(array $plan, array $decisions, string $packageDir, string $lang, int $adminId, string $name = ''): array
    {
        $items = [];
        foreach ($plan['products'] as $row) {
            $index = (int) $row['index'];
            $action = (string) $row['action'];
            $choice = $decisions[$index] ?? $action;
            if ($action === 'error') {
                $status = 'error';
                $action = 'skip';
            } elseif ($choice === 'skip' || ($choice === 'update' && $row['match'] === null)) {
                $status = 'skipped';
                $action = 'skip';
            } else {
                $status = 'pending';
                // 能匹配上的行也可以选「新增」：另建一款，不碰原产品
                $action = $choice === 'create' ? 'create' : $action;
            }
            $items[] = [
                'index' => $index, 'title' => (string) $row['title'], 'key' => (string) $row['key'], 'action' => $action,
                'match_id' => (int) ($row['match']['id'] ?? 0), 'status' => $status, 'product_id' => 0,
                'message' => $status === 'error' ? implode(',', array_column($row['errors'], 'code')) : '',
            ];
        }
        $categories = [];
        foreach ($plan['categories'] as $row) {
            $categories[] = ['key' => (string) $row['key'], 'action' => $row['errors'] === [] ? (string) $row['action'] : 'error',
                'site_id' => (int) $row['site_id']];
        }
        $job = [
            'id' => ProductImportJobs::newId(), 'v' => self::VERSION, 'name' => $name, 'status' => 'queued', 'lang' => $lang, 'admin_id' => $adminId,
            'package' => $packageDir, 'created_at' => time(), 'cursor' => 0, 'categories' => $categories, 'category_ids' => [], 'categories_done' => false,
            'images' => [], 'items' => $items,
            'created' => ['categories' => [], 'products' => [], 'stamps' => [], 'media' => []], 'updated' => [],
        ];
        $job['counts'] = self::counts($job);
        return $job;
    }

    /**
     * 跑下一批（最多 $limit 款产品）。分类在第一批里全部建好。
     * queued 是 2.3.0 起确认后排队的任务，ready 是升级前建的任务。
     * @param array<string,mixed> $job
     * @param null|Closure(array<string,mixed>, bool): void $checkpoint 存盘；不给就只在调用方跑完一批后存
     */
    public function run(array &$job, int $limit = self::BATCH, ?Closure $checkpoint = null): void
    {
        if (!in_array($job['status'], ['queued', 'ready', 'running'], true)) {
            return;
        }
        $this->checkpoint = $checkpoint;
        $job['status'] = 'running';
        // 升级前的任务没有 categories_done：建过分类（category_ids 非空）就算建完
        if (!($job['categories_done'] ?? ($job['category_ids'] !== []))) {
            $this->categories($job);
            $job['categories_done'] = true;
            $this->save($job, true);
        }
        $products = $this->package->products;
        $done = 0;
        $count = count($job['items']);
        while ($job['cursor'] < $count && $done < $limit) {
            $i = (int) $job['cursor'];
            if ($job['items'][$i]['status'] !== 'pending') {
                $job['cursor'] = $i + 1;
                continue;
            }
            $done++;
            // 游标在这一行做完之后才前移：做到一半存的盘（更新前的原值）里，这一行仍是「下一行」
            $this->product($job, $i, $products[$job['items'][$i]['index']] ?? null);
            $job['cursor'] = $i + 1;
            $job['counts'] = self::counts($job);
            $this->save($job, false);
        }
        // 后面只剩跳过 / 有错的行：这一批就算跑完，不让页面再空跑一轮
        while ($job['cursor'] < $count && $job['items'][$job['cursor']]['status'] !== 'pending') {
            $job['cursor']++;
        }
        if ($job['cursor'] >= $count) {
            $job['status'] = 'done';
            if (function_exists('do_action')) {
                do_action('after_save_product', 0, [], true);
                do_action('data_changed', 'products', null);
            }
        }
        $job['counts'] = self::counts($job);
        $this->checkpoint = null;
    }

    /** @param array<string,mixed> $job */
    private function save(array $job, bool $now): void
    {
        if ($this->checkpoint !== null) ($this->checkpoint)($job, $now);
    }

    /**
     * 失败的行重新排队（修好包里的图再试）。
     * @param array<string,mixed> $job
     */
    public static function retryFailed(array &$job): int
    {
        $n = 0;
        foreach ($job['items'] as $i => $item) {
            if ($item['status'] === 'failed') {
                $job['items'][$i]['status'] = 'pending';
                $job['items'][$i]['message'] = '';
                $n++;
            }
        }
        if ($n > 0) {
            $job['cursor'] = 0;
            $job['status'] = 'running';
            $job['counts'] = self::counts($job);
        }
        return $n;
    }

    /**
     * 撤销整批。返回没能恢复的产品（导入后又改过的）。
     * @param array<string,mixed> $job @return list<array{id:int,title:string}>
     */
    public function undo(array &$job): array
    {
        $p = DB_PREFIX;
        $conflicts = [];
        foreach (array_reverse($job['updated'], true) as $id => $record) {
            $id = (int) $id;
            $current = db()->fetchOne("SELECT * FROM {$p}products WHERE id = ?", [$id]);
            if (!is_array($current)) {
                continue;
            }
            $changed = false;
            foreach ($record['after'] as $field => $value) {
                $changed = $changed || (string) $current[$field] !== (string) $value;
            }
            if ($changed) {
                // 已经恢复过（上一次撤销中途断了）：不算冲突
                $restored = true;
                foreach ($record['before'] as $field => $value) {
                    $restored = $restored && (string) $current[$field] === (string) $value;
                }
                if (!$restored) $conflicts[] = ['id' => $id, 'title' => (string) $current['title']];
                continue;
            }
            productModel()->updateById($id, $record['before'] + ['updated_at' => time()]);
            foreach ($record['metas_before'] as $key => $value) {
                $value === null ? metaModel()->del('product', $id, $key) : metaModel()->set('product', $id, $key, $value);
            }
        }
        foreach ($job['created']['products'] as $id) {
            $id = (int) $id;
            $current = db()->fetchOne("SELECT title, updated_at FROM {$p}products WHERE id = ?", [$id]);
            if (!is_array($current)) {
                continue;
            }
            // 新建后又在后台保存过：留着，记为冲突
            if ((int) $current['updated_at'] > (int) ($job['created']['stamps'][$id] ?? PHP_INT_MAX)) {
                $conflicts[] = ['id' => $id, 'title' => (string) $current['title']];
                continue;
            }
            productModel()->forceDeleteById($id);
            metaModel()->del('product', $id);
            if (function_exists('productRouteModel')) {
                productRouteModel()->remove('product', $id);
            }
        }
        // 先子后父；还有产品或子分类挂着的（导入后有人加的）留着。
        // 中断后续跑认领回来的分类不一定按建的先后排：一遍删不掉的上级，等下级删了再来一遍
        $left = array_reverse(array_map('intval', $job['created']['categories']));
        do {
            $progress = false;
            foreach ($left as $n => $id) {
                $used = (int) db()->fetchColumn("SELECT COUNT(*) FROM {$p}products WHERE category_id = ?", [$id])
                    + (int) db()->fetchColumn("SELECT COUNT(*) FROM {$p}product_categories WHERE parent_id = ?", [$id]);
                if ($used === 0) {
                    db()->delete('product_categories', 'id = ?', [$id]);
                    metaModel()->del('product_category', $id);
                    if (function_exists('productRouteModel')) {
                        productRouteModel()->remove('category', $id);
                    }
                    unset($left[$n]);
                    $progress = true;
                }
            }
        } while ($progress && $left !== []);
        foreach ($job['created']['media'] as $id) {
            $media = db()->fetchOne("SELECT * FROM {$p}media WHERE id = ?", [(int) $id]);
            if (!is_array($media) || $this->imageInUse((string) $media['url'])) {
                continue;
            }
            mediaModel()->deleteById((int) $id);
            metaModel()->del('media', (int) $id);
            // 缩略图、WebP 副本一起删；原图再按记录的路径兜底删一次
            if (class_exists('MediaOptimization')) {
                MediaOptimization::deleteArtifacts($media);
            }
            $file = $this->siteRoot . (string) $media['url'];
            if (str_starts_with((string) $media['url'], '/uploads/') && is_file($file)) {
                unlink($file);
            }
        }
        $job['status'] = 'undone';
        $job['undo_conflicts'] = $conflicts;
        $job['undone_at'] = time();
        if (function_exists('do_action')) {
            do_action('after_save_product', 0, [], true);
            do_action('data_changed', 'products', null);
        }
        return $conflicts;
    }

    /**
     * 导入后检查：导入成功的每款产品都在，主图、产品图、参数图和详情里的本站图片文件都在。
     * @param array<string,mixed> $job
     * @return array{checked:int,problems:list<array{index:int,product_id:int,title:string,missing:list<string>}>}
     */
    public function verify(array $job): array
    {
        $checked = 0;
        $problems = [];
        foreach ($job['items'] as $item) {
            if ($item['status'] !== 'done') {
                continue;
            }
            $checked++;
            $id = (int) $item['product_id'];
            $row = db()->fetchOne('SELECT cover, images, content FROM ' . DB_PREFIX . 'products WHERE id = ? AND deleted_at IS NULL', [$id]);
            $missing = [];
            if (!is_array($row)) {
                $missing[] = 'product';
            } else {
                $urls = [(string) $row['cover']];
                foreach ([json_decode((string) $row['images'], true), json_decode((string) metaModel()->get('product', $id, 'param_images', '[]'), true)] as $list) {
                    $urls = array_merge($urls, is_array($list) ? array_filter($list, 'is_string') : []);
                }
                $urls = array_merge($urls, ProductImportPackage::contentImages((string) $row['content'])[0]);
                foreach (array_unique($urls) as $url) {
                    $path = (string) parse_url($url, PHP_URL_PATH);
                    if (str_starts_with($path, '/uploads/') && !is_file($this->siteRoot . rawurldecode($path))) {
                        $missing[] = $url;
                    }
                }
            }
            if ($missing !== []) {
                $problems[] = ['index' => (int) $item['index'], 'product_id' => $id, 'title' => (string) $item['title'], 'missing' => $missing];
            }
        }
        return ['checked' => $checked, 'problems' => $problems];
    }

    /** @param array<string,mixed> $job @return array<string,int> */
    public static function counts(array $job): array
    {
        $counts = ['total' => count($job['items']), 'pending' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'error' => 0, 'failed' => 0];
        foreach ($job['items'] as $item) {
            $key = match ($item['status']) {
                'done' => $item['action'] === 'update' ? 'updated' : 'created',
                default => (string) $item['status'],
            };
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        $counts['categories_created'] = count($job['created']['categories']);
        $counts['media_created'] = count($job['created']['media']);
        return $counts;
    }

    // ── 分类 ──

    /** @param array<string,mixed> $job */
    private function categories(array &$job): void
    {
        $byKey = [];
        foreach ($this->package->categories as $category) {
            $byKey[$category['key']] ??= $category;
        }
        $pending = [];
        foreach ($job['categories'] as $row) {
            if ($row['action'] === 'existing') {
                $job['category_ids'][$row['key']] = $row['site_id'];
            } elseif ($row['action'] === 'create' && isset($byKey[$row['key']]) && !isset($job['category_ids'][$row['key']])) {
                $pending[$row['key']] = $byKey[$row['key']];
            }
        }
        // 上级可能排在后面：每轮建能建的，直到没有进展
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $key => $category) {
                $parent = (string) $category['parent'];
                $parentId = $parent === '' ? 0 : ($job['category_ids'][$parent] ?? $this->siteCategoryId($parent, (string) $job['lang']));
                if ($parentId === null) {
                    continue;
                }
                $job['category_ids'][$key] = $this->createCategory($job, $category, (int) $parentId);
                unset($pending[$key]);
                $progress = true;
            }
            if (!$progress) {
                break;
            }
        }
        $job['category_ids'] += ['' => 0];
    }

    private function siteCategoryId(string $slug, string $lang): ?int
    {
        $id = db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'product_categories WHERE slug = ? AND lang = ? LIMIT 1', [$slug, $lang]);
        return $id === false || $id === null ? null : (int) $id;
    }

    /** @param array<string,mixed> $job @param array<string,mixed> $category */
    private function createCategory(array &$job, array $category, int $parentId): int
    {
        $tag = $job['id'] . '#cat:' . $category['key'];
        $adopted = self::tagged('product_category', $tag, 'product_categories');
        if ($adopted > 0) {
            // 上次写进库后中断、没记进任务：认领，不再建；分类图也认领回来（撤销时一起删）
            if (!in_array($adopted, array_map('intval', $job['created']['categories']), true)) $job['created']['categories'][] = $adopted;
            if ((string) $category['image'] !== '') {
                try {
                    $this->image($job, (string) $category['image']);
                } catch (RuntimeException) {
                }
            }
            return $adopted;
        }
        $image = '';
        if ((string) $category['image'] !== '') {
            try {
                $image = $this->image($job, (string) $category['image']);
            } catch (RuntimeException) {
                $image = '';   // 分类图坏了不拦分类；预览里已经报过
            }
        }
        $id = self::transaction(function () use ($job, $category, $parentId, $image, $tag): int {
            $id = (int) productCategoryModel()->create([
                'parent_id' => $parentId, 'name' => (string) $category['name'], 'slug' => $this->uniqueSlug('product_categories', (string) $category['key'], (string) $category['name'], (string) $job['lang']),
                'lang' => (string) $job['lang'], 'image' => $image, 'status' => 1, 'is_nav' => 1,
                'sort_order' => (int) $category['sort_order'], 'created_at' => time(),
            ]);
            metaModel()->set('product_category', $id, self::TAG, $tag);
            return $id;
        });
        $this->wrote('category', $id);
        $job['created']['categories'][] = $id;
        $this->save($job, false);
        return $id;
    }

    // ── 产品 ──

    /** @param array<string,mixed> $job @param array<string,mixed>|null $product */
    private function product(array &$job, int $i, ?array $product): void
    {
        $item = &$job['items'][$i];
        if ($product === null) {
            $item['status'] = 'failed';
            $item['message'] = 'row_missing';
            return;
        }
        try {
            $cover = $this->image($job, (string) $product['cover']);
            $images = [];
            foreach ($product['images'] as $ref) {
                $images[] = $this->image($job, (string) $ref);
            }
            $params = [];
            foreach ($product['param_images'] as $ref) {
                $params[] = $this->image($job, (string) $ref);
            }
            // 详情里的图（参数表常放在这里）：包里的导入媒体库，地址改成本站的
            $contentUrls = [];
            foreach ($product['content_images'] ?? [] as $ref) {
                $contentUrls[(string) $ref] = $this->image($job, (string) $ref);
            }
        } catch (RuntimeException $e) {
            $item['status'] = 'failed';
            $item['message'] = $e->getMessage();
            return;
        }
        $categoryKey = (string) $product['category'];
        // 分类键可以是包里的，也可以直接写本站已有分类的别名
        $categoryId = (int) ($job['category_ids'][$categoryKey] ?? $this->siteCategoryId($categoryKey, (string) $job['lang']) ?? 0);
        $paramBlock = self::paramBlock($params, (string) $product['title']);
        $packageContent = self::rewriteImages((string) $product['content'], $contentUrls);

        $fields = ['title' => (string) $product['title'], 'cover' => $cover];
        if ((string) $product['category'] !== '') {
            $fields['category_id'] = $categoryId;
        }
        foreach (['model', 'summary'] as $field) {
            if ((string) $product[$field] !== '') {
                $fields[$field] = (string) $product[$field];
            }
        }
        if ($images !== []) {
            $fields['images'] = json_encode(array_values(array_unique($images)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($product['specs'] !== []) {
            $fields['specs'] = json_encode($product['specs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $metas = array_filter(['import_key' => (string) $product['key'], 'source_url' => (string) $product['source_url']], static fn ($v) => $v !== '');
        // 下次同步时据此判断：导入后有没有人在本站改过它
        $metas['import_synced_at'] = (string) time();
        if ($params !== []) {
            $metas['param_images'] = json_encode($params, JSON_UNESCAPED_SLASHES);
        }

        $lang = (string) $job['lang'];
        if ($item['action'] === 'update' && $item['match_id'] > 0) {
            $id = (int) $item['match_id'];
            $current = db()->fetchOne('SELECT * FROM ' . DB_PREFIX . 'products WHERE id = ? AND deleted_at IS NULL', [$id]);
            if (!is_array($current)) {
                $item['status'] = 'failed';
                $item['message'] = 'product_gone';
                return;
            }
            $content = $packageContent !== '' ? $packageContent : (string) $current['content'];
            if ((string) $product['content'] !== '' || $params !== []) {
                $fields['content'] = self::withParamBlock($content, $paramBlock);
            }
            // 原值只记第一次（同一批里重试、中断后续跑都不覆盖），并且先存盘再改库：
            // 改完库、还没存任务就中断时，撤销仍能恢复到导入前的值
            if (!isset($job['updated'][$id])) {
                $before = [];
                foreach (self::PRODUCT_FIELDS as $field) {
                    $before[$field] = $current[$field];
                }
                $metasBefore = [];
                foreach (['import_key', 'source_url', 'param_images', 'import_synced_at'] as $key) {
                    $metasBefore[$key] = metaModel()->get('product', $id, $key);
                }
                $job['updated'][$id] = ['before' => $before, 'metas_before' => $metasBefore, 'after' => []];
                $this->save($job, true);
            }
            $job['updated'][$id]['after'] = array_intersect_key($fields, array_flip(self::PRODUCT_FIELDS)) + $job['updated'][$id]['after'];
            self::transaction(static function () use ($id, $fields, $metas): void {
                productModel()->updateById($id, $fields + ['updated_at' => time()]);
                foreach ($metas as $key => $value) {
                    metaModel()->set('product', $id, $key, $value);
                }
            });
        } else {
            $tag = $job['id'] . '#' . $item['index'];
            $id = self::tagged('product', $tag, 'products') ?: $this->legacyCreated($job, $item);
            if ($id > 0) {
                // 上次写进库后中断、没记进任务：认领这一款，不再新建
                $stamp = (int) db()->fetchColumn('SELECT updated_at FROM ' . DB_PREFIX . 'products WHERE id = ?', [$id]);
                if (!in_array($id, array_map('intval', $job['created']['products']), true)) $job['created']['products'][] = $id;
                $job['created']['stamps'][$id] ??= $stamp;
            } else {
                $now = time();
                $id = self::transaction(function () use ($fields, $lang, $product, $packageContent, $paramBlock, $job, $metas, $tag, $now): int {
                    $id = (int) productModel()->create($fields + [
                        'lang' => $lang, 'slug' => $this->uniqueSlug('products', (string) $product['slug'], (string) $product['title'], $lang),
                        'content' => self::withParamBlock($packageContent, $paramBlock),
                        'status' => (int) $product['status'], 'sort_order' => (int) $product['sort_order'],
                        'created_at' => $now, 'updated_at' => $now, 'admin_id' => (int) $job['admin_id'],
                    ]);
                    foreach ($metas + [self::TAG => $tag] as $key => $value) {
                        metaModel()->set('product', $id, $key, $value);
                    }
                    return $id;
                });
                $job['created']['products'][] = $id;
                $job['created']['stamps'][$id] = $now;
            }
        }
        $this->wrote('product', $id);
        $item['status'] = 'done';
        $item['product_id'] = $id;
        $item['message'] = '';
        unset($item);
    }

    /** 带某个标记、而且行还在的 id；没有返回 0 */
    private static function tagged(string $ownerType, string $tag, string $table): int
    {
        $id = (int) (db()->fetchColumn('SELECT owner_id FROM ' . DB_PREFIX . 'metas WHERE owner_type = ? AND meta_key = ? AND meta_value = ? ORDER BY owner_id LIMIT 1',
            [$ownerType, self::TAG, $tag]) ?: 0);
        return $id > 0 && db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE id = ?', [$id]) ? $id : 0;
    }

    /**
     * 升级前建的任务续跑（legacy_resume）：那时没有标记。上一批写进库、没记进任务的产品，
     * 按「同一导入键、同一语言、任务开始后新建、还没被这个任务认领」找回来。找不到返回 0。
     * @param array<string,mixed> $job @param array<string,mixed> $item
     */
    private function legacyCreated(array $job, array $item): int
    {
        if (empty($job['legacy_resume']) || (string) $item['key'] === '') return 0;
        $p = DB_PREFIX;
        $ids = db()->fetchAll("SELECT p.id FROM {$p}products p JOIN {$p}metas m ON m.owner_type = 'product' AND m.owner_id = p.id AND m.meta_key = 'import_key'"
            . ' WHERE m.meta_value = ? AND p.lang = ? AND p.created_at >= ? AND p.deleted_at IS NULL ORDER BY p.id',
            [(string) $item['key'], (string) $job['lang'], (int) $job['created_at']]);
        $claimed = array_map('intval', $job['created']['products']);
        foreach ($ids as $row) {
            if (!in_array((int) $row['id'], $claimed, true)) return (int) $row['id'];
        }
        return 0;
    }

    /**
     * 在一个事务里写（已经在事务里就直接写）。
     * @template T @param Closure(): T $work @return T
     */
    private static function transaction(Closure $work): mixed
    {
        $db = db();
        if ($db->getPdo()->inTransaction()) return $work();
        $db->beginTransaction();
        try {
            $result = $work();
            $db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($db->getPdo()->inTransaction()) $db->rollback();
            throw $error;
        }
    }

    private function wrote(string $kind, int $id): void
    {
        if ($this->afterWrite !== null) ($this->afterWrite)($kind, $id);
    }

    /** @param array<string,string> $urls 包里的引用 => 本站网址 */
    public static function rewriteImages(string $html, array $urls): string
    {
        if ($urls === []) {
            return $html;
        }
        return (string) preg_replace_callback('~(<img\b[^>]*?\ssrc\s*=\s*)(["\'])(.*?)\2~is', static function (array $m) use ($urls): string {
            $src = trim(html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5));
            return isset($urls[$src]) ? $m[1] . $m[2] . htmlspecialchars($urls[$src], ENT_QUOTES) . $m[2] : $m[0];
        }, $html);
    }

    /** @param list<string> $urls */
    public static function paramBlock(array $urls, string $title): string
    {
        if ($urls === []) {
            return '';
        }
        $html = '<div class="' . self::PARAM_BLOCK_CLASS . '">';
        foreach ($urls as $url) {
            $html .= '<p><img src="' . htmlspecialchars($url, ENT_QUOTES) . '" alt="' . htmlspecialchars($title, ENT_QUOTES) . '" loading="lazy"></p>';
        }
        return $html . '</div>';
    }

    /** 详情里的参数图区块整块替换；没有新区块就去掉旧的。 */
    public static function withParamBlock(string $content, string $block): string
    {
        $content = rtrim((string) preg_replace('~\s*<div class="' . self::PARAM_BLOCK_CLASS . '">.*?</div>~s', '', $content));
        return $block === '' ? $content : ($content === '' ? $block : $content . "\n" . $block);
    }

    /** 包里给的别名优先，否则按名称生成（与后台同一套口径，只留 a-z0-9-）；本语言里撞了加 -2、-3… */
    private function uniqueSlug(string $table, string $slug, string $title, string $lang): string
    {
        if (!function_exists('normalizeSlugInput')) {
            require_once ROOT_PATH . '/includes/Slug.php';
        }
        $base = normalizeSlugInput($slug);
        $base = $base !== '' ? $base : generateSlug($title);
        $base = substr($base !== '' ? $base : ($table === 'products' ? 'product' : 'category'), 0, 180);
        $slug = $base;
        for ($n = 2; db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE slug = ? AND lang = ? LIMIT 1', [$slug, $lang]); $n++) {
            $slug = $base . '-' . $n;
        }
        return $slug;
    }

    // ── 图片 ──

    /**
     * 包里或本站的图 → 本站网址。失败抛 RuntimeException（消息是错误代码）。
     * @param array<string,mixed> $job
     */
    private function image(array &$job, string $ref): string
    {
        $resolved = $this->package->resolveImage($ref);
        if ($resolved['kind'] === 'site') {
            return '/' . ltrim(str_replace('\\', '/', substr($resolved['path'], strlen($this->siteRoot))), '/');
        }
        if ($resolved['kind'] !== 'package') {
            throw new RuntimeException($resolved['kind'] === 'invalid' ? 'image_not_allowed' : 'image_not_found');
        }
        $md5 = (string) md5_file($resolved['path']);
        if (isset($job['images'][$md5])) {
            return (string) $job['images'][$md5];
        }
        $url = $this->existingMedia($job, $md5) ?? $this->copyImage($job, $resolved['path'], $md5);
        $job['images'][$md5] = $url;
        return $url;
    }

    /** @param array<string,mixed> $job */
    private function existingMedia(array &$job, string $md5): ?string
    {
        $p = DB_PREFIX;
        $rows = db()->fetchAll("SELECT id, url FROM {$p}media WHERE md5 = ? ORDER BY id LIMIT 5", [$md5]);
        $rows = array_merge($rows, db()->fetchAll(
            "SELECT md.id, md.url FROM {$p}metas m JOIN {$p}media md ON md.id = m.owner_id WHERE m.owner_type = 'media' AND m.meta_key = 'source_md5' AND m.meta_value = ? LIMIT 5",
            [$md5]
        ));
        foreach ($rows as $row) {
            $url = (string) $row['url'];
            if (str_starts_with($url, '/uploads/') && is_file($this->siteRoot . $url)) {
                // 这个任务自己上次建的、中断后没记进任务：认领，撤销时一起删
                $id = (int) $row['id'];
                if (!in_array($id, array_map('intval', $job['created']['media']), true)
                    && metaModel()->get('media', $id, self::TAG) === (string) $job['id']) {
                    $job['created']['media'][] = $id;
                }
                return $url;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $job */
    private function copyImage(array &$job, string $source, string $md5): string
    {
        $info = @getimagesize($source);
        $ext = strtolower(pathinfo($source, PATHINFO_EXTENSION));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        // avif：核心上传不收，但迁移来的站里有；服务器读得出来（PHP 8.2+）才收
        $mimes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif'];
        if (!is_array($info) || !isset($mimes[$ext]) || $info['mime'] !== $mimes[$ext]) {
            throw new RuntimeException('image_invalid');
        }
        [$width, $height] = [(int) $info[0], (int) $info[1]];
        if (function_exists('uploadMaxImageMegapixels') && ($limit = uploadMaxImageMegapixels()) > 0
            && !imageDimensionsWithinPixelLimit($width, $height, $limit * 1_000_000)) {
            throw new RuntimeException('image_too_large');
        }
        $relDir = '/uploads/images/' . date('Ym') . '/products/';
        if (!is_dir($this->siteRoot . $relDir) && !mkdir($this->siteRoot . $relDir, 0755, true) && !is_dir($this->siteRoot . $relDir)) {
            throw new RuntimeException('image_save_failed');
        }
        $name = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $path = $this->siteRoot . $relDir . $name;
        if (!copy($source, $path)) {
            throw new RuntimeException('image_save_failed');
        }
        $quality = function_exists('config') ? max(50, min(95, (int) config('upload_jpeg_quality', 85))) : 85;
        $maxWidth = function_exists('config') ? (int) config('upload_max_width', 1920) : 0;
        if ($ext !== 'gif' && $maxWidth > 0 && $width > $maxWidth && function_exists('downscaleImage') && downscaleImage($path, $ext, $maxWidth, $quality)) {
            clearstatcache(true, $path);
            $info = @getimagesize($path);
            [$width, $height] = is_array($info) ? [(int) $info[0], (int) $info[1]] : [$width, $height];
        }
        if ($ext !== 'avif' && function_exists('generateThumbnails')) {
            generateThumbnails($path, $ext);
        }
        if (in_array($ext, ['jpg', 'png'], true) && function_exists('generateWebpDerivatives') && function_exists('imagewebp')) {
            generateWebpDerivatives($path, $ext, $quality);
        }
        $stored = (string) md5_file($path);
        $id = self::transaction(static function () use ($source, $path, $relDir, $name, $ext, $mimes, $width, $height, $stored, $md5, $job): int {
            $id = (int) mediaModel()->create([
                'name' => basename($source), 'path' => $path, 'url' => $relDir . $name, 'type' => 'image', 'ext' => $ext,
                'mime' => $mimes[$ext], 'size' => (int) filesize($path), 'width' => $width, 'height' => $height, 'md5' => $stored,
                'admin_id' => (int) $job['admin_id'], 'created_at' => time(),
            ]);
            if ($stored !== $md5) {
                metaModel()->set('media', $id, 'source_md5', $md5);
            }
            metaModel()->set('media', $id, self::TAG, (string) $job['id']);
            return $id;
        });
        $this->wrote('media', $id);
        $job['created']['media'][] = $id;
        return $relDir . $name;
    }

    private function imageInUse(string $url): bool
    {
        $p = DB_PREFIX;
        $like = '%' . $url . '%';
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$p}products WHERE cover = ? OR images LIKE ? OR content LIKE ?",
            [$url, $like, $like]
        ) > 0 || (int) db()->fetchColumn("SELECT COUNT(*) FROM {$p}product_categories WHERE image = ?", [$url]) > 0;
    }
}
