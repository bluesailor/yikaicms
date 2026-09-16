<?php
/**
 * R1A：历史版本「载入到画布」。
 *
 * 载入数据源必须是纯读取：不写库、不改发布数据；blocks 版本经既有归一化，
 * 纯 HTML 版本用与旧页读取一致的包装；两者皆空必须报错而不是载入空布局。
 * 编辑器端契约：载入走 runCommand 成为一条历史，保留服务器 base_revision。
 */

declare(strict_types=1);

use Yikai\Tests\TestCase;

final class BloxRevisionCanvasLoadTest extends TestCase
{
    private const DOC = '{"schema":1,"settings":{},"sections":[{"id":"s_rev","settings":{},"columns":[{"id":"c_rev","elements":[{"id":"e_rev","type":"heading","data":{"text":"来自历史","level":"h2"}}]}]}]}';

    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
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
        ];
    }

    /** @return array{page:int, content:int} */
    private function publishedPage(): array
    {
        $page = $this->insertRow('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'About', 'content' => '<p>LIVE HTML</p>']);
        $content = $this->insertRow('contents', ['channel_id' => $page, 'lang' => 'en', 'title' => 'About',
            'content_type' => 'blocks', 'content' => '<div>live</div>', 'blocks_data' => self::DOC]);
        return ['page' => $page, 'content' => $content];
    }

    private function revisionRow(int $pageId, array $targets): array
    {
        $id = $this->insertRow('content_revisions', ['target_type' => 'page', 'target_id' => $pageId, 'lang' => 'en',
            'snapshot' => json_encode(['targets' => $targets], JSON_UNESCAPED_UNICODE), 'summary' => 'test', 'created_at' => time()]);
        return db()->fetchOne('SELECT * FROM content_revisions WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed> 全库快照（载入必须零写入） */
    private function databaseSnapshot(): array
    {
        $rows = [];
        foreach (['channels', 'contents', 'blox_page_drafts', 'content_revisions'] as $table) {
            $rows[$table] = db()->fetchAll("SELECT * FROM {$table} ORDER BY id");
        }
        return $rows;
    }

    public function testBlocksRevisionLoadsNormalizedDocumentWithoutWriting(): void
    {
        $ids = $this->publishedPage();
        $old = '{"schema":1,"settings":{},"sections":[{"id":"s_old","settings":{},"columns":[{"id":"c_old","elements":[{"id":"e_old","type":"text","data":{"html":"<p>旧版本正文</p>"}}]}]}]}';
        $revision = $this->revisionRow($ids['page'], [[
            'table' => 'contents', 'id' => $ids['content'],
            'fields' => ['content_type' => 'blocks', 'content' => '<div>old rendered</div>', 'blocks_data' => $old],
        ]]);

        $before = $this->databaseSnapshot();
        $state = PageBloxDocument::load($ids['page']);
        $json = PageBloxDocument::revisionEditableDocument($revision, $state);
        $document = json_decode($json, true);

        self::assertSame('s_old', $document['sections'][0]['id']);
        self::assertSame('<p>旧版本正文</p>', $document['sections'][0]['columns'][0]['elements'][0]['data']['html']);
        // 载入是纯读取：发布数据、草稿、历史都必须原样
        self::assertSame($before, $this->databaseSnapshot());
        // 载入不返回历史指纹当作 base_revision 的材料（客户端保留服务器版本号）
        self::assertArrayNotHasKey('base_revision', $document);
    }

    public function testHtmlOnlyRevisionWrapsIntoEditableTextElement(): void
    {
        $ids = $this->publishedPage();
        $revision = $this->revisionRow($ids['page'], [[
            'table' => 'channels', 'id' => $ids['page'], 'fields' => ['content' => '<p>OLD HTML BODY</p>'],
        ]]);

        $before = $this->databaseSnapshot();
        $document = json_decode(PageBloxDocument::revisionEditableDocument($revision, PageBloxDocument::load($ids['page'])), true);

        self::assertCount(1, $document['sections']);
        $elements = $document['sections'][0]['columns'][0]['elements'];
        self::assertSame('text', $elements[0]['type']);
        self::assertStringContainsString('OLD HTML BODY', (string) $elements[0]['data']['html']);
        self::assertSame($before, $this->databaseSnapshot());
    }

    public function testEmptyRevisionIsRejectedInsteadOfLoadingABlankLayout(): void
    {
        $ids = $this->publishedPage();
        // content_type=blocks 的行不算 HTML 来源；既无 blocks 又无 HTML → 必须拒绝
        $revision = $this->revisionRow($ids['page'], [[
            'table' => 'contents', 'id' => $ids['content'],
            'fields' => ['content_type' => 'blocks', 'content' => '<div>rendered only</div>', 'blocks_data' => ''],
        ]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(__('blox_revision_not_loadable'));
        PageBloxDocument::revisionEditableDocument($revision, PageBloxDocument::load($ids['page']));
    }

    public function testRevisionHtmlSkipsBlocksRowsAndPicksFirstHtml(): void
    {
        self::assertSame('', PageBloxDocument::revisionHtml(['snapshot' => '{"targets":[]}']));
        $revision = ['snapshot' => json_encode(['targets' => [
            ['table' => 'contents', 'id' => 1, 'fields' => ['content_type' => 'blocks', 'content' => '<div>rendered</div>', 'blocks_data' => '[]']],
            ['table' => 'channels', 'id' => 2, 'fields' => ['content' => '<p>keep me</p>']],
        ]])];
        self::assertSame('<p>keep me</p>', PageBloxDocument::revisionHtml($revision));
    }

    // ---- 编辑器 / 端点契约（源码级） ----

    public function testEditorLoadsRevisionAsOneUndoableCommandAndKeepsBaseRevision(): void
    {
        $editor = bloxEditorSourceForTest();
        self::assertStringContainsString('loadRevisionDraft(rev)', $editor);
        self::assertStringContainsString('runCommand("load-revision"', $editor);
        self::assertStringContainsString('action=blocks&type=page', $editor);

        $method = substr($editor, strpos($editor, 'loadRevisionDraft(rev)'));
        $method = substr($method, 0, strpos($method, 'homeAction(action)'));
        // 载入必须先落一个可回退的历史点，且绝不推进服务器 base_revision
        self::assertStringContainsString('flushHistory(true)', $method);
        self::assertStringNotContainsString('baseRevision =', $method);
        self::assertStringContainsString('queueDraftRecovery()', $method);
    }

    public function testRevisionEndpointGatesCanvasLoadBehindBloxEdit(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/revision.php');
        $blocks = substr($source, strpos($source, "action === 'blocks'"));
        $blocks = substr($blocks, 0, strpos($blocks, "action === 'preview'"));
        self::assertStringContainsString("requirePermission('blox_edit')", $blocks);
        self::assertStringContainsString('revisionEditableDocument', $blocks);
        self::assertStringContainsString('$loadOwned', $blocks, '载入数据源必须走归属校验');
    }
}
