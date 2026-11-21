<?php
/**
 * 产品导入后台执行（2.3.0）：确认后由执行者跑完，不依赖页面；同一任务只有一个执行者；
 * 写进库、还没存进度就中断，续跑不重复写；更新前先存原值；撤销遇上正在执行先排队；
 * 建任务的人不再是超级管理员就停；升级前的任务不会自己跑起来，续跑时找回中断那一批新建的产品。
 *
 * 单进程里用第二个文件句柄模拟「别的执行者」：flock 在 Windows（按句柄）和 Linux（按打开的文件描述）上都互斥。
 * 真正多进程、MySQL 上的验证见 tests/e2e/run-product-import-mysql.ps1。
 */

declare(strict_types=1);

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/product-import/ProductImportPackage.php';
require_once ROOT_PATH . '/includes/product-import/ProductImportPlanner.php';
require_once ROOT_PATH . '/includes/product-import/ProductImportSiteDb.php';
require_once ROOT_PATH . '/includes/product-import/ProductImportWorker.php';

final class ProductImportWorkerTest extends TestCase
{
    private string $tmp = '';
    private ProductImportJobs $jobs;

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
            "CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, role_id INTEGER NOT NULL DEFAULT 1, status INTEGER NOT NULL DEFAULT 1)",
            "CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, permissions TEXT, status INTEGER NOT NULL DEFAULT 1)",
        ];
    }

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('GD is required to draw test images');
        }
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/pimw-' . bin2hex(random_bytes(4));
        mkdir($this->tmp . '/package/images', 0777, true);
        mkdir($this->tmp . '/site/uploads/images', 0777, true);
        foreach (['washer' => 0xff0000, 'flat' => 0x00ff00, 'flat-2' => 0x0000ff, 'spring' => 0xffff00, 'lock' => 0xff00ff] as $name => $color) {
            $img = imagecreatetruecolor(300, 300);
            imagefill($img, 0, 0, $color);
            imagepng($img, $this->tmp . "/package/images/{$name}.png");
        }
        $this->manifest();
        $this->jobs = new ProductImportJobs($this->tmp . '/site/storage/product-import/jobs');
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
            'categories' => [
                ['key' => 'washer-flat', 'name' => 'Flat Washer', 'parent' => 'washer', 'sort_order' => 2],
                ['key' => 'washer', 'name' => 'Washer', 'image' => 'washer.png', 'sort_order' => 1],
            ],
            'products' => $products ?? [
                ['key' => 'A1', 'title' => 'Flat washer DIN125', 'category' => 'washer-flat', 'cover' => 'flat.png', 'images' => ['flat.png', 'flat-2.png'], 'model' => 'DIN125'],
                ['key' => 'A2', 'title' => 'Spring washer', 'category' => 'washer', 'cover' => 'spring.png'],
                ['key' => 'A3', 'title' => 'Lock washer', 'category' => 'washer', 'cover' => 'lock.png'],
            ],
        ]));
    }

    /** 建任务、存盘、标为排队（与页面 job_create 相同） @param array<int,string> $decisions @return string 任务号 */
    private function queue(array $decisions = []): string
    {
        $package = ProductImportPackage::fromDirectory($this->tmp . '/package', $this->tmp . '/site');
        $plan = (new ProductImportPlanner($package, new ProductImportSiteDb('en', $this->tmp . '/site')))->plan();
        $job = ProductImportRunner::newJob($plan, $decisions, $this->tmp . '/package', 'en', 1, 'test');
        $this->jobs->save($job);
        $this->jobs->markActive((string) $job['id']);
        return (string) $job['id'];
    }

    private function worker(?Closure $mayRun = null): ProductImportWorker
    {
        return new ProductImportWorker($this->jobs, $this->tmp . '/site', $mayRun ?? static fn (int $adminId): bool => true);
    }

    private function rows(string $table): int
    {
        return (int) db()->fetchColumn("SELECT COUNT(*) FROM {$table}");
    }

    /** @return array<string,mixed> */
    private function load(string $id): array
    {
        return (array) $this->jobs->load($id);
    }

    public function testAConfirmedJobIsFinishedByTheBackgroundWorker(): void
    {
        $id = $this->queue();
        self::assertSame('queued', $this->load($id)['status']);
        self::assertSame([$id], $this->jobs->active());

        $result = $this->worker()->drain(30);

        self::assertSame(['jobs' => 1, 'progress' => true, 'remaining' => false], $result);
        $job = $this->load($id);
        self::assertSame(['done', 3, 2], [$job['status'], $job['counts']['created'], $job['counts']['categories_created']]);
        self::assertSame(3, $job['verify']['checked'], '跑完自动做导入后检查');
        self::assertArrayNotHasKey('owner', $job, '跑完不留执行者记录');
        self::assertSame([], $this->jobs->active(), '跑完就不再排队');
        self::assertSame(['idle', 'idle'], [$this->worker()->runJob($id, 5)['result'], $this->worker()->runJob($id, 5)['result']], '再跑一次什么都不做');
        self::assertSame(3, $this->rows('products'));
    }

    public function testOnlyOneExecutorWorksOnAJobAtATime(): void
    {
        $id = $this->queue();
        $other = $this->jobs->lock($id);   // 别的执行者（页面、定时任务或命令行）正拿着
        self::assertNotNull($other);
        try {
            self::assertTrue($this->jobs->busy($id));
            $result = $this->worker()->runJob($id, 30);
            self::assertSame('busy', $result['result']);
            self::assertSame('queued', $result['job']['status'], '拿不到执行权只回进度');
            self::assertSame(['jobs' => 0, 'progress' => false, 'remaining' => true], $this->worker()->drain(30));
            self::assertSame(0, $this->rows('products'), '拿不到执行权的一个字都不写');
            self::assertSame('busy', $this->worker()->retry($id)['result']);
        } finally {
            $this->jobs->release($other);
        }
        self::assertFalse($this->jobs->busy($id), '对方退出（包括崩溃）后执行权自动放开');
        self::assertSame('ran', $this->worker()->runJob($id, 30)['result']);
        self::assertSame(3, $this->rows('products'));
    }

    /** @return list<array{0:string,1:int}> 第几次写进库之后「崩溃」 */
    public static function crashPoints(): array
    {
        return [
            'after the first category' => ['category', 1],
            'after the first media file' => ['media', 1],
            'after the first product' => ['product', 1],
            'after the second product' => ['product', 2],
        ];
    }

    /**
     * 写进库之后、存进度之前进程崩溃（这里用异常模拟：之后的存盘一次都没发生）。
     * 下一个执行者接着跑：按标记认领已写进库的行，产品、分类、媒体都不多一行；撤销也能把它们都删掉。
     * @dataProvider crashPoints
     */
    public function testACrashRightAfterADatabaseWriteDoesNotDuplicateRows(string $kind, int $nth): void
    {
        $id = $this->queue();
        $crashing = $this->worker();
        $seen = [];
        $crashing->onWrite(static function (string $written, int $rowId) use (&$seen, $kind, $nth): void {
            $seen[$written] = ($seen[$written] ?? 0) + 1;
            if ($written === $kind && $seen[$written] === $nth) throw new LogicException('simulated crash');
        });
        try {
            $crashing->runJob($id, 30);
            self::fail('the worker should have crashed');
        } catch (LogicException $e) {
            self::assertSame('simulated crash', $e->getMessage());
        }
        self::assertFalse($this->jobs->busy($id), '崩溃后执行权放开');
        self::assertSame([$id], $this->jobs->active(), '任务仍在排队，等下一个执行者');

        $this->worker()->drain(30);
        $job = $this->load($id);
        self::assertSame('done', $job['status']);
        self::assertSame([3, 2, 5], [$this->rows('products'), $this->rows('product_categories'), $this->rows('media')], '没有重复的产品、分类、媒体');
        self::assertSame([3, 2, 5], [count($job['created']['products']), count($job['created']['categories']), count($job['created']['media'])],
            '崩溃前写进库的行都认领进任务（撤销靠它）');
        self::assertSame(3, $job['counts']['created']);

        $this->worker()->undo($id);
        self::assertSame([0, 0, 0], [$this->rows('products'), $this->rows('product_categories'), $this->rows('media')], '撤销把认领回来的也删掉');
        self::assertSame('undone', $this->load($id)['status']);
    }

    /** 更新产品：原值先存盘再改库。改完库、存进度前崩溃，续跑后撤销仍恢复到这次导入之前 */
    public function testAnUpdateInterruptedAfterTheWriteCanStillBeUndone(): void
    {
        $first = $this->queue();
        $this->worker()->drain(30);
        $flat = (array) db()->fetchOne("SELECT * FROM products WHERE title = 'Flat washer DIN125'");

        $this->manifest([['key' => 'A1', 'title' => 'Flat washer DIN125 zinc', 'category' => 'washer-flat', 'cover' => 'flat.png', 'model' => 'DIN125-Zn']]);
        $second = $this->queue();
        self::assertSame('update', $this->load($second)['items'][0]['action']);
        $crashing = $this->worker();
        $crashing->onWrite(static function (string $kind): void {
            if ($kind === 'product') throw new LogicException('simulated crash');
        });
        try {
            $crashing->runJob($second, 30);
        } catch (LogicException) {
        }
        self::assertSame('Flat washer DIN125 zinc', db()->fetchColumn('SELECT title FROM products WHERE id = ?', [(int) $flat['id']]), '库已经改了');
        $saved = $this->load($second);
        self::assertSame('Flat washer DIN125', $saved['updated'][(int) $flat['id']]['before']['title'], '改库前原值已经存盘');
        self::assertSame('pending', $saved['items'][0]['status'], '进度还没存：这一行还是待做');
        $main = (string) file_get_contents($this->tmp . '/site/storage/product-import/jobs/' . $second . '.json');
        self::assertStringNotContainsString('"before"', $main, '原值存在单独的文件里，主文件每次存盘都很小');

        $this->worker()->drain(30);
        self::assertSame(['done', 1], [$this->load($second)['status'], $this->load($second)['counts']['updated']]);
        self::assertSame(3, $this->rows('products'));

        $this->worker()->undo($second);
        $after = (array) db()->fetchOne('SELECT * FROM products WHERE id = ?', [(int) $flat['id']]);
        self::assertSame(['Flat washer DIN125', 'DIN125'], [$after['title'], $after['model']], '撤销恢复到这次导入之前');
        self::assertSame([], $this->load($second)['undo_conflicts']);
        self::assertSame('done', $this->load($first)['status']);
    }

    public function testUndoWhileAnotherExecutorRunsIsQueuedAndDoneByTheOwner(): void
    {
        $id = $this->queue();
        $other = $this->jobs->lock($id);
        try {
            $result = $this->worker()->undo($id);
            self::assertSame('queued', $result['result'], '正在执行：先留撤销请求，不插进去写库');
            self::assertTrue($this->jobs->undoRequested($id));
        } finally {
            $this->jobs->release($other);
        }
        $this->worker()->drain(30);
        self::assertSame('undone', $this->load($id)['status']);
        self::assertFalse($this->jobs->undoRequested($id));
        self::assertSame([], $this->jobs->active());
        self::assertSame(0, $this->rows('products'));
    }

    /** 执行者写完一款后看到撤销请求：停在这里，不再写下一款，接着把已经写的撤掉 */
    public function testTheOwnerStopsBetweenRowsWhenUndoIsRequested(): void
    {
        $id = $this->queue();
        $worker = $this->worker();
        $products = 0;
        $worker->onWrite(function (string $kind) use (&$products, $id): void {
            if ($kind === 'product' && ++$products === 1) $this->jobs->requestUndo($id);
        });
        $result = $worker->runJob($id, 30);

        self::assertSame('undone', $result['result']);
        self::assertSame(1, $products, '看到请求后没有再写第二款');
        $job = $this->load($id);
        self::assertSame(['undone', []], [$job['status'], $job['undo_conflicts']]);
        self::assertSame([0, 0, 0], [$this->rows('products'), $this->rows('product_categories'), $this->rows('media')]);
    }

    /** 撤销中途断了（状态停在「撤销中」）：下一个执行者接着撤，已经恢复的不报冲突 */
    public function testAnInterruptedUndoIsFinishedWithoutFalseConflicts(): void
    {
        $this->queue();
        $this->worker()->drain(30);
        $this->manifest([['key' => 'A1', 'title' => 'Flat washer renamed', 'category' => 'washer-flat', 'cover' => 'flat.png']]);
        $id = $this->queue();
        $this->worker()->drain(30);
        $this->worker()->undo($id);
        self::assertSame('Flat washer DIN125', db()->fetchColumn("SELECT title FROM products WHERE title LIKE 'Flat%'"));

        // 模拟：撤销做完了库，却在存「已撤销」之前断了
        $job = $this->load($id);
        $job['status'] = 'undoing';
        $this->jobs->save($job);
        $this->jobs->markActive($id);
        $this->worker()->drain(30);
        $job = $this->load($id);
        self::assertSame(['undone', []], [$job['status'], $job['undo_conflicts']], '已恢复的产品不算冲突');
        self::assertSame('Flat washer DIN125', db()->fetchColumn("SELECT title FROM products WHERE title LIKE 'Flat%'"));
    }

    public function testFailedRowsAreRetriedInTheBackground(): void
    {
        $id = $this->queue();
        rename($this->tmp . '/package/images/lock.png', $this->tmp . '/lock.png');   // 预览之后图被挪走：执行时这一行失败
        $this->worker()->drain(30);
        $job = $this->load($id);
        self::assertSame(['done', 2, 1, 'image_not_found'], [$job['status'], $job['counts']['created'], $job['counts']['failed'], $job['items'][2]['message']]);
        self::assertSame([], $this->jobs->active());

        rename($this->tmp . '/lock.png', $this->tmp . '/package/images/lock.png');
        self::assertSame('queued', $this->worker()->retry($id)['result']);
        self::assertSame([$id], $this->jobs->active(), '重试交给后台');
        $this->worker()->drain(30);
        $job = $this->load($id);
        self::assertSame(['done', 3, 0], [$job['status'], $job['counts']['created'], $job['counts']['failed']]);
        self::assertSame(3, $job['verify']['checked'], '重试跑完重新检查');
        self::assertSame(3, $this->rows('products'), '成功过的行没有再写一遍');
        self::assertSame('nothing', $this->worker()->retry($id)['result']);
    }

    public function testAJobStopsWhenItsCreatorIsNoLongerAnActiveSuperAdmin(): void
    {
        $id = $this->queue();
        $result = $this->worker(static fn (int $adminId): bool => false)->runJob($id, 30);
        self::assertSame('blocked', $result['result']);
        $job = $this->load($id);
        self::assertSame(['blocked', 'admin_not_allowed'], [$job['status'], $job['blocked_reason']]);
        self::assertSame([0, []], [$this->rows('products'), $this->jobs->active()]);
        self::assertSame('undone', $this->worker()->undo($id)['result'], '停下的任务可以撤销');

        // 默认的判断：账号与角色都启用、角色含 *
        db()->execute("INSERT INTO roles (id, permissions, status) VALUES (1, '[\"*\"]', 1), (2, '[\"edit_product\",\"delete_product\",\"media\"]', 1), (3, '[\"*\"]', 0)");
        db()->execute('INSERT INTO users (id, role_id, status) VALUES (1, 1, 1), (2, 2, 1), (3, 1, 0), (4, 3, 1)');
        self::assertSame([true, false, false, false, false],
            array_map(static fn (int $id): bool => ProductImportWorker::isActiveSuperAdmin($id), [1, 2, 3, 4, 99]));
    }

    public function testTheJobStopsWhenThePackageIsGone(): void
    {
        $id = $this->queue();
        $job = $this->load($id);
        $job['package'] = $this->tmp . '/missing-package';
        $this->jobs->save($job);
        self::assertSame('blocked', $this->worker()->runJob($id, 30)['result']);
        self::assertSame('package_gone', $this->load($id)['blocked_reason']);
    }

    /**
     * 升级前的任务（没有 v，原值在主文件里）：不会因为后台执行器扫到就自己跑起来；看与撤销照旧；
     * 站长点「继续」后才交给后台，中断那一批里已建好、没记进任务的产品按导入键认领，不重复。
     */
    public function testLegacyJobsAreViewedAndUndoneAsBeforeAndOnlyResumeOnRequest(): void
    {
        $id = $this->queue();
        $this->worker()->drain(30);
        // 改写成升级前的格式：没有 v、原值在主文件、状态 done
        $job = $this->load($id);
        unset($job['v'], $job['categories_done'], $job['updated_split'], $job['updated_ids']);
        $job['status'] = 'done';
        $legacyFile = $this->tmp . '/site/storage/product-import/jobs/' . $id . '.json';
        file_put_contents($legacyFile, json_encode($job));
        self::assertSame('done', $this->jobs->load($id, false)['status'], '升级前的任务照样能看');
        self::assertSame('undone', $this->worker()->undo($id)['result'], '照样能撤销');
        self::assertSame(0, $this->rows('products'));
        self::assertArrayNotHasKey('v', $this->load($id), '撤销后仍保持原来的格式');

        // 升级前没跑完的任务：第一批（两款）已写进库并存盘；第二批写进库后页面关了，没存
        $legacy = $this->queue();
        $this->jobs->clearActive($legacy);
        $job = $this->load($legacy);
        $runner = new ProductImportRunner(ProductImportPackage::fromDirectory($this->tmp . '/package', $this->tmp . '/site'), $this->tmp . '/site');
        $runner->run($job, 2);
        unset($job['v'], $job['categories_done']);
        file_put_contents($this->tmp . '/site/storage/product-import/jobs/' . $legacy . '.json', json_encode($job));
        $lost = $job;
        $runner->run($lost, 1);   // 写进库，但这份结果没存
        self::assertSame(3, $this->rows('products'));

        $this->jobs->markActive($legacy);   // 就算有排队标记，后台也不碰升级前的任务
        self::assertSame('legacy', $this->worker()->runJob($legacy, 30)['result']);
        self::assertSame(['running', 3], [$this->load($legacy)['status'], $this->rows('products')]);
        self::assertSame([], $this->jobs->active());

        self::assertSame('queued', $this->worker()->resumeLegacy($legacy)['result']);
        $this->worker()->drain(30);
        $job = $this->load($legacy);
        self::assertSame(['done', 3, 3], [$job['status'], $job['counts']['created'], $this->rows('products')], '中断那一批新建的产品认领回来，没有重复');
        self::assertCount(3, $job['created']['products']);
        $this->worker()->undo($legacy);
        self::assertSame(0, $this->rows('products'));
    }
}
