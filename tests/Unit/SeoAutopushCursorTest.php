<?php

declare(strict_types=1);

namespace {
    require_once ROOT_PATH . '/plugins/seo/autopush.php';
}

namespace Yikai\Tests\Unit {
    use PHPUnit\Framework\TestCase;

    final class SeoAutopushCursorTest extends TestCase
    {
        protected function setUp(): void
        {
            db()->execute('DROP TABLE IF EXISTS contents');
            db()->execute('DROP TABLE IF EXISTS channels');
            db()->execute('CREATE TABLE channels (id INTEGER PRIMARY KEY, slug TEXT, type TEXT)');
            db()->execute('CREATE TABLE contents (
                id INTEGER PRIMARY KEY,
                slug TEXT,
                type TEXT,
                channel_id INTEGER,
                updated_at TEXT,
                created_at TEXT,
                publish_time TEXT,
                status INTEGER,
                deleted_at TEXT NULL
            )');
            db()->execute("INSERT INTO channels (id, slug, type) VALUES (1, 'news', 'list')");
        }

        protected function tearDown(): void
        {
            db()->execute('DROP TABLE IF EXISTS contents');
            db()->execute('DROP TABLE IF EXISTS channels');
        }

        public function testCompositeCursorDoesNotSkipMoreThanOneBatchInSameSecond(): void
        {
            $stamp = '2026-08-23 02:00:00';
            for ($id = 1; $id <= 55; $id++) {
                db()->execute(
                    'INSERT INTO contents
                     (id, slug, type, channel_id, updated_at, created_at, publish_time, status, deleted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 1, NULL)',
                    [$id, 'item-' . $id, 'article', 1, $stamp, $stamp, $stamp]
                );
            }

            $start = ['ts' => strtotime($stamp) ?: 0, 'id' => 0];
            [$firstRows, $firstCursor] = seo_autopush_rows($start, 50);
            [$secondRows, $secondCursor] = seo_autopush_rows($firstCursor, 50);

            self::assertCount(50, $firstRows);
            self::assertSame(['ts' => $start['ts'], 'id' => 50], $firstCursor);
            self::assertCount(5, $secondRows);
            self::assertSame(['ts' => $start['ts'], 'id' => 55], $secondCursor);
            self::assertSame(range(1, 55), array_map(
                static fn(array $row): int => (int) $row['id'],
                array_merge($firstRows, $secondRows)
            ));
        }

        public function testEffectiveTimestampUsesNewestOfAllThreeColumns(): void
        {
            db()->execute(
                'INSERT INTO contents
                 (id, slug, type, channel_id, updated_at, created_at, publish_time, status, deleted_at)
                 VALUES (1, ?, ?, 1, ?, ?, ?, 1, NULL)',
                ['future-publish', 'article', '2026-08-20 00:00:00', '2026-08-20 00:00:00', '2026-08-23 03:00:00']
            );
            db()->execute(
                'INSERT INTO contents
                 (id, slug, type, channel_id, updated_at, created_at, publish_time, status, deleted_at)
                 VALUES (2, ?, ?, 1, ?, ?, ?, 1, NULL)',
                ['normal-update', 'article', '2026-08-23 02:30:00', '2026-08-20 00:00:00', '2026-08-20 00:00:00']
            );

            [$rows, $cursor] = seo_autopush_rows(['ts' => strtotime('2026-08-23 02:00:00') ?: 0, 'id' => 0]);

            self::assertSame([2, 1], array_map(static fn(array $row): int => (int) $row['id'], $rows));
            self::assertSame(strtotime('2026-08-23 03:00:00'), $cursor['ts']);
            self::assertSame(1, $cursor['id']);
        }

        public function testLegacyNumericCursorDecodesWithoutLosingCompatibility(): void
        {
            self::assertSame(['ts' => 1234, 'id' => 0], seo_autopush_cursor_decode('1234'));
            self::assertSame(
                ['ts' => 1234, 'id' => 9],
                seo_autopush_cursor_decode(seo_autopush_cursor_encode(['ts' => 1234, 'id' => 9]))
            );
        }
    }
}
