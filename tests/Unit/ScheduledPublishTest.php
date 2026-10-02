<?php
/**
 * 定时发布 / 定时上架（2.0.3）：到点由 sweep() 上线。
 * 2.0.4 起保存规则以用户选的状态为准：已发布即上线（未来时间只作显示日期），定时才看时间。
 * 产品的上架时间存在 metas（不给 products 加列，整站模板包按表结构精确比对）。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/ScheduledPublish.php';

final class ScheduledPublishTest extends TestCase
{
    private const NOW = 1_800_000_000;
    private const TABLES = ['contents', 'products', 'metas'];

    public function testScheduledNeedsATimeAndAPastTimePublishesDirectly(): void
    {
        $future = date('Y-m-d\TH:i', self::NOW + 3600);
        $past = date('Y-m-d\TH:i', self::NOW - 3600);

        self::assertSame(['status' => 3, 'publish_time' => strtotime($future)], ScheduledPublish::normalize(3, $future, 0, self::NOW));
        self::assertSame(['status' => 1, 'publish_time' => strtotime($past)], ScheduledPublish::normalize(3, $past, 0, self::NOW));

        $this->expectException(InvalidArgumentException::class);
        ScheduledPublish::normalize(3, '', 0, self::NOW);
    }

    /** 客户预期：选「已发布」就是马上上线，未来时间只是文章显示的日期，不替用户改成定时 */
    public function testPublishedWithAFutureTimeStaysPublished(): void
    {
        $future = date('Y-m-d\TH:i', self::NOW + 86400);
        self::assertSame(['status' => 1, 'publish_time' => strtotime($future)], ScheduledPublish::normalize(1, $future, 0, self::NOW));
        self::assertSame(['status' => 1, 'publish_time' => strtotime($future)], ScheduledPublish::normalize(1, $future, 1_700_000_000, self::NOW));
    }

    /** 三个编辑页（文章、产品、通用内容）共用这一条规则，不各写一份 */
    public function testEveryEditorUsesTheSharedRule(): void
    {
        foreach (['admin/article_edit.php', 'admin/product_edit.php', 'admin/content_edit.php'] as $file) {
            $src = (string) file_get_contents(ROOT_PATH . '/' . $file);
            self::assertStringContainsString('ScheduledPublish::normalize(', $src, $file);
            self::assertDoesNotMatchRegularExpression("/\\\$data\\['status'\\]\\s*=\\s*3\\s*;/", $src, "{$file} 不应自己把状态改成定时");
        }
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
        $this->withIsolatedTables(function (): void {
            db()->execute('CREATE TABLE contents (id INTEGER PRIMARY KEY, status INTEGER, publish_time INTEGER, updated_at INTEGER)');
            db()->execute('CREATE TABLE products (id INTEGER PRIMARY KEY, status INTEGER, updated_at INTEGER)');
            db()->execute('CREATE TABLE metas (id INTEGER PRIMARY KEY, owner_type TEXT, owner_id INTEGER, meta_key TEXT, meta_value TEXT, created_at INTEGER, updated_at INTEGER)');
            db()->execute('INSERT INTO contents (id, status, publish_time, updated_at) VALUES
                (1, 3, ' . (self::NOW - 10) . ', 0), (2, 3, ' . (self::NOW + 10) . ', 0), (3, 0, ' . (self::NOW - 10) . ', 0), (4, 3, 0, 0)');
            db()->execute('INSERT INTO products (id, status, updated_at) VALUES (1, 3, 0), (2, 3, 0), (3, 0, 0), (4, 3, 0)');
            db()->execute("INSERT INTO metas (owner_type, owner_id, meta_key, meta_value) VALUES
                ('product', 1, 'publish_time', '" . (self::NOW - 10) . "'),
                ('product', 2, 'publish_time', '" . (self::NOW + 10) . "'),
                ('product', 3, 'publish_time', '" . (self::NOW - 10) . "'),
                ('content', 4, 'publish_time', '" . (self::NOW - 10) . "')");

            self::assertSame(2, ScheduledPublish::sweep(self::NOW));

            foreach (['contents', 'products'] as $table) {
                $status = array_column(db()->fetchAll("SELECT id, status FROM {$table} ORDER BY id"), 'status', 'id');
                self::assertSame([1 => 1, 2 => 3, 3 => 0, 4 => 3], array_map('intval', $status), $table);
            }
            self::assertSame(0, ScheduledPublish::sweep(self::NOW));
            self::assertSame([2 => self::NOW + 10], ScheduledPublish::productTimes([2, 4]));
        });
    }

    /** 共用的内存库里别的测试已建过这些表：先挪开，测完原样放回。 */
    private function withIsolatedTables(callable $test): void
    {
        $moved = [];
        foreach (self::TABLES as $table) {
            if (db()->fetchOne("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])) {
                db()->execute("ALTER TABLE {$table} RENAME TO {$table}__sched_backup");
                $moved[] = $table;
            }
        }
        try {
            $test();
        } finally {
            foreach (self::TABLES as $table) {
                db()->execute("DROP TABLE IF EXISTS {$table}");
            }
            foreach ($moved as $table) {
                db()->execute("ALTER TABLE {$table}__sched_backup RENAME TO {$table}");
            }
        }
    }
}
