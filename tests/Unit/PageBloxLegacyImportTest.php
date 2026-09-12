<?php

declare(strict_types=1);

use Yikai\Tests\TestCase;

final class PageBloxLegacyImportTest extends TestCase
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
                lang TEXT NOT NULL,
                name TEXT NOT NULL,
                slug TEXT NOT NULL DEFAULT \'\',
                content TEXT,
                updated_at INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
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
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )',
        ];
    }

    public function testPageFrameSettingsPersistOnlyInDraftUntilPublished(): void
    {
        $pageId = $this->insertRow('channels', [
            'type' => 'page', 'lang' => 'zh-CN', 'name' => 'Frame test', 'content' => '',
        ]);
        $before = PageBloxDocument::load($pageId);
        $json = json_encode(['schema' => 1, 'settings' => [
            'page_header_hidden' => true, 'page_footer_hidden' => false, 'page_breadcrumb_hidden' => true,
        ], 'sections' => []], JSON_THROW_ON_ERROR);
        PageBloxDocument::saveDraft($pageId, $json, $before['base_revision']);
        $after = PageBloxDocument::load($pageId);
        $this->assertTrue($after['has_draft']);
        $this->assertTrue($after['has_unpublished_changes']);
        $this->assertFalse($after['has_published']);
        $draft = BloxDocumentPipeline::decode($after['document_json']);
        $this->assertTrue($draft['settings']['page_header_hidden']);
        $this->assertFalse($draft['settings']['page_footer_hidden']);
        $this->assertTrue($draft['settings']['page_breadcrumb_hidden']);
        $this->assertSame($before['published_document_json'], $after['published_document_json']);
    }

    public function testNewEmptyPageDoesNotInsertAnyTitleOrSection(): void
    {
        $id = $this->insertRow('channels', ['type' => 'page', 'lang' => 'zh-CN', 'name' => 'New page', 'content' => '']);
        $state = PageBloxDocument::load($id);
        $doc = BloxDocumentPipeline::decode($state['document_json']);
        $this->assertTrue($doc['settings']['page_title_hidden']);
        $this->assertFalse(PageBloxDocument::usesThemeTitle($doc['settings']));
        $this->assertSame([], $doc['sections']);
        $this->assertFalse($state['has_draft']);
        $this->assertFalse($state['has_unpublished_changes']);
        PageBloxDocument::saveDraft($id, $state['document_json'], $state['base_revision']);
        $existing = BloxDocumentPipeline::decode(PageBloxDocument::load($id)['document_json']);
        $this->assertSame([], $existing['sections']);
        $this->assertTrue($existing['settings']['page_title_hidden']);
    }

    public function testLegacyBlocksKeepThemeTitleWithoutChangingTheirDraft(): void
    {
        foreach (['zh-CN', 'en', 'ja'] as $language) {
            $id = $this->insertRow('channels', ['type' => 'page', 'lang' => $language, 'name' => 'Legacy page', 'content' => '']);
            $raw = '{"schema":1,"settings":{},"sections":[]}';
            $this->insertRow('contents', ['channel_id' => $id, 'content_type' => 'blocks', 'content' => '', 'blocks_data' => $raw]);
            $state = PageBloxDocument::load($id);
            $document = BloxDocumentPipeline::decode($state['document_json']);
            $this->assertTrue(PageBloxDocument::usesThemeTitle($document['settings']));
            $this->assertArrayNotHasKey('page_title_hidden', $document['settings']);
            PageBloxDocument::saveDraft($id, $state['document_json'], $state['base_revision']);
            $saved = PageBloxDocument::load($id);
            $this->assertFalse($saved['has_unpublished_changes']);
            $this->assertSame($state['document_json'], $saved['document_json']);
        }
    }

    public function testOnlyAnExplicitDocumentSettingHidesThemeTitle(): void
    {
        $this->assertTrue(PageBloxDocument::usesThemeTitle([]));
        foreach ([false, 'false', 'true', '0', [], null] as $value) {
            $this->assertTrue(PageBloxDocument::usesThemeTitle(['page_title_hidden' => $value]));
        }
        foreach ([true, 1, '1'] as $value) {
            $settings = BloxDocumentPipeline::normalizeDocSettings(['page_title_hidden' => $value]);
            $this->assertFalse(PageBloxDocument::usesThemeTitle($settings));
        }
        $this->assertTrue(PageBloxDocument::usesThemeTitle(['page_header_hidden' => true, 'page_footer_hidden' => true]));
        foreach (['page.php', 'includes/builder/BloxCanvasPreview.php'] as $path) {
            $this->assertStringContainsString('PageBloxDocument::usesThemeTitle(', (string) file_get_contents(ROOT_PATH . '/' . $path));
        }
    }

    public function testPageFrameDefaultsAndMalformedValuesRemainVisible(): void
    {
        $this->assertSame([], BloxDocumentPipeline::normalizeDocSettings([]));
        foreach (['false', 'true', '0', [], null] as $value) {
            $settings = BloxDocumentPipeline::normalizeDocSettings(['page_header_hidden' => $value]);
            $this->assertFalse($settings['page_header_hidden']);
        }
        $this->assertTrue(BloxDocumentPipeline::normalizeDocSettings(['page_footer_hidden' => '1'])['page_footer_hidden']);
    }

    public function testPageUrlSaveDoesNotChangeContentOrDraftRevision(): void
    {
        $id = $this->insertRow('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Page', 'slug' => 'old-page', 'content' => '<p>Keep me</p>']);
        $before = PageBloxDocument::load($id);
        PageBloxDocument::saveDraft($id, '{"settings":{"page_breadcrumb_hidden":true},"sections":[]}', $before['base_revision']);
        $draft = PageBloxDocument::load($id);
        $updated = channelModel()->updatePageSlug($id, 'new-page', 'old-page');
        $this->assertSame('new-page', $updated['slug']);
        $this->assertSame('<p>Keep me</p>', channelModel()->find($id)['content']);
        $after = PageBloxDocument::load($id);
        $this->assertSame($draft['document_json'], $after['document_json']);
        $this->assertSame($draft['base_revision'], $after['base_revision']);
        $this->assertFalse($after['has_published']);
    }

    public function testPageUrlRejectsInvalidReservedDuplicateAndStaleEdits(): void
    {
        $id = $this->insertRow('channels', ['type' => 'page', 'lang' => 'zh-CN', 'name' => 'Page', 'slug' => 'original']);
        $this->insertRow('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Other', 'slug' => 'taken']);
        foreach (['', '../admin', 'https://example.com', 'page.html', 'bad_slug', 'admin', 'contact', 'taken'] as $slug) {
            try {
                channelModel()->updatePageSlug($id, $slug, 'original');
                $this->fail('Unexpected accepted slug: ' . $slug);
            } catch (RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage());
                $this->assertSame('original', channelModel()->find($id)['slug']);
            }
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('blox_save_conflict'));
        channelModel()->updatePageSlug($id, 'new-page', 'stale-value');
    }

    public function testLegacyRichTextIsSeededWithoutWritingADraft(): void
    {
        $pageId = $this->insertRow('channels', [
            'type' => 'page',
            'lang' => 'zh-CN',
            'name' => 'Legacy page',
            'content' => '',
            'updated_at' => 1,
        ]);
        $this->insertRow('contents', [
            'channel_id' => $pageId,
            'status' => 1,
            'deleted_at' => null,
            'is_top' => 0,
            'content_type' => 'html',
            'content' => '<h2>Existing heading</h2><p>Existing body</p>',
            'blocks_data' => null,
        ]);

        $state = PageBloxDocument::load($pageId);
        $document = BloxDocumentPipeline::decode($state['document_json']);
        $publishedDocument = BloxDocumentPipeline::decode($state['published_document_json']);

        self::assertFalse($state['has_draft']);
        self::assertFalse($state['has_published']);
        self::assertTrue(PageBloxDocument::usesThemeTitle($document['settings']));
        self::assertSame('text', $document['sections'][0]['columns'][0]['elements'][0]['type']);
        self::assertSame(
            '<h2>Existing heading</h2><p>Existing body</p>',
            $document['sections'][0]['columns'][0]['elements'][0]['data']['html']
        );
        self::assertSame($document['sections'], $publishedDocument['sections']);
        self::assertNull(bloxPageDraftModel()->findByPageId($pageId));
    }

    public function testChannelBodyIsImportedWhenNoMirroredContentRowExists(): void
    {
        // 老站形态：单页正文只存 channels.content、没有镜像 contents 行——
        // 编辑器不能开空画布（longcool.cn 实例），须兜底导入栏目正文。
        $pageId = $this->insertRow('channels', [
            'type' => 'page',
            'lang' => 'zh-CN',
            'name' => 'Channel-body legacy page',
            'content' => '<h2>栏目正文</h2><p>存于 channels.content。</p>',
            'updated_at' => 1,
        ]);

        $state = PageBloxDocument::load($pageId);
        $document = BloxDocumentPipeline::decode($state['document_json']);

        self::assertFalse($state['has_draft']);
        self::assertFalse($state['has_published']);
        self::assertSame('text', $document['sections'][0]['columns'][0]['elements'][0]['type']);
        self::assertSame(
            '<h2>栏目正文</h2><p>存于 channels.content。</p>',
            $document['sections'][0]['columns'][0]['elements'][0]['data']['html']
        );
        self::assertNull(bloxPageDraftModel()->findByPageId($pageId));
    }

    public function testBlocksContentRowStillYieldsEmptyDocumentWithoutChannelFallback(): void
    {
        // blocks 页（空 blocks_data）维持原语义：不导入任何 html 正文
        $pageId = $this->insertRow('channels', [
            'type' => 'page',
            'lang' => 'zh-CN',
            'name' => 'Blocks page',
            'content' => '<p>不应被导入</p>',
            'updated_at' => 1,
        ]);
        $this->insertRow('contents', [
            'channel_id' => $pageId,
            'status' => 1,
            'deleted_at' => null,
            'is_top' => 0,
            'content_type' => 'blocks',
            'content' => '<p>旧富文本</p>',
            'blocks_data' => '',
        ]);

        $state = PageBloxDocument::load($pageId);
        $document = BloxDocumentPipeline::decode($state['document_json']);
        self::assertSame([], $document['sections']);
    }

    public function testLegacyOrganizationChartIsSeededAsStructuredElementWithoutWritingADraft(): void
    {
        $pageId = $this->insertRow('channels', [
            'type' => 'page',
            'lang' => 'zh-CN',
            'name' => 'Organization',
            'content' => '',
            'updated_at' => 1,
        ]);
        $this->insertRow('contents', [
            'channel_id' => $pageId,
            'status' => 1,
            'deleted_at' => null,
            'is_top' => 0,
            'content_type' => 'html',
            'content' => '<div class="org-chart"><ul><li><div class="org-node org-ceo">CEO'
                . '<span class="org-title">Chief executive</span></div><ul><li>'
                . '<div class="org-node org-dept">Engineering</div></li></ul></li></ul></div>'
                . '<p>Additional copy</p>',
            'blocks_data' => null,
        ]);

        $state = PageBloxDocument::load($pageId);
        $document = BloxDocumentPipeline::decode($state['document_json']);
        $elements = $document['sections'][0]['columns'][0]['elements'];

        self::assertFalse($state['has_draft']);
        self::assertSame(['org-chart', 'text'], array_column($elements, 'type'));
        self::assertSame(['CEO', 'Engineering'], array_column($elements[0]['data']['nodes'], 'name'));
        self::assertStringContainsString('Additional copy', $elements[1]['data']['html']);
        self::assertNull(bloxPageDraftModel()->findByPageId($pageId));
    }
}
