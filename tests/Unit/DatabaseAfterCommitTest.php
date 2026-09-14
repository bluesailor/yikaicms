<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** E06：副作用（页面缓存失效）只在事务提交后执行，回滚丢弃。 */
final class DatabaseAfterCommitTest extends TestCase
{
    protected function tearDown(): void
    {
        if (db()->getPdo()->inTransaction()) {
            db()->rollback();
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
