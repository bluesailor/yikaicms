<?php
/**
 * 定时发布 / 定时上架（2.0.3）：保存规则以发布时间为准，到点由 sweep() 上线并通知缓存失效。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ScheduledPublish.php';

final class ScheduledPublishTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testScheduledNeedsATimeAndAPastTimePublishesDirectly(): void
    {
        $future = date('Y-m-d\TH:i', self::NOW + 3600);
        $past = date('Y-m-d\TH:i', self::NOW - 3600);

        self::assertSame(['status' => 3, 'publish_time' => strtotime($future)], ScheduledPublish::normalize(3, $future, 0, self::NOW));
        self::assertSame(['status' => 1, 'publish_time' => strtotime($past)], ScheduledPublish::normalize(3, $past, 0, self::NOW));

        $this->expectException(InvalidArgumentException::class);
        ScheduledPublish::normalize(3, '', 0, self::NOW);
    }

    public function testPublishedWithAFutureTimeBecomesScheduled(): void
    {
        $future = date('Y-m-d\TH:i', self::NOW + 86400);
        self::assertSame(['status' => 3, 'publish_time' => strtotime($future)], ScheduledPublish::normalize(1, $future, 0, self::NOW));
    }

    public function testPublishedWithoutATimeKeepsTheOldOneOrUsesNow(): void
    {
        self::assertSame(['status' => 1, 'publish_time' => self::NOW], ScheduledPublish::normalize(1, '', 0, self::NOW));
        self::assertSame(['status' => 1, 'publish_time' => 1_700_000_000], ScheduledPublish::normalize(1, '', 1_700_000_000, self::NOW));
    }

    public function testDraftKeepsItsTime(): void
    {
        $future = date('Y-m-d\TH:i', self::NOW + 3600);
        self::assertSame(['status' => 0, 'publish_time' => strtotime($future)], ScheduledPublish::normalize(0, $future, 0, self::NOW));
        self::assertSame(['status' => 0, 'publish_time' => 123], ScheduledPublish::normalize(0, '', 123, self::NOW));
    }

    public function testSweepPublishesDueArticlesAndProductsOnly(): void
    {
        foreach (['contents', 'products'] as $table) {
            db()->execute("CREATE TABLE IF NOT EXISTS {$table} (id INTEGER PRIMARY KEY, status INTEGER, publish_time INTEGER, updated_at INTEGER)");
            db()->execute("DELETE FROM {$table}");
            db()->execute("INSERT INTO {$table} (id, status, publish_time, updated_at) VALUES
                (1, 3, " . (self::NOW - 10) . ", 0),
                (2, 3, " . (self::NOW + 10) . ", 0),
                (3, 0, " . (self::NOW - 10) . ", 0),
                (4, 3, 0, 0)");
        }

        self::assertSame(2, ScheduledPublish::sweep(self::NOW));

        foreach (['contents', 'products'] as $table) {
            $status = array_column(db()->fetchAll("SELECT id, status FROM {$table} ORDER BY id"), 'status', 'id');
            self::assertSame([1 => 1, 2 => 3, 3 => 0, 4 => 3], array_map('intval', $status), $table);
        }
        self::assertSame(0, ScheduledPublish::sweep(self::NOW));
    }

    public function testSweepSkipsAProductsTableWithoutTheNewColumn(): void
    {
        db()->execute('DROP TABLE IF EXISTS products');
        db()->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, status INTEGER, updated_at INTEGER)');
        db()->execute('CREATE TABLE IF NOT EXISTS contents (id INTEGER PRIMARY KEY, status INTEGER, publish_time INTEGER, updated_at INTEGER)');
        db()->execute('DELETE FROM contents');
        db()->execute('INSERT INTO contents (id, status, publish_time, updated_at) VALUES (1, 3, ' . (self::NOW - 1) . ', 0)');

        self::assertSame(1, ScheduledPublish::sweep(self::NOW));
        db()->execute('DROP TABLE products');
    }
}
