<?php
/** Data-backed channel draft and publication behavior. */

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

    final class ChannelBloxDocumentTest extends TestCase
    {
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
                    description TEXT
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

        public function testNewsLayoutPublishesWithoutUsingArticleRecords(): void
        {
            $channelId = $this->insertRow('channels', [
                'type' => 'list',
                'parent_id' => 0,
                'lang' => 'zh-CN',
                'name' => 'News',
                'description' => '',
            ]);

            $initial = \ChannelBloxDocument::load($channelId);
            $document = \BloxDocumentPipeline::decode($initial['document_json']);
            $publishedDocument = \BloxDocumentPipeline::decode($initial['published_document_json']);
            self::assertFalse($initial['has_draft']);
            self::assertFalse($initial['has_published']);
            self::assertSame('content-catalog', $document['sections'][1]['columns'][0]['elements'][0]['type']);
            self::assertSame($document['sections'], $publishedDocument['sections']);

            $draft = \ChannelBloxDocument::saveDraft(
                $channelId,
                $initial['document_json'],
                $initial['base_revision'],
                2
            );
            self::assertTrue($draft['has_unpublished_changes']);

            $published = \ChannelBloxDocument::saveAndPublish(
                $channelId,
                $initial['document_json'],
                $draft['base_revision'],
                2
            );
            self::assertTrue($published['published']);
            self::assertNotNull(\ChannelBloxDocument::publishedJson($channelId));
            self::assertFalse(db()->tableExists('contents'));
        }
    }
}
