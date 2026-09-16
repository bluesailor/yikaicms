<?php
/** Concurrent save protection for channel-owned Blox documents (E03). */

declare(strict_types=1);

namespace {
    if (!function_exists('cacheClear')) {
        function cacheClear(): void {}
    }
    if (!function_exists('do_action')) {
        function do_action(string $hook, mixed ...$args): void {}
    }
}

namespace Yikai\Tests\Unit {
    use Yikai\Tests\TestCase;

    final class BloxDocumentWriteLockTest extends TestCase
    {
        // 规范化会丢弃未知设置键，竞争草稿必须在结构上真正不同。
        private const RIVAL = '{"schema":1,"settings":{},"sections":[{"id":"s_rival","settings":{},"columns":[{"id":"c_rival","elements":[]}]}]}';

        public static function setUpBeforeClass(): void
        {
            require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        }

        protected function schemaSql(): array
        {
            return [
                'CREATE TABLE channels (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    type TEXT NOT NULL,
                    parent_id INTEGER NOT NULL DEFAULT 0,
                    lang TEXT NOT NULL,
                    name TEXT NOT NULL,
                    slug TEXT NOT NULL DEFAULT \'\',
                    description TEXT,
                    content TEXT,
                    updated_at INTEGER NOT NULL DEFAULT 0
                )',
                'CREATE TABLE contents (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    channel_id INTEGER NOT NULL,
                    lang TEXT NOT NULL DEFAULT \'zh-CN\',
                    publish_time INTEGER NOT NULL DEFAULT 0,
                    status INTEGER NOT NULL DEFAULT 1,
                    deleted_at INTEGER,
                    is_top INTEGER NOT NULL DEFAULT 0,
                    content_type TEXT NOT NULL DEFAULT \'html\',
                    content TEXT,
                    blocks_data TEXT
                )',
                'CREATE TABLE blox_page_drafts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    page_id INTEGER NOT NULL UNIQUE,
                    draft_data TEXT NOT NULL,
                    published_data TEXT,
                    admin_id INTEGER NOT NULL DEFAULT 0,
                    created_at INTEGER NOT NULL DEFAULT 0,
                    updated_at INTEGER NOT NULL DEFAULT 0,
                    published_at INTEGER NOT NULL DEFAULT 0
                )',
            ];
        }

        protected function tearDown(): void
        {
            $this->resetDatabase();
            parent::tearDown();
        }

        /**
         * 模拟另一个保存恰好在本次校验之后、取得锁之前提交：SQLite 下取锁的无害 UPDATE 触发写入竞争草稿。
         * 失败回滚会连同触发器写入一起撤销，所以断言“本次内容未落库”而不是“竞争内容仍在”。
         */
        private function rivalCommitsBeforeLock(): void
        {
            db()->getPdo()->exec(
                'CREATE TRIGGER rival_save AFTER UPDATE ON channels BEGIN '
                . 'INSERT OR REPLACE INTO blox_page_drafts (page_id, draft_data, admin_id) '
                . "VALUES (NEW.id, '" . self::RIVAL . "', 9); END"
            );
        }

        private function assertConflict(callable $save): void
        {
            try {
                $save();
                self::fail('A save based on a superseded document was accepted');
            } catch (\RuntimeException $error) {
                self::assertSame(__('blox_save_conflict'), $error->getMessage());
            }
            self::assertFalse(db()->getPdo()->inTransaction());
        }

        public function testFirstPageDraftLosesToConcurrentFirstDraft(): void
        {
            $pageId = $this->insertRow('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Race', 'content' => '']);
            $state = \PageBloxDocument::load($pageId);
            $this->rivalCommitsBeforeLock();

            $this->assertConflict(static fn() => \PageBloxDocument::saveDraft(
                $pageId, '{"schema":1,"settings":{"mine":true},"sections":[]}', $state['base_revision']
            ));
            self::assertNull(bloxPageDraftModel()->findByPageId($pageId));
        }

        public function testExistingPageDraftIsNotOverwrittenByStaleSave(): void
        {
            $pageId = $this->insertRow('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Race', 'content' => '']);
            $state = \PageBloxDocument::load($pageId);
            $first = \PageBloxDocument::saveDraft($pageId, '{"schema":1,"settings":{"first":true},"sections":[]}', $state['base_revision']);
            $this->rivalCommitsBeforeLock();

            $this->assertConflict(static fn() => \PageBloxDocument::saveDraft(
                $pageId, '{"schema":1,"settings":{"mine":true},"sections":[]}', $first['base_revision']
            ));
            self::assertSame($first['base_revision'], \PageBloxDocument::load($pageId)['base_revision']);
        }

        public function testChannelDraftAndPublishRejectConcurrentWrites(): void
        {
            $channelId = $this->insertRow('channels', ['type' => 'list', 'parent_id' => 0, 'lang' => 'en', 'name' => 'News', 'description' => '']);
            $state = \ChannelBloxDocument::load($channelId);
            $this->rivalCommitsBeforeLock();

            $this->assertConflict(static fn() => \ChannelBloxDocument::saveDraft($channelId, $state['document_json'], $state['base_revision']));
            $this->assertConflict(static fn() => \ChannelBloxDocument::saveAndPublish($channelId, $state['document_json'], $state['base_revision']));
            self::assertNull(\ChannelBloxDocument::publishedJson($channelId));
            self::assertNull(bloxPageDraftModel()->findByPageId($channelId));
        }

        public function testHomeDraftAndPublishRejectConcurrentWrites(): void
        {
            $GLOBALS['_test_config'] = [];
            db()->getPdo()->exec(
                'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
                . '"key" TEXT UNIQUE, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', '
                . 'tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)'
            );
            // 锚点行上的无害 UPDATE 触发另一方先提交首页草稿。
            db()->getPdo()->exec(
                'CREATE TRIGGER rival_home AFTER UPDATE ON settings WHEN NEW."key" = \'home_blox_active\' BEGIN '
                . 'INSERT OR REPLACE INTO settings ("key", value, "group") VALUES (\'home_blox_data\', \'' . self::RIVAL . '\', \'home\'); END'
            );
            $mine = '{"schema":1,"settings":{},"sections":[]}';

            $this->assertConflict(static fn() => \HomeBloxDocument::saveDraft($mine));
            $this->assertConflict(static fn() => \HomeBloxDocument::saveAndPublish($mine));
            self::assertNull(db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['home_blox_data']) ?: null);
            self::assertNull(db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['home_blox_published']) ?: null);

            db()->getPdo()->exec('DROP TRIGGER rival_home');
            $published = \HomeBloxDocument::saveAndPublish($mine);
            self::assertTrue($published['active']);
            self::assertFalse(db()->getPdo()->inTransaction());
        }

        public function testUncontendedSavesStillSucceed(): void
        {
            $channelId = $this->insertRow('channels', ['type' => 'list', 'parent_id' => 0, 'lang' => 'en', 'name' => 'News', 'description' => '']);
            $state = \ChannelBloxDocument::load($channelId);
            $draft = \ChannelBloxDocument::saveDraft($channelId, $state['document_json'], $state['base_revision']);
            $published = \ChannelBloxDocument::saveAndPublish($channelId, $state['document_json'], $draft['base_revision']);
            self::assertTrue($published['published']);
            self::assertFalse(db()->getPdo()->inTransaction());
        }
    }
}
