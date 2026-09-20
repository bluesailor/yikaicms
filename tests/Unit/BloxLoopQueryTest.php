<?php
/** v1.25 容器 Loop：_query 归一 / 取数复用 {yk:list} 契约 / 授权与冻结 / 渲染循环契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BloxLoopQuery;
use BloxProtectedFields;
use BloxQueryLoopPolicy;
use RuntimeException;
use TagEngine;
use Yikai\Tests\TestCase;

require_once dirname(__DIR__) . '/Controllers/_fixtures/helpers.php';
require_once ROOT_PATH . '/includes/TagEngine.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxLoopQueryTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                title TEXT NOT NULL, slug TEXT, summary TEXT, cover TEXT, content TEXT,
                type TEXT DEFAULT 'article',
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL
            )",
            "CREATE TABLE product_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, status INTEGER DEFAULT 1
            )",
            "CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                category_id INTEGER DEFAULT 0,
                title TEXT NOT NULL, model TEXT, summary TEXT, cover TEXT,
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0,
                is_hot INTEGER DEFAULT 0, is_new INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_test_config'] = [];
        TagEngine::setItem(null);
        BloxLoopQuery::resetForTests();
    }

    private function seedNews(): void
    {
        // channel:N 源按栏目 type 过滤内容行（与 list-dynamic 同语义），种子保持 type 对齐
        $this->insertRow('channels', ['name' => '新闻', 'slug' => 'news', 'type' => 'article']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'First & Co', 'publish_time' => 200]);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Second', 'publish_time' => 100]);
    }

    // ── _query 归一化（安全边界） ────────────────────────────────────

    public function testNormalizationDropsIllegalQueriesAndClampsValues(): void
    {
        // 非法 source / 非数组：整体删除
        self::assertArrayNotHasKey('_query', BloxLoopQuery::normalizeElementData(['_query' => 'type:article']));
        self::assertArrayNotHasKey('_query', BloxLoopQuery::normalizeElementData(['_query' => ['source' => 'evil;drop']]));
        self::assertArrayNotHasKey('_query', BloxLoopQuery::normalizeElementData(['_query' => ['source' => 'channel:0']]));

        $data = BloxLoopQuery::normalizeElementData(['_query' => [
            'source' => 'type:article', 'cat' => ' news ', 'limit' => 999, 'offset' => -5,
            'keyword' => str_repeat('k', 300), 'recommend' => '1', 'order' => 'evil',
            'pagination' => 'numbers', 'empty_mode' => 'hidden', 'junk' => 'x',
        ]]);
        $query = $data['_query'];
        self::assertSame('type:article', $query['source']);
        self::assertSame('news', $query['cat']);
        self::assertSame(50, $query['limit']);
        self::assertArrayNotHasKey('offset', $query);
        self::assertSame(200, mb_strlen($query['keyword']));
        self::assertTrue($query['recommend']);
        self::assertArrayNotHasKey('order', $query);
        self::assertSame('numbers', $query['pagination']);
        self::assertSame('hidden', $query['empty_mode']);
        self::assertArrayNotHasKey('junk', $query);
    }

    // ── 取数：与 {yk:list} 同一契约 ─────────────────────────────────

    public function testRunFetchesRowsAndMemoizesPerRequest(): void
    {
        $this->seedNews();
        $query = ['source' => 'type:article', 'limit' => 10];
        $first = BloxLoopQuery::run($query, '');
        self::assertCount(2, $first['rows']);
        self::assertSame('First & Co', $first['rows'][0]['title']);
        self::assertFalse($first['is_product']);

        // 同请求同查询：结果复用（插入新行不影响本请求内的第二次取数）
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Third']);
        self::assertCount(2, BloxLoopQuery::run($query, '')['rows']);
        BloxLoopQuery::resetForTests();
        self::assertCount(3, BloxLoopQuery::run($query, '')['rows']);
    }

    public function testChannelSourceResolvesTypeAndUnknownChannelYieldsEmpty(): void
    {
        $this->seedNews();
        $viaChannel = BloxLoopQuery::run(['source' => 'channel:1', 'limit' => 10], '');
        self::assertCount(2, $viaChannel['rows']);
        // 不存在/停用的栏目：空结果，不退化为全量（{yk:list} cat 未命中同款守卫）
        self::assertSame([], BloxLoopQuery::run(['source' => 'channel:99', 'limit' => 10], '')['rows']);
    }

    // ── 渲染循环：{{loop.*}} 指向当前行、空态、分页包裹 ───────────────

    public function testContainerLoopRendersChildrenPerRowWithLoopTags(): void
    {
        $this->seedNews();
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'loop-host', 'type' => 'div',
                'data' => [
                    '_query' => ['source' => 'type:article', 'limit' => 10],
                    'children' => [[
                        'id' => 'loop-card', 'type' => 'heading',
                        'data' => ['text' => '{{loop.index}}. {{loop.title}}'],
                    ]],
                ],
            ]]]],
        ]]));

        self::assertStringContainsString('1. First &amp; Co', $out);
        self::assertStringContainsString('2. Second', $out);
        // 单子节点：卡片自身就是循环项，不包 yk-query-item
        self::assertStringNotContainsString('yk-query-item', $out);
        // 上下文出栈干净
        self::assertNull(TagEngine::currentContext());
    }

    public function testLoopEmptyStateMessageAndHidden(): void
    {
        $host = static fn (array $query): string => (string) json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'empty-host', 'type' => 'div',
                'data' => ['_query' => $query, 'children' => [
                    ['id' => 'empty-card', 'type' => 'heading', 'data' => ['text' => '{{loop.title}}']],
                ]],
            ]]]],
        ]]);

        $message = BlockRenderer::render($host(['source' => 'type:article', 'limit' => 5, 'empty' => '暂无内容 <x>']));
        self::assertStringContainsString('yk-query-empty', $message);
        self::assertStringContainsString('暂无内容 &lt;x&gt;', $message);

        $hidden = BlockRenderer::render($host(['source' => 'type:article', 'limit' => 5, 'empty_mode' => 'hidden']));
        self::assertStringNotContainsString('yk-div', $hidden);
        self::assertStringNotContainsString('yk-query-empty', $hidden);
    }

    public function testLoopPaginationRendersInsideFullWidthWrap(): void
    {
        $this->seedNews();
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Third']);
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'paged-host', 'type' => 'div',
                'data' => [
                    '_query' => ['source' => 'type:article', 'limit' => 2, 'pagination' => 'numbers'],
                    'children' => [
                        ['id' => 'paged-a', 'type' => 'heading', 'data' => ['text' => '{{loop.title}}']],
                        ['id' => 'paged-b', 'type' => 'text', 'data' => ['html' => '<p>{{loop.date}}</p>']],
                    ],
                ],
            ]]]],
        ]]));

        self::assertStringContainsString('yk-query-item', $out); // 多子节点：包裹行边界
        self::assertStringContainsString('yk-query-pagination-wrap', $out);
        self::assertStringContainsString('col-span-full', $out);
        self::assertStringContainsString('yk-query-pagination', $out);
    }

    // ── 授权与冻结 ──────────────────────────────────────────────────

    public function testPolicyBlocksContainerQueriesWithoutLicenseAndFreezeProtectsThem(): void
    {
        $element = ['id' => 'q1', 'type' => 'div', 'data' => [
            '_query' => ['source' => 'type:article', 'limit' => 6],
            'children' => [['id' => 'q1c', 'type' => 'heading', 'data' => ['text' => 'x']]],
        ]];
        $sections = [['id' => 'qs', 'columns' => [['id' => 'qc', 'elements' => [$element]]]]];

        BloxQueryLoopPolicy::assertSectionsAllowed($sections, true);
        try {
            BloxQueryLoopPolicy::assertSectionsAllowed($sections, false);
            self::fail('未授权站点不得新增容器查询');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_query_loop_license_required', $e->getMessage());
        }

        // 冻结：能力失效时既有 _query 不可修改（limit 6 → 7 被拒），原样保留可通过
        BloxProtectedFields::forValidation($sections, $sections, ['query_loop']);
        $tampered = $sections;
        $tampered[0]['columns'][0]['elements'][0]['data']['_query']['limit'] = 7;
        $this->expectExceptionMessage('blox_protected_fields_changed');
        BloxProtectedFields::forValidation($tampered, $sections, ['query_loop']);
    }
}
