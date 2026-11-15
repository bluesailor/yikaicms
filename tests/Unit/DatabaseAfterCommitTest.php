<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** E06：副作用（页面缓存失效）只在事务提交后执行，回滚丢弃。 */
final class DatabaseAfterCommitTest extends TestCase
{
    private string $cacheDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT_PATH . '/includes/HtmlCache.php';
        $this->cacheDir = sys_get_temp_dir() . '/yk_after_commit_' . bin2hex(random_bytes(6));
        mkdir($this->cacheDir, 0700, true);
        HtmlCache::setDir($this->cacheDir);
    }

    protected function tearDown(): void
    {
        if (db()->getPdo()->inTransaction()) {
            db()->rollback();
        }
        HtmlCache::setDir(null);
        if ($this->cacheDir !== '' && is_dir($this->cacheDir)) {
            foreach (glob($this->cacheDir . '/*.html') ?: [] as $file) unlink($file);
            rmdir($this->cacheDir);
        }
        parent::tearDown();
    }

    public function testCallbackRunsImmediatelyOutsideTransaction(): void
    {
        $calls = 0;
        db()->afterCommit(static function () use (&$calls): void { $calls++; });
        self::assertSame(1, $calls);
    }

    public function testCallbackWaitsForCommitAndIsDroppedOnRollback(): void
    {
        $committed = 0;
        $rolledBack = 0;
        db()->beginTransaction();
        db()->afterCommit(static function () use (&$committed): void { $committed++; }, static function () use (&$rolledBack): void { $rolledBack++; });
        self::assertSame(0, $committed, 'must not run before commit');
        db()->commit();
        self::assertSame(1, $committed);
        self::assertSame(0, $rolledBack);

        db()->beginTransaction();
        db()->afterCommit(static function () use (&$committed): void { $committed++; }, static function () use (&$rolledBack): void { $rolledBack++; });
        db()->rollback();
        self::assertSame(1, $committed, 'rolled back work must not trigger side effects');
        self::assertSame(1, $rolledBack);

        // 回滚后队列已清空，下一次提交不重放旧回调。
        db()->beginTransaction();
        db()->commit();
        self::assertSame(1, $committed);
    }

    /** 评审 F07：提交之后的副作用出错，不能让调用方以为写库失败，也不能被「没有活动事务」盖掉 */
    public function testSideEffectFailureAfterCommitDoesNotMaskTheCommittedWrite(): void
    {
        db()->execute('CREATE TABLE IF NOT EXISTS f07_probe (id INTEGER PRIMARY KEY, v TEXT)');
        db()->execute('DELETE FROM f07_probe');
        $ran = [];
        db()->beginTransaction();
        try {
            db()->execute("INSERT INTO f07_probe (v) VALUES ('saved')");
            db()->afterCommit(static function (): void { throw new RuntimeException('cache generation write failed'); });
            db()->afterCommit(static function () use (&$ran): void { $ran[] = 'second'; });
            self::assertTrue(db()->commit(), '数据已提交：commit 报成功');
        } catch (Throwable $e) {
            db()->rollback();
            self::fail('提交后的副作用异常不该抛给写库的调用方：' . $e->getMessage());
        }
        self::assertSame(['second'], $ran, '一个回调失败不影响其余的');
        self::assertCount(1, db()->afterCommitFailures(), '失败仍要让关心它的调用方知道（AI 提案标为已应用 + post_commit_error）');
        self::assertSame('cache generation write failed', db()->afterCommitFailures()[0]->getMessage());
        db()->beginTransaction();
        db()->commit();
        self::assertSame([], db()->afterCommitFailures(), '下一次提交重新计');
        self::assertSame('saved', db()->fetchColumn('SELECT v FROM f07_probe'));
        self::assertFalse(db()->getPdo()->inTransaction());
    }

    public function testRollbackWithoutAnActiveTransactionKeepsTheOriginalError(): void
    {
        db()->beginTransaction();
        db()->commit();
        $rolledBack = 0;
        self::assertFalse(db()->rollback(), '没有活动事务：什么都不做，不抛异常');
        try {
            db()->beginTransaction();
            db()->afterCommit(static fn () => null, static function () use (&$rolledBack): void { $rolledBack++; });
            throw new DomainException('original');
        } catch (DomainException $e) {
            db()->rollback();
            db()->rollback();   // 外层 catch 再回滚一次也不该盖掉原错误
            self::assertSame('original', $e->getMessage());
        }
        self::assertSame(1, $rolledBack);
    }

    public function testFailedGenerationRotationStillDeletesCachedFiles(): void
    {
        $file = $this->cacheDir . '/f07-probe.html';
        file_put_contents($file, 'cached');
        // 有表但缺列：换代写入会失败。别的用例建的 settings 表先挪开，结束后原样放回
        $hadSettings = db()->tableExists('settings');
        if ($hadSettings) db()->execute('ALTER TABLE settings RENAME TO settings_f07_saved');
        db()->execute('CREATE TABLE settings (id INTEGER PRIMARY KEY)');
        if (function_exists('settingModel')) settingModel()->clearCache();
        try {
            HtmlCache::invalidate();
            self::fail('换代失败要让调用方知道');
        } catch (\PHPUnit\Framework\AssertionFailedError $e) {
            throw $e;
        } catch (Throwable) {
            self::assertFileDoesNotExist($file, '换代失败时仍删掉旧缓存文件');
        } finally {
            db()->execute('DROP TABLE settings');
            if ($hadSettings) db()->execute('ALTER TABLE settings_f07_saved RENAME TO settings');
            if (function_exists('settingModel')) settingModel()->clearCache();
        }
    }

    public function testPageCacheInvalidationIsCoalescedUntilCommit(): void
    {
        require_once ROOT_PATH . '/includes/HtmlCache.php';
        $dir = (new ReflectionMethod(HtmlCache::class, 'dir'))->invoke(null);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/e06-after-commit-probe.html';
        file_put_contents($file, 'cached');

        db()->beginTransaction();
        HtmlCache::invalidateAfterCommit();
        HtmlCache::invalidateAfterCommit();
        self::assertFileExists($file, 'cache must survive until the transaction commits');
        db()->commit();
        self::assertFileDoesNotExist($file);

        file_put_contents($file, 'cached');
        db()->beginTransaction();
        HtmlCache::invalidateAfterCommit();
        db()->rollback();
        self::assertFileExists($file, 'rollback leaves cache untouched');
        // 回滚后标记已复位，事务外调用立即生效。
        HtmlCache::invalidateAfterCommit();
        self::assertFileDoesNotExist($file);
    }
}
