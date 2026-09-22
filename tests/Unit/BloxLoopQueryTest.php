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
            "CREATE TABLE metas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_type TEXT NOT NULL,
                owner_id INTEGER NOT NULL DEFAULT 0,
                meta_key TEXT NOT NULL,
                meta_value TEXT,
                created_at INTEGER DEFAULT 0,
                updated_at INTEGER DEFAULT 0
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
        // 生产语义：'list' 栏目存放 type='article' 的内容行；channel:/current 源经
        // channelContentType 映射后按 article 过滤（2026-09-20 修复前直接拿栏目 type 恒空）
        $this->insertRow('channels', ['name' => '新闻', 'slug' => 'news', 'type' => 'list']);
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

    public function testFilterNormalizationEnforcesWhitelistAndClamps(): void
    {
        $data = BloxLoopQuery::normalizeElementData(['_query' => [
            'source' => 'type:article',
            'filters' => [
                ['field' => 'Color', 'op' => '=', 'value' => ' red '],        // 字段小写化、值修剪
                ['field' => 'price', 'op' => '>', 'value' => 'abc'],          // 数值算子非数值：丢
                ['field' => 'price', 'op' => 'between', 'value' => '10,'],    // between 缺右端：丢
                ['field' => 'price', 'op' => 'between', 'value' => ' 10 , 90 '],
                ['field' => 'flag', 'op' => 'empty', 'value' => 'ignored'],   // empty 剥值
                ['field' => 'bad field', 'op' => '=', 'value' => 'x'],        // 非法字段名：丢
                ['field' => 'kind', 'op' => 'drop', 'value' => 'x'],          // 非法算子：丢
                ['field' => 'a', 'op' => '=', 'value' => '1'],
                ['field' => 'b', 'op' => '=', 'value' => '2'],
                ['field' => 'c', 'op' => '=', 'value' => '3'],                // 第 6 条有效：截断
                'junk',
            ],
        ]]);
        $filters = $data['_query']['filters'];
        self::assertCount(5, $filters);
        self::assertSame(['field' => 'color', 'op' => '=', 'value' => 'red'], $filters[0]);
        self::assertSame(['field' => 'price', 'op' => 'between', 'value' => '10,90'], $filters[1]);
        self::assertSame(['field' => 'flag', 'op' => 'empty', 'value' => ''], $filters[2]);
        self::assertSame('b', $filters[4]['field']);

        // 全部非法：filters 键整体不落盘
        $none = BloxLoopQuery::normalizeElementData(['_query' => [
            'source' => 'type:article', 'filters' => [['field' => '1bad', 'op' => '=', 'value' => 'x']],
        ]]);
        self::assertArrayNotHasKey('filters', $none['_query']);
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

    /** 自定义字段过滤：metas EXISTS 端到端（owner 走 resolveExtFieldOwner、+0 数值归一、缺行语义）。 */
    public function testRunAppliesMetaFilters(): void
    {
        // owner_type 与取数端同源：都过 resolveExtFieldOwner（TagEngineTest 的进程级
        // shim 直接返回 type，即 'article'；生产版本把内置类型归到 'content'——
        // 存/取共用同一函数，两端永远一致，此处按 shim 语义播种）
        $this->seedNews(); // id=1 First & Co, id=2 Second
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Third']); // id=3 无 meta
        foreach ([1 => ['color' => 'red', 'price' => '100'], 2 => ['color' => 'sky blue', 'price' => '250']] as $id => $metas) {
            foreach ($metas as $k => $v) {
                $this->insertRow('metas', ['owner_type' => 'article', 'owner_id' => $id, 'meta_key' => $k, 'meta_value' => $v]);
            }
        }
        $titles = function (array $filters): array {
            BloxLoopQuery::resetForTests();
            $run = BloxLoopQuery::run(['source' => 'type:article', 'limit' => 10, 'filters' => $filters], '');
            return array_column($run['rows'], 'title');
        };

        self::assertSame(['First & Co'], $titles([['field' => 'color', 'op' => '=', 'value' => 'red']]));
        // 数值比较：meta_value 是 TEXT，靠 +0 归一（'250'+0 > 150）
        self::assertSame(['Second'], $titles([['field' => 'price', 'op' => '>', 'value' => '150']]));
        self::assertSame(['First & Co'], $titles([['field' => 'price', 'op' => 'between', 'value' => '50,150']]));
        self::assertSame(['First & Co', 'Second'], $titles([['field' => 'color', 'op' => 'in', 'value' => 'red, sky blue']]));
        // like 的 % 通配来自代码侧包裹，值内元字符已转义（'!' ESCAPE）
        self::assertSame(['Second'], $titles([['field' => 'color', 'op' => 'like', 'value' => 'blu']]));
        // != 与 empty：meta 行缺失视为「值不同 / 为空」——无 meta 的 Third 命中
        self::assertSame(['Second', 'Third'], $titles([['field' => 'color', 'op' => '!=', 'value' => 'red']]));
        self::assertSame(['Third'], $titles([['field' => 'color', 'op' => 'empty', 'value' => '']]));
        // AND 平铺
        self::assertSame([], $titles([
            ['field' => 'color', 'op' => '=', 'value' => 'red'],
            ['field' => 'price', 'op' => '>=', 'value' => '200'],
        ]));
    }

    /** 分页计数走同一 filters：总数随过滤收敛，页码用容器自己的分页参数。 */
    public function testMetaFiltersNarrowPaginationCount(): void
    {
        $this->seedNews();
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Third']);
        foreach ([1, 2, 3] as $id) {
            $this->insertRow('metas', ['owner_type' => 'article', 'owner_id' => $id, 'meta_key' => 'featured', 'meta_value' => $id === 3 ? '' : '1']);
        }
        $query = [
            'source' => 'type:article', 'limit' => 1, 'pagination' => 'numbers',
            'filters' => [['field' => 'featured', 'op' => '=', 'value' => '1']],
        ];
        $run = BloxLoopQuery::run($query, 'ykq_ftest');
        self::assertCount(1, $run['rows']);
        // 命中 2 行 / limit 1 = 2 页；第 3 页链接不存在（未过滤时 3 行会出现第 3 页）
        self::assertStringContainsString('ykq_ftest=2', $run['pagination']);
        self::assertStringNotContainsString('ykq_ftest=3', $run['pagination']);
    }

    /** current 源：继承 list.php 设置的请求级上下文；无上下文=空态（绝不全量）。 */
    public function testCurrentSourceInheritsListPageContext(): void
    {
        $this->seedNews();
        $this->insertRow('channels', ['name' => '案例', 'slug' => 'cases', 'type' => 'case']);
        $this->insertRow('contents', ['channel_id' => 2, 'title' => 'Case A', 'type' => 'case']);

        // 无上下文（首页/详情/预览/编辑器）：空态
        self::assertSame([], BloxLoopQuery::run(['source' => 'current', 'limit' => 10], '')['rows']);

        // 'list' 栏目上下文：经 channelContentType 映射按 article 取当前栏目行
        BloxLoopQuery::setCurrentContext([
            'channel' => ['id' => 1, 'type' => 'list', 'status' => 1],
            'keyword' => '',
            'page_param' => 'page',
        ]);
        BloxLoopQuery::resetForTests(); // resetForTests 连上下文一起清
        self::assertSame([], BloxLoopQuery::run(['source' => 'current', 'limit' => 10], '')['rows']);

        BloxLoopQuery::setCurrentContext([
            'channel' => ['id' => 1, 'type' => 'list', 'status' => 1],
            'keyword' => '',
            'page_param' => 'page',
        ]);
        $run = BloxLoopQuery::run(['source' => 'current', 'limit' => 10], '');
        self::assertSame(['First & Co', 'Second'], array_column($run['rows'], 'title'));

        // 上下文关键词收敛结果；分页锁定主列表参数（不用节点派生的 ykq_*）
        BloxLoopQuery::setCurrentContext([
            'channel' => ['id' => 1, 'type' => 'list', 'status' => 1],
            'keyword' => 'Second',
            'page_param' => 'page',
        ]);
        $narrowed = BloxLoopQuery::run(
            ['source' => 'current', 'limit' => 1, 'pagination' => 'numbers'],
            'ykq_nodeparam'
        );
        self::assertSame(['Second'], array_column($narrowed['rows'], 'title'));
        self::assertStringNotContainsString('ykq_nodeparam', $narrowed['pagination']);

        BloxLoopQuery::setCurrentContext(null);
    }

    public function testChannelSourceResolvesTypeAndUnknownChannelYieldsEmpty(): void
    {
        $this->seedNews();
        $viaChannel = BloxLoopQuery::run(['source' => 'channel:1', 'limit' => 10], '');
        self::assertCount(2, $viaChannel['rows']);
        // 不存在/停用的栏目：空结果，不退化为全量（{yk:list} cat 未命中同款守卫）
        self::assertSame([], BloxLoopQuery::run(['source' => 'channel:99', 'limit' => 10], '')['rows']);
    }

    public function testProductShowcaseRendersLiveCardsAndKeepsEmptyMessage(): void
    {
        $raw = (string) file_get_contents(ROOT_PATH . '/templates/blox/sections/product-showcase.json');
        $prepared = \BloxTemplateImporter::prepare($raw);
        $json = json_encode(['sections' => $prepared['sections']], JSON_THROW_ON_ERROR);
        $empty = BlockRenderer::render($json);
        self::assertStringContainsString('yk-query-empty', $empty);
        $this->insertRow('product_categories', ['name' => 'Equipment', 'slug' => 'equipment']);
        foreach (['Machine & tools', 'Second product', 'Third product', 'Not in showcase'] as $index => $title) {
            $this->insertRow('products', [
                'category_id' => 1, 'title' => $title, 'cover' => '/images/company-about-v2.webp',
                'summary' => str_repeat('Details ', $index + 1), 'sort_order' => $index,
            ]);
        }
        BloxLoopQuery::resetForTests();
        $html = BlockRenderer::render($json);
        self::assertStringContainsString('Machine &amp; tools', $html);
        self::assertStringContainsString('Third product', $html);
        self::assertStringNotContainsString('Not in showcase', $html);
        self::assertStringNotContainsString('{{loop.', $html);
        self::assertStringNotContainsString('yk-query-empty', $html);
        self::assertSame(3, substr_count($html, 'yk-card-hover-lift'));
        self::assertSame(1, substr_count($html, 'data-stagger'));
        self::assertStringContainsString('aspect-ratio:4 / 3', $html);
        self::assertStringContainsString('href="', $html);
        self::assertNull(TagEngine::currentContext());
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

    /** v1.24-③ 结构化属性绑定：Image 的 src/alt/link_url 吃 {{loop.*}}（卡片图的前置能力）。 */
    public function testLoopImageBindsCoverAndUrlAttributes(): void
    {
        $this->insertRow('channels', ['name' => '新闻', 'slug' => 'news', 'type' => 'list']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Pic "A"', 'cover' => '/uploads/2026/a.jpg']);
        $out = BlockRenderer::render(json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'id' => 'img-host', 'type' => 'div',
                'data' => [
                    '_query' => ['source' => 'type:article', 'limit' => 5],
                    'children' => [[
                        'id' => 'img-card', 'type' => 'image',
                        'data' => [
                            'src' => '{{loop.cover}}', 'alt' => '{{loop.title}}',
                            'click_action' => 'link', 'link_url' => '{{loop.url}}',
                        ],
                    ]],
                ],
            ]]]],
        ]]));

        self::assertStringContainsString('/uploads/2026/a.jpg', $out);
        self::assertStringContainsString('alt="Pic &quot;A&quot;"', $out);
        self::assertStringContainsString('href="/news/article/1.html"', $out);
        self::assertStringNotContainsString('{{loop.', $out);
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
