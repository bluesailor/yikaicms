<?php
/**
 * 产品导入 2.0 执行器：真写内存 SQLite 与临时站点目录。
 * 新建 → 同一包重导变更新（图片不再复制）→ 撤销；分批 + 任务存盘续跑；坏图失败后重试；导入后又改过的产品撤销时不动。
 */

declare(strict_types=1);

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportPackage.php';
require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportPlanner.php';
require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportSiteDb.php';
require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportJobs.php';
require_once ROOT_PATH . '/plugins/product-import/lib/ProductImportRunner.php';

final class ProductImportRunnerTest extends TestCase
{
    private string $tmp = '';

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE products (id INTEGER PRIMARY KEY AUTOINCREMENT, lang TEXT DEFAULT 'zh-CN', translation_group_id INTEGER DEFAULT 0,
                category_id INTEGER DEFAULT 0, brand_id INTEGER DEFAULT 0, title TEXT NOT NULL, subtitle TEXT DEFAULT '', slug TEXT DEFAULT '',
                cover TEXT DEFAULT '', images TEXT, summary TEXT, content TEXT, model TEXT DEFAULT '', specs TEXT, status INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0, admin_id INTEGER DEFAULT 0, deleted_at INTEGER DEFAULT NULL)",
            "CREATE TABLE product_categories (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0, name TEXT NOT NULL, slug TEXT DEFAULT '',
                lang TEXT DEFAULT 'zh-CN', translation_group_id INTEGER DEFAULT 0, image TEXT DEFAULT '', description TEXT, status INTEGER DEFAULT 1,
                is_nav INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0)",
            "CREATE TABLE media (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', path TEXT NOT NULL DEFAULT '', url TEXT NOT NULL DEFAULT '',
                type TEXT NOT NULL DEFAULT 'image', ext TEXT NOT NULL DEFAULT '', mime TEXT NOT NULL DEFAULT '', size INTEGER NOT NULL DEFAULT 0,
                width INTEGER NOT NULL DEFAULT 0, height INTEGER NOT NULL DEFAULT 0, md5 TEXT NOT NULL DEFAULT '', admin_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE metas (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT NOT NULL, owner_id INTEGER NOT NULL DEFAULT 0, meta_key TEXT NOT NULL,
                meta_value TEXT, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, UNIQUE(owner_type, owner_id, meta_key))",
        ];
    }

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is required to draw test images');
        }
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/pimr-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/package/images', 0777, true);
        mkdir($this->tmp . '/site/uploads/images', 0777, true);
        foreach (['washer' => 0xff0000, 'flat' => 0x00ff00, 'flat-2' => 0x0000ff, 'spring' => 0xffff00, 'spec' => 0x00ffff] as $name => $color) {
            $img = imagecreatetruecolor(400, 400);
            imagefill($img, 0, 0, $color);
            imagepng($img, $this->tmp . "/package/images/{$name}.png");
            imagedestroy($img);
        }
        $this->manifest();
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tmp);
        }
        parent::tearDown();
    }

    /** @param list<array<string,mixed>>|null $products */
    private function manifest(?array $products = null): void
    {
        file_put_contents($this->tmp . '/package/products.json', json_encode([
            'format' => 'yikai-product-package', 'version' => 1, 'lang' => 'en',
            // 小类排在大类前面：执行器要先建上级
            'categories' => [
                ['key' => 'washer-flat', 'name' => 'Flat Washer', 'parent' => 'washer', 'sort_order' => 2],
                ['key' => 'washer', 'name' => 'Washer', 'image' => 'washer.png', 'sort_order' => 1],
            ],
            'products' => $products ?? [
                ['key' => 'A1', 'source_url' => 'https://example.com/a1', 'title' => 'Flat washer DIN125', 'category' => 'washer-flat',
                    'cover' => 'flat.png', 'images' => ['flat.png', 'flat-2.png'], 'param_images' => ['spec.png'],
                    'content' => '<p>Zinc plated.</p>', 'specs' => ['Material' => 'Steel'], 'model' => 'DIN125'],
                ['key' => 'A2', 'title' => 'Spring washer', 'category' => 'washer', 'cover' => 'spring.png', 'images' => ['flat-2.png'], 'status' => 0],
            ],
        ]));
    }

    /** @param array<int,string> $decisions @return array<string,mixed> */
    private function job(array $decisions = []): array
    {
        $package = ProductImportPackage::fromDirectory($this->tmp . '/package', $this->tmp . '/site');
        $plan = (new ProductImportPlanner($package, new ProductImportSiteDb('en', $this->tmp . '/site')))->plan();
        return ProductImportRunner::newJob($plan, $decisions, $this->tmp . '/package', 'en', 1, 'test');
    }

    private function runner(): ProductImportRunner
    {
        return new ProductImportRunner(ProductImportPackage::fromDirectory($this->tmp . '/package', $this->tmp . '/site'), $this->tmp . '/site');
    }

    /** @return array<string,mixed> */
    private function product(string $title): array
    {
        return (array) db()->fetchOne('SELECT * FROM products WHERE title = ?', [$title]);
    }

    private function rows(string $table): int
    {
        return (int) db()->fetchColumn("SELECT COUNT(*) FROM {$table}");
    }

    public function testAFirstImportCreatesCategoriesProductsAndMediaOnce(): void
    {
        $job = $this->job();
        $this->runner()->run($job);

        self::assertSame('done', $job['status']);
        self::assertSame(['created' => 2, 'categories_created' => 2, 'media_created' => 5],
            array_intersect_key($job['counts'], array_flip(['created', 'categories_created', 'media_created'])));

        $parent = db()->fetchOne("SELECT * FROM product_categories WHERE slug = 'washer'");
        $child = db()->fetchOne("SELECT * FROM product_categories WHERE slug = 'washer-flat'");
        self::assertSame([(int) $parent['id'], 'en'], [(int) $child['parent_id'], $child['lang']], '小类挂在新建的大类下');
        self::assertStringStartsWith('/uploads/images/' . date('Ym') . '/products/', $parent['image']);

        $flat = $this->product('Flat washer DIN125');
        self::assertSame([(int) $child['id'], 'flat-washer-din125', 'DIN125', 1], [(int) $flat['category_id'], $flat['slug'], $flat['model'], (int) $flat['status']]);
        $images = json_decode($flat['images'], true);
        self::assertSame($flat['cover'], $images[0], '主图与第一张产品图是同一文件，只复制一次');
        self::assertFileExists($this->tmp . '/site' . $flat['cover']);
        self::assertSame('{"Material":"Steel"}', $flat['specs']);
        self::assertStringStartsWith('<p>Zinc plated.</p>' . "\n" . '<div class="yk-product-param-images"><p><img src="/uploads/images/', $flat['content']);
        self::assertSame('A1', metaModel()->get('product', (int) $flat['id'], 'import_key'));
        self::assertSame('https://example.com/a1', metaModel()->get('product', (int) $flat['id'], 'source_url'));
        self::assertCount(1, json_decode((string) metaModel()->get('product', (int) $flat['id'], 'param_images'), true));

        $spring = $this->product('Spring washer');
        self::assertSame(0, (int) $spring['status']);
        self::assertSame($images[1], json_decode($spring['images'], true)[0], '两款产品共用的图只入库一次');
    }

    public function testReimportingTheSamePackageUpdatesOnlyGivenFieldsAndReusesMedia(): void
    {
        $first = $this->job();
        $this->runner()->run($first);
        $flat = $this->product('Flat washer DIN125');
        db()->update('products', ['slug' => 'my-flat-washer', 'status' => 0, 'summary' => 'Hand written'], 'id = ?', [(int) $flat['id']]);
        $media = $this->rows('media');

        $this->manifest([
            ['key' => 'A1', 'title' => 'Flat washer DIN125 zinc', 'category' => 'washer-flat', 'cover' => 'flat.png', 'param_images' => ['spec.png'], 'content' => '<p>New text.</p>'],
        ]);
        $second = $this->job();
        self::assertSame('update', $second['items'][0]['action']);
        self::assertSame((int) $flat['id'], $second['items'][0]['match_id'], '按导入键认出是同一款，改了名称也认得');
        $this->runner()->run($second);

        $after = (array) db()->fetchOne('SELECT * FROM products WHERE id = ?', [(int) $flat['id']]);
        self::assertSame(['Flat washer DIN125 zinc', 'my-flat-washer', 0, 'Hand written', $flat['images']],
            [$after['title'], $after['slug'], (int) $after['status'], $after['summary'], $after['images']], '别名、状态、包里没给的字段不动');
        self::assertSame(1, substr_count($after['content'], 'yk-product-param-images'), '参数图区块整块替换，不叠加');
        self::assertStringStartsWith('<p>New text.</p>', $after['content']);
        self::assertSame($media, $this->rows('media'), '同样的图不再复制');
        self::assertSame(0, $second['counts']['categories_created']);
        self::assertSame(2, $this->rows('product_categories'));
    }

    public function testUndoRemovesWhatTheJobCreatedAndRestoresWhatItUpdated(): void
    {
        $first = $this->job();
        $this->runner()->run($first);
        $spring = $this->product('Spring washer');

        $this->manifest([
            ['key' => 'A2', 'title' => 'Spring washer renamed', 'category' => 'washer', 'cover' => 'spring.png'],
            ['key' => 'B1', 'title' => 'Brand new washer', 'category' => 'washer-lock', 'cover' => 'spec.png'],
        ]);
        $raw = json_decode((string) file_get_contents($this->tmp . '/package/products.json'), true);
        $raw['categories'][] = ['key' => 'washer-lock', 'name' => 'Lock Washer', 'parent' => 'washer'];
        file_put_contents($this->tmp . '/package/products.json', json_encode($raw));
        $second = $this->job();
        $this->runner()->run($second);
        self::assertSame(1, $second['counts']['categories_created']);
        $new = $this->product('Brand new washer');
        $copied = $this->tmp . '/site' . db()->fetchColumn('SELECT url FROM media ORDER BY id DESC LIMIT 1');

        $conflicts = $this->runner()->undo($second);

        self::assertSame([], $conflicts);
        self::assertSame('undone', $second['status']);
        self::assertSame('Spring washer', (string) db()->fetchColumn('SELECT title FROM products WHERE id = ?', [(int) $spring['id']]));
        self::assertSame([], db()->fetchAll('SELECT id FROM products WHERE id = ?', [(int) $new['id']]));
        self::assertNull(metaModel()->get('product', (int) $new['id'], 'import_key'));
        self::assertSame(2, $this->rows('product_categories'), '本批新建的 Lock Washer 删掉，第一批的分类不动');
        self::assertSame(5, $this->rows('media'), 'spec.png 第一批就用过，仍有产品引用，保留');
        self::assertFileExists($copied);

        // 撤销第一批：媒体文件一起删
        $this->runner()->undo($first);
        self::assertSame([0, 0, 0], [$this->rows('products'), $this->rows('product_categories'), $this->rows('media')]);
        self::assertFileDoesNotExist($copied);
    }

    public function testUndoLeavesProductsEditedAfterTheImport(): void
    {
        $first = $this->job();
        $this->runner()->run($first);
        $this->manifest([['key' => 'A1', 'title' => 'Imported title', 'cover' => 'flat.png']]);
        $second = $this->job();
        $this->runner()->run($second);
        $id = (int) $second['items'][0]['product_id'];
        db()->update('products', ['title' => 'Edited by hand'], 'id = ?', [$id]);

        $conflicts = $this->runner()->undo($second);

        self::assertSame([['id' => $id, 'title' => 'Edited by hand']], $conflicts);
        self::assertSame('Edited by hand', (string) db()->fetchColumn('SELECT title FROM products WHERE id = ?', [$id]));
    }

    public function testAProductCreatedThenEditedSurvivesUndo(): void
    {
        $job = $this->job();
        $this->runner()->run($job);
        $id = (int) $job['items'][0]['product_id'];
        db()->update('products', ['updated_at' => time() + 60], 'id = ?', [$id]);

        $conflicts = $this->runner()->undo($job);

        self::assertSame([$id], array_column($conflicts, 'id'));
        self::assertSame(1, $this->rows('products'));
        self::assertGreaterThan(0, $this->rows('media'), '还在用的图留着');
    }

    public function testAProductCanPointAtAnExistingSiteCategoryBySlug(): void
    {
        db()->insert('product_categories', ['name' => 'Bolts', 'slug' => 'bolts', 'lang' => 'en']);
        $this->manifest([['title' => 'Hex bolt', 'category' => 'bolts', 'cover' => 'flat.png']]);
        $job = $this->job();
        $this->runner()->run($job);
        self::assertSame((int) db()->fetchColumn("SELECT id FROM product_categories WHERE slug = 'bolts'"), (int) $this->product('Hex bolt')['category_id']);
    }

    public function testBatchesResumeFromTheSavedJobAndFailedRowsCanBeRetried(): void
    {
        $jobs = new ProductImportJobs($this->tmp . '/site/storage/product-import/jobs');
        $job = $this->job([1 => 'skip']);
        self::assertSame('skipped', $job['items'][1]['status']);
        rename($this->tmp . '/package/images/flat-2.png', $this->tmp . '/flat-2.png');   // 计划后图片被挪走
        $jobs->save($job);

        $job = $jobs->load($job['id']);
        $this->runner()->run($job, 1);
        self::assertSame(['done', 'failed', 'image_not_found'], [$job['status'], $job['items'][0]['status'], $job['items'][0]['message']]);
        self::assertSame(0, $this->rows('products'));
        $jobs->save($job);

        rename($this->tmp . '/flat-2.png', $this->tmp . '/package/images/flat-2.png');
        $job = $jobs->load($job['id']);
        self::assertSame(1, ProductImportRunner::retryFailed($job));
        $this->runner()->run($job, 1);
        self::assertSame(['done', 1, 1], [$job['status'], $job['counts']['created'], $job['counts']['skipped']]);
        self::assertSame(1, $this->rows('products'), '跳过的那一行没导入');
        self::assertSame($job['id'], $jobs->recent()[0]['id']);
        self::assertFileExists($this->tmp . '/site/storage/product-import/.htaccess');
    }

    public function testParamBlockIsReplacedOrRemoved(): void
    {
        $block = ProductImportRunner::paramBlock(['/uploads/a.png'], 'A "quoted" title');
        self::assertSame('<div class="yk-product-param-images"><p><img src="/uploads/a.png" alt="A &quot;quoted&quot; title" loading="lazy"></p></div>', $block);
        $content = ProductImportRunner::withParamBlock('<p>x</p>', $block);
        self::assertSame($content, ProductImportRunner::withParamBlock($content, $block));
        self::assertSame('<p>x</p>', ProductImportRunner::withParamBlock($content, ''));
        self::assertSame($block, ProductImportRunner::withParamBlock('', $block));
    }
}
