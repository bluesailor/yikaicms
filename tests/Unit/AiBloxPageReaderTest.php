<?php
/** S5-01 只读投影：权限、栏目类型与语言、停用/删除、代码与敏感字段排除、分页上限、元素 ID 异常。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use AiAccessAuthContext;
use AiBloxPageReader;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/ai-access/AiBloxPageReader.php';
require_once ROOT_PATH . '/includes/ai-access/AiAccessTokenStore.php';

final class AiBloxPageReaderTest extends TestCase
{
    private const AUTHOR = ['ai_access', 'blox_edit', 'edit_page'];

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        // 单元测试不加载整份函数库：只载入真实的编辑器总开关
        if (!function_exists('bloxPageEditorEnabled')) {
            $src = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
            self::assertSame(1, preg_match('/^function bloxPageEditorEnabled\(.*?^\}$/ms', $src, $m));
            eval($m[0]);
        }
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
                status INTEGER NOT NULL DEFAULT 1,
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

    /** @param list<string> $permissions */
    private static function context(array $permissions = self::AUTHOR): AiAccessAuthContext
    {
        return new AiAccessAuthContext(1, 1, $permissions, []);
    }

    /** @param list<array<string,mixed>> $elements @param array<string,mixed> $sectionSettings @param array<string,mixed> $docSettings */
    private static function page(array $elements, array $fields = [], array $sectionSettings = [], array $docSettings = []): int
    {
        $id = db()->insert('channels', $fields + ['type' => 'page', 'lang' => 'en', 'name' => 'About', 'slug' => 'about-' . mt_rand()]);
        $json = json_encode([
            'schema' => 1,
            'settings' => $docSettings,
            'sections' => [['id' => 's1', 'settings' => $sectionSettings, 'columns' => [['id' => 'c1', 'elements' => $elements]]]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        db()->insert('blox_page_drafts', ['page_id' => $id, 'draft_data' => $json]);
        return (int) $id;
    }

    /** @return array<string,array<string,mixed>> */
    private static function byId(array $result): array
    {
        $out = [];
        foreach ($result['elements'] as $element) $out[(string) $element['id']] = $element;
        return $out;
    }

    public function testAuthorReadsWhitelistedFieldsAndRevision(): void
    {
        $pageId = self::page([
            ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Welcome', 'url' => '/about', 'level' => 'h2', 'color' => '#ff0000']],
            ['id' => 't1', 'type' => 'text', 'data' => ['html' => '<p>Body</p>', 'typography_role' => 'body']],
            ['id' => 'i1', 'type' => 'image', 'data' => ['src' => '/a.jpg', 'alt' => 'Team', 'click_action' => 'none']],
            ['id' => 'b1', 'type' => 'button', 'data' => ['text' => 'Contact', 'url' => '/contact', 'variant' => 'primary']],
        ]);

        foreach ([self::AUTHOR, ['*']] as $permissions) {
            $result = AiBloxPageReader::read(self::context($permissions), $pageId, 'en');
            $this->assertTrue($result['ok']);
            $this->assertSame('page:' . $pageId, $result['page']['document']);
            $this->assertSame(\PageBloxDocument::load($pageId)['base_revision'], $result['page']['base_revision']);
            $this->assertTrue($result['page']['has_draft']);
            $elements = self::byId($result);
            $this->assertSame(['h1', 't1', 'i1', 'b1'], array_keys($elements));
            $this->assertSame(['text', 'url'], array_keys($elements['h1']['fields']));
            $this->assertSame('Welcome', $elements['h1']['fields']['text']['value']);
            $this->assertSame(['html'], array_keys($elements['t1']['fields']));
            $this->assertSame(['src', 'alt'], array_keys($elements['i1']['fields']));
            $this->assertSame(['text', 'url'], array_keys($elements['b1']['fields']));
            foreach ($elements as $element) {
                $this->assertTrue($element['addressable']);
                $this->assertTrue($element['editable']);
            }
        }
    }

    public function testPermissionsMirrorThePageEditorAndContentOnlyIsNotEnough(): void
    {
        $pageId = self::page([['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'x']]]);
        foreach ([
            ['blox_edit', 'edit_page'],
            ['ai_access', 'edit_page'],
            ['ai_access', 'blox_edit'],
            ['ai_access', 'blox_content_only'],
            ['ai_access', 'blox_content_only', 'edit_page'],
            [],
        ] as $permissions) {
            $this->assertSame(['ok' => false, 'error' => 'forbidden'], AiBloxPageReader::read(self::context($permissions), $pageId, 'en'), implode(',', $permissions));
        }
    }

    public function testOnlyEnabledOrdinaryPagesInTheRequestedLanguage(): void
    {
        $elements = [['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'x']]];
        $ctx = self::context();
        $this->assertSame('not_found', AiBloxPageReader::read($ctx, self::page($elements, ['type' => 'list']), 'en')['error']);
        $this->assertSame('not_found', AiBloxPageReader::read($ctx, self::page($elements, ['type' => 'product']), 'en')['error']);
        $this->assertSame('not_found', AiBloxPageReader::read($ctx, 0, 'en')['error']);
        $this->assertSame('not_found', AiBloxPageReader::read($ctx, 999, 'en')['error']);
        $this->assertSame('unavailable', AiBloxPageReader::read($ctx, self::page($elements, ['status' => 0]), 'en')['error']);

        $pageId = self::page($elements);
        $this->assertSame('language_mismatch', AiBloxPageReader::read($ctx, $pageId, 'zh-CN')['error']);
        $this->assertSame('language_mismatch', AiBloxPageReader::read($ctx, $pageId, '')['error']);
        db()->delete('channels', 'id = ?', [$pageId]);
        $this->assertSame('not_found', AiBloxPageReader::read($ctx, $pageId, 'en')['error']);
    }

    public function testCodeStyleConditionsQueriesAndBindingsNeverLeak(): void
    {
        $pageId = self::page([
            ['id' => 'code1', 'type' => 'code', 'data' => ['code' => '<script>SECRET_CODE</script>']],
            ['id' => 'h1', 'type' => 'heading', 'data' => [
                'text' => 'Visible', '_custom_css' => 'SECRET_CSS{}', '_conditions' => ['SECRET_COND'],
            ]],
            ['id' => 'h2', 'type' => 'heading', 'data' => ['text' => 'SECRET_FALLBACK', 'site_field' => 'site_name']],
            ['id' => 'loop1', 'type' => 'container', 'data' => [
                '_query' => ['source' => 'article'],
                'children' => [['id' => 'tpl1', 'type' => 'heading', 'data' => ['text' => 'SECRET_TEMPLATE']]],
            ]],
        ], [], ['_custom_css' => 'SECRET_SECTION_CSS'], ['custom_css' => 'SECRET_DOC_CSS']);

        $result = AiBloxPageReader::read(self::context(['*']), $pageId, 'en');
        $this->assertTrue($result['ok']);
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (['SECRET_CODE', 'SECRET_CSS', 'SECRET_COND', 'SECRET_FALLBACK', 'SECRET_TEMPLATE', 'SECRET_SECTION_CSS', 'SECRET_DOC_CSS', 'site_name', 'article'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded);
        }
        $elements = self::byId($result);
        $this->assertArrayNotHasKey('fields', $elements['code1']);
        $this->assertSame('type_not_supported', $elements['code1']['reason']);
        $this->assertFalse($elements['code1']['editable']);
        $this->assertSame(['kind' => 'text', 'editable' => false, 'reason' => 'site_binding'], $elements['h2']['fields']['text']);
        $this->assertTrue($elements['loop1']['children_omitted']);
        $this->assertArrayNotHasKey('tpl1', $elements);
        $this->assertSame('Visible', $elements['h1']['fields']['text']['value']);
    }

    public function testResultsArePagedAndOversizedDocumentsRefused(): void
    {
        $elements = [];
        for ($i = 0; $i < 120; $i++) $elements[] = ['id' => 'e' . $i, 'type' => 'heading', 'data' => ['text' => 'T' . $i]];
        $elements[] = ['id' => 'big', 'type' => 'text', 'data' => ['html' => str_repeat('a', AiBloxPageReader::MAX_FIELD_CHARS + 1)]];
        $pageId = self::page($elements);
        $ctx = self::context();

        $first = AiBloxPageReader::read($ctx, $pageId, 'en');
        $this->assertSame(121, $first['total']);
        $this->assertCount(AiBloxPageReader::DEFAULT_LIMIT, $first['elements']);
        $this->assertSame(50, $first['next_cursor']);
        $wide = AiBloxPageReader::read($ctx, $pageId, 'en', 0, 100000);
        $this->assertCount(AiBloxPageReader::MAX_LIMIT, $wide['elements']);
        $last = AiBloxPageReader::read($ctx, $pageId, 'en', 100, 100, $first['page']['base_revision']);
        $this->assertCount(21, $last['elements']);
        $this->assertNull($last['next_cursor']);
        $big = $last['elements'][20];
        $this->assertSame(['kind' => 'richtext', 'editable' => false, 'reason' => 'too_large'], $big['fields']['html']);
        $this->assertLessThan(10000, strlen(json_encode($last, JSON_THROW_ON_ERROR)));

        $huge = [];
        for ($i = 0; $i <= AiBloxPageReader::MAX_ELEMENTS; $i++) $huge[] = ['id' => 'x' . $i, 'type' => 'divider', 'data' => []];
        $this->assertSame(['ok' => false, 'error' => 'document_too_large'], AiBloxPageReader::read($ctx, self::page($huge), 'en'));
    }

    public function testLaterPagesArePinnedToTheFirstPageRevision(): void
    {
        $elements = [];
        for ($i = 0; $i < 60; $i++) $elements[] = ['id' => 'e' . $i, 'type' => 'heading', 'data' => ['text' => 'T' . $i]];
        $pageId = self::page($elements);
        $ctx = self::context();
        $first = AiBloxPageReader::read($ctx, $pageId, 'en');
        $revision = $first['page']['base_revision'];

        $this->assertSame(['ok' => false, 'error' => 'revision_mismatch'], AiBloxPageReader::read($ctx, $pageId, 'en', 50));
        $this->assertSame(['ok' => false, 'error' => 'revision_mismatch'], AiBloxPageReader::read($ctx, $pageId, 'en', 50, 50, 'stale'));
        $this->assertTrue(AiBloxPageReader::read($ctx, $pageId, 'en', 50, 50, $revision)['ok']);

        $elements[] = ['id' => 'late', 'type' => 'heading', 'data' => ['text' => 'Added between pages']];
        $json = json_encode(['schema' => 1, 'settings' => [], 'sections' => [['id' => 's1', 'settings' => [], 'columns' => [['id' => 'c1', 'elements' => $elements]]]]], JSON_THROW_ON_ERROR);
        db()->update('blox_page_drafts', ['draft_data' => $json], 'page_id = ?', [$pageId]);
        $this->assertSame(['ok' => false, 'error' => 'revision_mismatch'], AiBloxPageReader::read($ctx, $pageId, 'en', 50, 50, $revision));
    }

    public function testUnsafeStoredValuesAreReducedToTheBloxSafeSubset(): void
    {
        $pageId = self::page([
            ['id' => 't1', 'type' => 'text', 'data' => ['html' => '<p onclick="steal()" style="color:red">Hi<script>evil()</script></p>']],
            ['id' => 'b1', 'type' => 'button', 'data' => ['text' => 'Go', 'url' => 'javascript:alert(1)']],
            ['id' => 'i1', 'type' => 'image', 'data' => ['src' => 'javascript:alert(2)', 'alt' => 'A']],
            ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Clean', 'url' => '/clean']],
        ]);
        $result = AiBloxPageReader::read(self::context(), $pageId, 'en');
        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach (['<script', 'evil()', 'onclick', 'steal()', 'javascript:', 'color:red'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $encoded);
        }
        $elements = self::byId($result);
        $this->assertStringContainsString('Hi', $elements['t1']['fields']['html']['value']);
        $this->assertTrue($elements['t1']['fields']['html']['sanitized']);
        $this->assertSame('', $elements['b1']['fields']['url']['value']);
        $this->assertTrue($elements['b1']['fields']['url']['sanitized']);
        $this->assertSame('', $elements['i1']['fields']['src']['value']);
        $this->assertSame(['kind' => 'url', 'editable' => true, 'value' => '/clean'], $elements['h1']['fields']['url']);
        $this->assertArrayNotHasKey('sanitized', $elements['h1']['fields']['text']);
    }

    public function testIdsInsideUnexpandedSubtreesAreAllCountedOrThePageIsRefused(): void
    {
        $nest = static function (int $levels, array $leaf): array {
            for ($i = 0; $i < $levels; $i++) $leaf = ['id' => 'n' . $i, 'type' => 'container', 'data' => ['children' => [$leaf]]];
            return $leaf;
        };
        $leaf = ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Deep twin']];
        $loop = ['id' => 'loop', 'type' => 'container', 'data' => ['_query' => ['source' => 'article'], 'children' => [$nest(20, $leaf)]]];
        $result = AiBloxPageReader::read(self::context(), self::page([['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Top']], $loop]), 'en');
        $this->assertSame('duplicate_id', self::byId($result)['h1']['reason']);

        $tooDeep = ['id' => 'loop', 'type' => 'container', 'data' => ['_query' => ['source' => 'article'], 'children' => [$nest(AiBloxPageReader::MAX_DEPTH * 4 + 2, $leaf)]]];
        $this->assertSame(['ok' => false, 'error' => 'document_too_large'], AiBloxPageReader::read(self::context(), self::page([$tooDeep]), 'en'));
    }

    public function testMissingDuplicateAndInvalidIdsAreReportedWithoutRewritingTheDocument(): void
    {
        $pageId = self::page([
            ['type' => 'heading', 'data' => ['text' => 'No id']],
            ['id' => 'dup', 'type' => 'heading', 'data' => ['text' => 'First']],
            ['id' => 'box', 'type' => 'container', 'data' => ['children' => [
                ['id' => 'dup', 'type' => 'text', 'data' => ['html' => '<p>Second</p>']],
                ['id' => 'inner', 'type' => 'button', 'data' => ['text' => 'Go', 'url' => '/go']],
            ]]],
            ['id' => 'bad id!', 'type' => 'heading', 'data' => ['text' => 'Bad']],
            ['id' => 'c1', 'type' => 'heading', 'data' => ['text' => 'Clashes with column id']],
        ]);
        $before = db()->fetchOne('SELECT draft_data, updated_at FROM ' . DB_PREFIX . 'blox_page_drafts WHERE page_id = ?', [$pageId]);

        $result = AiBloxPageReader::read(self::context(), $pageId, 'en');
        $this->assertTrue($result['ok']);
        $byPath = [];
        foreach ($result['elements'] as $element) $byPath[$element['path']] = $element;

        $this->assertNull($byPath['s0/c0/e0']['id']);
        $this->assertSame('missing_id', $byPath['s0/c0/e0']['reason']);
        $this->assertSame('duplicate_id', $byPath['s0/c0/e1']['reason']);
        $this->assertSame('duplicate_id', $byPath['s0/c0/e2/0']['reason']);
        $this->assertSame(2, $byPath['s0/c0/e2/0']['depth']);
        $this->assertNull($byPath['s0/c0/e3']['id']);
        $this->assertSame('invalid_id', $byPath['s0/c0/e3']['reason']);
        $this->assertSame('duplicate_id', $byPath['s0/c0/e4']['reason']);
        foreach (['s0/c0/e0', 's0/c0/e1', 's0/c0/e2/0', 's0/c0/e3', 's0/c0/e4'] as $path) {
            $this->assertFalse($byPath[$path]['addressable']);
            $this->assertFalse($byPath[$path]['editable']);
            foreach ($byPath[$path]['fields'] ?? [] as $field) $this->assertFalse($field['editable']);
        }
        $this->assertTrue($byPath['s0/c0/e2/1']['addressable']);
        $this->assertTrue($byPath['s0/c0/e2/1']['editable']);
        $this->assertSame('type_not_supported', $byPath['s0/c0/e2']['reason']);
        $this->assertTrue($byPath['s0/c0/e2']['addressable']);

        $after = db()->fetchOne('SELECT draft_data, updated_at FROM ' . DB_PREFIX . 'blox_page_drafts WHERE page_id = ?', [$pageId]);
        $this->assertSame($before, $after);
    }

    public function testMcpPageSourceRevisionMatchesTheEditorForEveryDocumentState(): void
    {
        $doc = json_encode(['schema' => 1, 'settings' => ['custom_css' => 'a{}'], 'sections' => [['id' => 's1', 'settings' => [], 'columns' => [['id' => 'c1', 'elements' => [
            ['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Hi']],
        ]]]]]], JSON_THROW_ON_ERROR);
        $edited = str_replace('"Hi"', '"Edited"', $doc);
        $cases = [];
        $cases['draft only'] = self::page([['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Draft']]]);
        $id = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'P', 'slug' => 'p-' . mt_rand()]);
        db()->insert('contents', ['channel_id' => $id, 'lang' => 'en', 'status' => 1, 'content_type' => 'blocks', 'blocks_data' => $doc, 'content' => '<h2>Hi</h2>']);
        $cases['published only'] = $id;
        $id = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'P', 'slug' => 'p-' . mt_rand()]);
        db()->insert('contents', ['channel_id' => $id, 'lang' => 'en', 'status' => 1, 'content_type' => 'blocks', 'blocks_data' => $doc]);
        db()->insert('blox_page_drafts', ['page_id' => $id, 'draft_data' => $edited]);
        $cases['published with newer draft'] = $id;
        $cases['blank canvas'] = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'P', 'slug' => 'p-' . mt_rand()]);
        $id = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'P', 'slug' => 'p-' . mt_rand()]);
        db()->insert('contents', ['channel_id' => $id, 'lang' => 'en', 'status' => 1, 'content_type' => 'blocks', 'blocks_data' => '', 'content' => '<p>stale</p>']);
        $cases['blocks type without data'] = $id;

        $source = new \AiPageDocumentSource(db());
        foreach ($cases as $label => $pageId) {
            $editor = \PageBloxDocument::load($pageId);
            $mcp = $source->load((array) $source->page($pageId));
            $this->assertNull($mcp['legacy_html'], $label);
            $this->assertSame($editor['base_revision'], $mcp['base_revision'], $label);
            foreach (['has_draft', 'has_published', 'has_unpublished_changes'] as $flag) $this->assertSame($editor[$flag], $mcp[$flag], $label . ' ' . $flag);
        }
        $legacy = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Old', 'slug' => 'old-' . mt_rand(), 'content' => '<p>Old</p>']);
        $this->assertTrue(\PageBloxDocument::load($legacy)['uses_legacy_html']);
        $this->assertSame('<p>Old</p>', $source->load((array) $source->page($legacy))['legacy_html']);
        $this->assertNull($source->page((int) db()->insert('channels', ['type' => 'list', 'lang' => 'en', 'name' => 'L', 'slug' => 'l-' . mt_rand()])));
    }

    public function testTheEditorSeesTheAiRevisionAndRejectsSavesBasedOnTheOldOne(): void
    {
        require_once ROOT_PATH . '/includes/ai-access/AiPageContentRules.php';
        $pageId = self::page([['id' => 'h1', 'type' => 'heading', 'data' => ['text' => 'Before AI']]]);
        $editor = \PageBloxDocument::load($pageId);
        // 与 AiProposalService::applyPage 相同的写法：解码当前文档、只改提案字段、按规范形式写回草稿行
        $result = \AiPageContentRules::apply(\BloxDocumentPipeline::decode($editor['document_json']), [['element_id' => 'h1', 'field' => 'text', 'value' => 'After AI']]);
        $this->assertArrayHasKey('document', $result);
        $doc = $result['document'];
        $json = json_encode(['schema' => $doc['schema'], 'settings' => $doc['settings'], 'sections' => $doc['sections']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        db()->update('blox_page_drafts', ['draft_data' => $json], 'page_id = ?', [$pageId]);

        $after = \PageBloxDocument::load($pageId);
        $this->assertSame(\BloxDocumentPipeline::fingerprint($json), $after['base_revision'], 'editor and AI agree on the revision after apply');
        $this->assertSame($after['base_revision'], (new \AiPageDocumentSource(db()))->load((array) (new \AiPageDocumentSource(db()))->page($pageId))['base_revision']);
        try {
            \PageBloxDocument::saveDraft($pageId, $editor['document_json'], $editor['base_revision']);
            $this->fail('a save based on the pre-apply revision must be rejected');
        } catch (\RuntimeException $e) {
            $this->assertSame(__('blox_save_conflict'), $e->getMessage(), 'rejected for the revision conflict, not another error');
            $this->assertStringContainsString('After AI', (string) db()->fetchColumn('SELECT draft_data FROM ' . DB_PREFIX . 'blox_page_drafts WHERE page_id = ?', [$pageId]));
        }
    }

    public function testPageToolsNeedThePageReadScopeAndThePageEditorPermissions(): void
    {
        $tools = static fn(array $permissions, array $scopes): array => array_values(array_filter(AiAccessAuthContext::TOOLS,
            (new AiAccessAuthContext(1, 1, $permissions, $scopes))->allowsTool(...)));
        $this->assertSame(['page_list', 'page_read'], $tools(self::AUTHOR, ['page_read']));
        $this->assertSame(['page_list', 'page_read'], $tools(['*'], ['page_read']));
        $this->assertSame([], $tools(self::AUTHOR, ['page_list']), 'page_list is not a scope of its own');
        $this->assertSame([], $tools(self::AUTHOR, ['article_read']));
        $this->assertSame([], $tools(['ai_access', 'edit_article', 'blox_content_only'], ['page_read']));
        $this->assertSame([], $tools(['ai_access', 'blox_edit'], ['page_read']));
        $this->assertContains('page_read', \AiAccessTokenStore::ISSUABLE_SCOPES);
        $this->assertNotContains('page_list', \AiAccessTokenStore::ISSUABLE_SCOPES);
        $this->assertNotContains('page_read', \AiAccessTokenStore::DEFAULT_SCOPES);
        $this->assertSame(['site_info', 'page_read'], \AiAccessTokenStore::normalizeScopes(['page_read', 'site_info']));
    }

    public function testLegacyHtmlPagesAreReadOnlyBecauseTheirIdsAreNotStored(): void
    {
        $pageId = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Old', 'slug' => 'old', 'content' => '<p>Legacy body</p>']);
        $result = AiBloxPageReader::read(self::context(), $pageId, 'en');
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['page']['uses_legacy_html']);
        $this->assertFalse($result['page']['has_draft']);
        $this->assertNotSame([], $result['elements']);
        foreach ($result['elements'] as $element) {
            $this->assertFalse($element['addressable']);
            $this->assertFalse($element['editable']);
            $this->assertSame('legacy_html', $element['reason']);
        }
        $this->assertSame(0, (int) db()->fetchOne('SELECT COUNT(*) AS n FROM ' . DB_PREFIX . 'blox_page_drafts')['n']);
    }
}
