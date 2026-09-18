<?php
/** E06: design token and named style usage covers every stored Blox document. */

declare(strict_types=1);

namespace Yikai\Tests\Unit {
    use Yikai\Tests\TestCase;

    final class BloxDesignDependenciesUsageTest extends TestCase
    {
        public static function setUpBeforeClass(): void
        {
            require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        }

        protected function schemaSql(): array
        {
            return [
                'CREATE TABLE blox_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, draft_data TEXT, published_data TEXT)',
                'CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL DEFAULT 0, title TEXT, blocks_data TEXT, deleted_at INTEGER)',
                'CREATE TABLE blox_page_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL UNIQUE, draft_data TEXT NOT NULL, published_data TEXT, admin_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, published_at INTEGER NOT NULL DEFAULT 0)',
                'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', "key" TEXT UNIQUE, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)',
            ];
        }

        private static function document(string $styleId, string $tokenId): string
        {
            return json_encode(['schema' => 1, 'settings' => [], 'sections' => [[
                'id' => 's1', 'settings' => [], 'columns' => [['id' => 'c1', 'elements' => [
                    ['id' => 'e1', 'type' => 'heading', 'data' => ['text' => 'Hi', '_global_style' => $styleId, 'color' => 'var(--yk-color-' . $tokenId . ')']],
                ]]],
            ]]], JSON_THROW_ON_ERROR);
        }

        public function testPageDraftsAndChannelPublicationsAreCountedButDeletedContentIsNot(): void
        {
            $this->insertRow('blox_templates', ['type' => 'section', 'name' => 'Tpl', 'draft_data' => self::document('s_template', 'c_template'), 'published_data' => '']);
            $this->insertRow('contents', ['channel_id' => 5, 'title' => 'Live', 'blocks_data' => self::document('s_live', 'c_live')]);
            $this->insertRow('contents', ['channel_id' => 6, 'title' => 'Trashed', 'blocks_data' => self::document('s_trashed', 'c_trashed'), 'deleted_at' => 1]);
            $this->insertRow('blox_page_drafts', [
                'page_id' => 7,
                'draft_data' => self::document('s_draft', 'c_draft'),
                'published_data' => self::document('s_channel', 'c_channel'),
            ]);

            $usage = \BloxDesignDependencies::usageSnapshot();

            self::assertSame(1, $usage['styles']['s_template']['count'] ?? 0);
            self::assertSame(1, $usage['styles']['s_live']['count'] ?? 0);
            self::assertArrayNotHasKey('s_trashed', $usage['styles'], 'soft-deleted content must not keep styles in use');
            self::assertArrayNotHasKey('c_trashed', $usage['tokens']);

            self::assertSame(1, $usage['styles']['s_draft']['count'] ?? 0, 'unpublished page drafts reference styles too');
            self::assertSame(1, $usage['styles']['s_channel']['count'] ?? 0, 'channel landing pages publish into blox_page_drafts');
            self::assertSame(1, $usage['tokens']['c_channel']['count'] ?? 0);

            $draftSource = $usage['styles']['s_draft']['sources'][0] ?? [];
            self::assertSame('page_draft', $draftSource['type'] ?? null);
            self::assertSame(7, $draftSource['id'] ?? null);
            self::assertSame('draft', $draftSource['state'] ?? null);
            self::assertSame('published', $usage['styles']['s_channel']['sources'][0]['state'] ?? null);
        }
    }
}
