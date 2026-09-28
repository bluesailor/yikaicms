<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ROOT_PATH')) define('ROOT_PATH', dirname(__DIR__, 2));
require_once ROOT_PATH . '/includes/Migrator.php';

/**
 * v2.0.3：数据库升级执行锁。后台「数据库升级」与 CLI migrate:run 共用一把系统文件锁；
 * 另一处在跑时控制台横幅与升级页显示「升级运行中」且不可点击，执行请求被拒绝。
 */
final class MigratorRunLockTest extends TestCase
{
    private string $lockFile;
    private string $infoFile;

    protected function setUp(): void
    {
        $this->lockFile = ROOT_PATH . '/storage/migrations.lock';
        $this->infoFile = ROOT_PATH . '/storage/migrations.running.json';
        Migrator::endRun();
    }

    protected function tearDown(): void
    {
        Migrator::endRun();
        @unlink($this->infoFile);
        @unlink($this->lockFile);
    }

    public function testOwnRunIsNotReportedAsSomeoneElses(): void
    {
        self::assertTrue(Migrator::beginRun('admin'));
        self::assertNull(Migrator::runningInfo(), 'the holder itself is not "busy elsewhere"');
        self::assertTrue(Migrator::beginRun('admin'), 're-entrant within one request');
        Migrator::endRun();
        self::assertNull(Migrator::runningInfo());
        self::assertFileDoesNotExist($this->infoFile);
    }

    public function testAnotherHolderBlocksAndIsReported(): void
    {
        // 另一个进程持锁：用独立的文件句柄模拟（flock 按打开的文件计，同进程的第二个句柄同样互斥）
        $other = fopen($this->lockFile, 'c');
        self::assertNotFalse($other);
        self::assertTrue(flock($other, LOCK_EX | LOCK_NB));
        file_put_contents($this->infoFile, json_encode(['started_at' => 1790000000, 'source' => 'cli']));

        self::assertSame(['started_at' => 1790000000, 'source' => 'cli'], Migrator::runningInfo());
        self::assertFalse(Migrator::beginRun('admin'), 'a second run is refused while one is in progress');

        // 持锁方结束（或进程退出，系统同样会释放）后恢复正常
        flock($other, LOCK_UN);
        fclose($other);
        self::assertNull(Migrator::runningInfo());
        self::assertTrue(Migrator::beginRun('admin'));
    }

    /**
     * 跑完迁移（成功、部分失败，或存储不可写没拿到锁）都让「待升级数量」缓存失效：
     * 回到控制台立即重新探测，不会再显示升级前的数量（原先非 0 结果缓存 60 秒）。
     */
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testEndingARunInvalidatesThePendingCountCache(): void
    {
        if (!function_exists('cacheDelete')) {
            eval('function cacheDelete(string $key): void { $GLOBALS["_deleted_cache_keys"][] = $key; }');
        }
        $GLOBALS['_deleted_cache_keys'] = [];
        self::assertTrue(Migrator::beginRun('admin'));
        Migrator::endRun();
        self::assertSame(['sidebar_pending_migrations'], $GLOBALS['_deleted_cache_keys']);

        // 没持有锁时（beginRun 因存储不可写直接放行）也要清
        $GLOBALS['_deleted_cache_keys'] = [];
        Migrator::endRun();
        self::assertSame(['sidebar_pending_migrations'], $GLOBALS['_deleted_cache_keys']);
    }

    public function testDashboardBannerAndSidebarBadgeShareOneCheck(): void
    {
        $header = (string) file_get_contents(ROOT_PATH . '/admin/includes/header.php');
        self::assertStringContainsString("\$__pendingMig = hasPermission('*') && (\$currentMenu ?? '') !== 'upgrade' ? \$__sidebarPendingMigrations : 0;", $header);
        self::assertStringNotContainsString('migrations_ok_version', $header, 'no second, separately cached state that can drift from the badge');
    }

    public function testEveryEntryPointUsesTheLock(): void
    {
        $upgrade = (string) file_get_contents(ROOT_PATH . '/admin/upgrade.php');
        $run = strpos($upgrade, "if (!Migrator::beginRun('admin')) {");
        self::assertIsInt($run, 'the admin run request takes the lock');
        self::assertLessThan(strpos($upgrade, '$runIds = (array)$_POST[\'run\'];'), $run, 'before any migration runs');
        $post = strpos($upgrade, "if (\$_SERVER['REQUEST_METHOD'] === 'POST' && !empty(\$_POST['run'])) {");
        self::assertIsInt($post);
        self::assertStringContainsString('verifyCsrf();', substr($upgrade, $post, 200), 'running migrations changes the schema: token required');
        self::assertStringContainsString("formData.append('_token', '<?php echo csrfToken(); ?>');", $upgrade);
        self::assertStringContainsString("'running' => true, 'msg' => __('mig_running_busy')", $upgrade);
        self::assertStringContainsString('Migrator::endRun();', $upgrade);
        self::assertStringContainsString('data-testid="mig-running"', $upgrade, 'upgrade page shows the running state');
        self::assertStringContainsString("<?php echo \$migRunning !== null ? ' disabled' : ''; ?>", $upgrade, 'and disables its button');

        $header = (string) file_get_contents(ROOT_PATH . '/admin/includes/header.php');
        self::assertStringContainsString('$__migRunning = Migrator::runningInfo();', $header);
        self::assertStringContainsString('data-testid="mig-running-banner"', $header);
        self::assertStringContainsString('aria-disabled="true"', $header, 'dashboard banner button is not clickable while running');

        $cli = (string) file_get_contents(ROOT_PATH . '/includes/commands/migrate.php');
        self::assertStringContainsString("if (!Migrator::beginRun('cli')) {", $cli);

        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            foreach (['mig_running_title', 'mig_running_desc', 'mig_running_button', 'mig_running_busy'] as $key) {
                self::assertNotSame('', trim((string) ($strings[$key] ?? '')), "$lang $key");
            }
            self::assertStringContainsString(':time', (string) $strings['mig_running_desc'], $lang);
        }
    }
}
