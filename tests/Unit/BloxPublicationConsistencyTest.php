<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class BloxPublicationConsistencyTest extends TestCase
{
    private const DOC = '{"schema":1,"settings":{},"sections":[{"id":"s_test","settings":{},"columns":[{"id":"c_test","elements":[]}]}]}';

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        require_once ROOT_PATH . '/includes/builder/detail-editor-bootstrap.php';
        // Thin entry-point helpers delegate to the real renderer and revision model.
        if (!function_exists('renderBlocksToHtml')) {
            function renderBlocksToHtml(string $json): string { return BlockRenderer::render($json); }
        }
        if (!function_exists('recordContentRevision')) {
            function recordContentRevision(string $type, int $id, string $lang, array $targets, string $summary): void
            {
                contentRevisionModel()->record($type, $id, $lang, $targets, $summary);
            }
        }
        if (!function_exists('cacheClear')) { function cacheClear(): void {} }
        if (!function_exists('do_action')) { function do_action(string $hook, mixed ...$args): void {} }
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, parent_id INTEGER DEFAULT 0,
                lang TEXT, name TEXT, slug TEXT DEFAULT '', description TEXT, content TEXT, updated_at INTEGER DEFAULT 0)",
            "CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER, lang TEXT,
                title TEXT, status INTEGER DEFAULT 1, deleted_at INTEGER, is_top INTEGER DEFAULT 0,
                content_type TEXT DEFAULT 'html', content TEXT, blocks_data TEXT, publish_time INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0)",
            'CREATE TABLE blox_page_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER UNIQUE,
                draft_data TEXT, published_data TEXT, admin_id INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0,
                updated_at INTEGER DEFAULT 0, published_at INTEGER DEFAULT 0)',
            'CREATE TABLE content_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT,
                target_id INTEGER, lang TEXT, snapshot TEXT, summary TEXT, admin_id INTEGER, admin_name TEXT, created_at INTEGER)',
            'CREATE TABLE blox_templates (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT, name TEXT, source TEXT,
                source_ref TEXT, schema_version INTEGER DEFAULT 1, draft_data TEXT, published_data TEXT,
                requirements TEXT, metadata TEXT, conditions TEXT, thumbnail TEXT, status INTEGER DEFAULT 1,
                admin_id INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0, published_at INTEGER DEFAULT 0)',
        ];
    }

    private function page(string $html = '<p>OLD HTML</p>', string $lang = 'en'): int
    {
        return $this->insertRow('channels', ['type' => 'page', 'lang' => $lang, 'name' => 'About', 'content' => $html]);
    }

    private function publish(int $id): void
    {
        $state = PageBloxDocument::load($id);
        PageBloxDocument::saveAndPublish($id, self::DOC, $state['base_revision']);
    }

    public function testSourceSwitchPreservesDraftAndPublishedLayoutsForBothTypes(): void
    {
        foreach (['product-legacy', 'product', 'article'] as $variant) {
            $kind = $variant === 'product-legacy' ? 'product' : $variant;
            $scopeKey = $variant === 'product-legacy' ? 'product_template' : 'detail_template';
            $scope = $variant === 'product-legacy'
                ? ['mode' => 'all', 'ids' => [], 'lang' => 'en']
                : ['version' => 2, 'content_type' => $kind, 'lang' => 'en', 'include' => [['kind' => 'all']], 'exclude' => []];
            $published = json_encode(['schema' => 1, 'settings' => [$scopeKey => $scope], 'sections' => []], JSON_THROW_ON_ERROR);
            $draft = json_decode($published, true);
            $draft['sections'] = json_decode(self::DOC, true)['sections'];
            $draft = json_encode($draft, JSON_THROW_ON_ERROR);
            $id = $this->insertRow('blox_templates', ['type' => $kind . '-detail', 'name' => 'T', 'source' => 'user', 'draft_data' => $draft, 'published_data' => $published]);
            bloxTemplateModel()->switchDetailSource($id, $kind . '-detail', 'native', hash('sha256', $published));
            $switched = bloxTemplateModel()->find($id);
            self::assertSame([], json_decode($switched['published_data'], true)['sections']);
            self::assertNotEmpty(json_decode($switched['draft_data'], true)['sections']);
            self::assertSame('native', json_decode($switched['draft_data'], true)['settings'][$scopeKey]['source']);
            try {
                bloxTemplateModel()->updateDraft($id, $draft, [], $draft);
                self::fail('Stale editor save should conflict after a source switch');
            } catch (RuntimeException $e) {
                self::assertSame(__('blox_save_conflict'), $e->getMessage());
            }
            bloxTemplateModel()->updateDraft($id, $switched['draft_data'], [], $switched['draft_data']);
            bloxTemplateModel()->publishDraft($id);
            $final = bloxTemplateModel()->find($id);
            self::assertSame('native', json_decode($final['published_data'], true)['settings'][$scopeKey]['source']);
            bloxTemplateModel()->switchDetailSource($id, $kind . '-detail', 'custom', hash('sha256', $final['published_data']));
            $custom = bloxTemplateModel()->find($id);
            self::assertSame($custom['draft_data'], $custom['published_data']);
            self::assertStringNotContainsString('"source":"native"', $custom['draft_data']);
        }
    }

    public function testSourceSwitchHandlesNullDraftAndRepeatedClicks(): void
    {
        $id = $this->insertRow('blox_templates', ['type' => 'product-detail', 'name' => 'T', 'source' => 'user', 'draft_data' => null, 'published_data' => self::DOC]);
        bloxTemplateModel()->switchDetailSource($id, 'product-detail', 'native', hash('sha256', self::DOC));
        $row = bloxTemplateModel()->find($id);
        bloxTemplateModel()->switchDetailSource($id, 'product-detail', 'native', hash('sha256', $row['published_data']));
        self::assertSame($row, bloxTemplateModel()->find($id));
    }

    public function testLegacyHtmlRestoreAlsoRestoresMirrorAndCanBeUndone(): void
    {
        foreach (['<p>OLD HTML</p>', ''] as $html) {
            $id = $this->page($html);
            $this->publish($id);
            $revision = db()->fetchOne('SELECT * FROM content_revisions WHERE target_id = ? ORDER BY id ASC LIMIT 1', [$id]);
            self::assertCount(1, json_decode($revision['snapshot'], true)['targets']);
            self::assertSame(2, PageBloxDocument::restoreRevision($id, $revision));
            $live = contentModel()->getFirstByChannel($id, 'en');
            self::assertSame('html', $live['content_type']);
            self::assertNull($live['blocks_data']);
            self::assertSame($html, $live['content']);
            self::assertSame($html, channelModel()->find($id)['content']);
            self::assertFalse(PageBloxDocument::load($id)['has_published']);
            $undo = db()->fetchOne('SELECT * FROM content_revisions WHERE target_id = ? ORDER BY id DESC LIMIT 1', [$id]);
            PageBloxDocument::restoreRevision($id, $undo);
            self::assertSame('blocks', contentModel()->getFirstByChannel($id, 'en')['content_type']);
            self::assertTrue(PageBloxDocument::load($id)['has_published']);
        }
    }

    public function testPublishingUsesPageLanguageAndFrontendOrdering(): void
    {
        $id = $this->page();
        $selected = $this->insertRow('contents', ['channel_id' => $id, 'lang' => 'en', 'content' => 'Selected', 'publish_time' => 200]);
        $older = $this->insertRow('contents', ['channel_id' => $id, 'lang' => 'en', 'content' => 'Older', 'publish_time' => 100]);
        $foreign = $this->insertRow('contents', ['channel_id' => $id, 'lang' => 'zh-CN', 'content' => 'Foreign', 'is_top' => 1]);
        $disabled = $this->insertRow('contents', ['channel_id' => $id, 'lang' => 'en', 'content' => 'Disabled', 'status' => 0, 'is_top' => 1]);
        $this->publish($id);
        self::assertSame($selected, (int) contentModel()->getFirstByChannel($id, 'en')['id']);
        self::assertSame('blocks', contentModel()->find($selected)['content_type']);
        foreach ([$older, $foreign, $disabled] as $untouched) self::assertSame('html', contentModel()->find($untouched)['content_type']);
        $revision = db()->fetchOne('SELECT * FROM content_revisions WHERE target_id = ?', [$id]);
        PageBloxDocument::restoreRevision($id, $revision);
        self::assertSame('Selected', contentModel()->find($selected)['content']);
        self::assertSame('Foreign', contentModel()->find($foreign)['content']);
    }

    public function testForeignOnlyRowsDoNotPreventCreatingThePageLanguageMirror(): void
    {
        $id = $this->page();
        $foreign = $this->insertRow('contents', ['channel_id' => $id, 'lang' => 'zh-CN', 'content' => 'Foreign']);
        $this->publish($id);
        self::assertSame(2, (int) db()->fetchColumn('SELECT COUNT(*) FROM contents'));
        self::assertSame('Foreign', contentModel()->find($foreign)['content']);
        self::assertSame('blocks', contentModel()->getFirstByChannel($id, 'en')['content_type']);
    }
}
