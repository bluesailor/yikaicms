<?php
/**
 * 2.0.3 查询循环增强（对标 Bricks Query Loop）：规格归一、执行器、分类/下载/招聘来源、
 * 相关内容与嵌套循环、循环标签、AJAX 分页标记与片段响应、前台筛选、画布预览。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlockRenderer;
use BloxLoopQuery;
use BloxQueryFilters;
use BloxQueryFragment;
use BloxQueryLoopPolicy;
use BloxQuerySpec;
use QueryFilterElement;
use RuntimeException;
use TagEngine;
use Yikai\Tests\TestCase;

require_once dirname(__DIR__) . '/Controllers/_fixtures/helpers.php';
require_once ROOT_PATH . '/includes/TagEngine.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxQueryLoopEnhancementTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'list', image TEXT DEFAULT '', description TEXT DEFAULT '',
                status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL,
                title TEXT NOT NULL, slug TEXT, summary TEXT, cover TEXT, content TEXT,
                type TEXT DEFAULT 'article', status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0, publish_time INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN', deleted_at INTEGER DEFAULT NULL
            )",
            "CREATE TABLE metas (
                id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT NOT NULL, owner_id INTEGER NOT NULL DEFAULT 0,
                meta_key TEXT NOT NULL, meta_value TEXT, created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE product_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, image TEXT DEFAULT '', description TEXT DEFAULT '',
                status INTEGER DEFAULT 1, sort_order INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE products (
                id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER DEFAULT 0,
                title TEXT NOT NULL, model TEXT, summary TEXT, cover TEXT, price REAL DEFAULT 0,
                status INTEGER DEFAULT 1, is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0,
                is_hot INTEGER DEFAULT 0, is_new INTEGER DEFAULT 0, views INTEGER DEFAULT 0,
                sort_order INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN', deleted_at INTEGER DEFAULT NULL
            )",
            "CREATE TABLE download_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, name_en TEXT DEFAULT '', name_ja TEXT DEFAULT '',
                slug TEXT DEFAULT '', description TEXT DEFAULT '', sort_order INTEGER DEFAULT 0,
                status INTEGER DEFAULT 1, created_at INTEGER DEFAULT 0
            )",
            "CREATE TABLE downloads (
                id INTEGER PRIMARY KEY AUTOINCREMENT, category_id INTEGER DEFAULT 0, title TEXT NOT NULL,
                description TEXT, cover TEXT DEFAULT '', file_size INTEGER DEFAULT 0, file_ext TEXT DEFAULT '',
                download_count INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0, status INTEGER DEFAULT 1,
                created_at INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0, deleted_at INTEGER DEFAULT NULL
            )",
            "CREATE TABLE jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, summary TEXT, location TEXT DEFAULT '',
                salary TEXT DEFAULT '', views INTEGER DEFAULT 0, is_top INTEGER DEFAULT 0, sort_order INTEGER DEFAULT 0,
                status INTEGER DEFAULT 1, publish_time INTEGER DEFAULT 0, created_at INTEGER DEFAULT 0,
                updated_at INTEGER DEFAULT 0, deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_test_config'] = [];
        $_GET = [];
        TagEngine::setItem(null);
        BloxLoopQuery::resetForTests();
        BloxQueryFragment::setSinkForTests(null);
    }

    protected function tearDown(): void
    {
        $_GET = [];
        TagEngine::setItem(null);
        BloxQueryFragment::setSinkForTests(null);
        parent::tearDown();
    }

    /** 新闻（1）> 行业（2）、公司（3）；案例（4）。 */
    private function seedContent(): void
    {
        $this->insertRow('channels', ['name' => '新闻', 'slug' => 'news', 'sort_order' => 1]);
        $this->insertRow('channels', ['name' => '行业', 'slug' => 'industry', 'parent_id' => 1, 'sort_order' => 2]);
        $this->insertRow('channels', ['name' => '公司', 'slug' => 'company', 'parent_id' => 1, 'sort_order' => 3]);
        $this->insertRow('channels', ['name' => '案例', 'slug' => 'cases', 'type' => 'case', 'sort_order' => 4]);
        $day = 86400;
        $now = time();
        foreach ([
            ['channel_id' => 1, 'title' => 'Alpha', 'publish_time' => $now - 1 * $day, 'views' => 5],
            ['channel_id' => 2, 'title' => 'Bravo', 'publish_time' => $now - 3 * $day, 'views' => 50],
            ['channel_id' => 2, 'title' => 'Charlie', 'publish_time' => $now - 40 * $day, 'views' => 20, 'is_hot' => 1],
            ['channel_id' => 3, 'title' => 'Delta', 'publish_time' => $now - 400 * $day, 'views' => 1],
            ['channel_id' => 4, 'title' => 'Case One', 'type' => 'case', 'publish_time' => $now - 2 * $day],
        ] as $row) {
            $this->insertRow('contents', $row);
        }
    }

    /** @param array<string,mixed> $query @return list<string> */
    private function titles(array $query): array
    {
        BloxLoopQuery::resetForTests();
        return array_column(BloxLoopQuery::run($query, '')['rows'], 'title');
    }

    /** @param array<string,mixed> $hostData */
    private function renderHost(array $hostData, array $children, string $id = 'host1'): string
    {
        return BlockRenderer::render((string) json_encode([[
            'settings' => [],
            'columns' => [['elements' => [['id' => $id, 'type' => 'div', 'data' => $hostData + ['children' => $children]]]]],
        ]]));
    }

    // ── 规格归一 ─────────────────────────────────────────────────────

    public function testSpecNormalizesNewKeysAndDropsInvalidOnes(): void
    {
        $query = BloxQuerySpec::normalize([
            'source' => 'type:article', 'cats' => ['2', 'industry', 'bad slug', '2'], 'cats_exclude' => '3',
            'children' => true, 'ids' => '5, 9，x, 5, 0', 'exclude_ids' => [7], 'exclude_current' => 1,
            'scope' => 'related', 'date_within' => 99999, 'date_from' => '2026-01-01', 'date_to' => 'tomorrow',
            'order' => 'title_desc', 'pagination' => 'load_more', 'load_more_text' => str_repeat('更', 60),
            'filters' => [['field' => 'a', 'op' => '=', 'value' => '1'], ['field' => 'b', 'op' => '=', 'value' => '2']],
            'filter_relation' => 'or', 'price_min' => '-3',
        ]);
        self::assertIsArray($query);
        self::assertSame(['2', 'industry'], $query['cats']);
        self::assertSame(['3'], $query['cats_exclude']);
        self::assertTrue($query['children']);
        self::assertSame([5, 9], $query['ids']);
        self::assertSame([7], $query['exclude_ids']);
        self::assertTrue($query['exclude_current']);
        self::assertSame('related', $query['scope']);
        self::assertSame(3650, $query['date_within']);
        self::assertSame('2026-01-01', $query['date_from']);
        self::assertArrayNotHasKey('date_to', $query);
        self::assertSame('title_desc', $query['order']);
        self::assertSame('load_more', $query['pagination']);
        self::assertSame(40, mb_strlen($query['load_more_text']));
        self::assertSame('or', $query['filter_relation']);
        self::assertArrayNotHasKey('price_min', $query);

        // 随机顺序 / 嵌套循环不分页；手动顺序必须有 ids；分类循环丢弃条目专用键
        self::assertArrayNotHasKey('pagination', BloxQuerySpec::normalize(['source' => 'type:article', 'order' => 'random', 'pagination' => 'numbers']));
        self::assertArrayNotHasKey('pagination', BloxQuerySpec::normalize(['source' => 'type:article', 'scope' => 'parent', 'pagination' => 'ajax']));
        self::assertArrayNotHasKey('order', BloxQuerySpec::normalize(['source' => 'type:article', 'order' => 'manual']));
        $terms = BloxQuerySpec::normalize(['source' => 'terms:product', 'keyword' => 'x', 'parent_term' => '0', 'hide_empty' => 1, 'scope' => 'related', 'order' => 'count']);
        self::assertSame(['source' => 'terms:product', 'limit' => 6, 'parent_term' => 0, 'hide_empty' => true, 'order' => 'count'], $terms);
        self::assertNull(BloxQuerySpec::normalize(['source' => 'terms:users']));

        // 旧文档归一结果不变（键集合与值）
        self::assertSame(['source' => 'type:article', 'cat' => 'news', 'limit' => 6, 'pagination' => 'numbers'],
            BloxQuerySpec::normalize(['source' => 'type:article', 'cat' => 'news', 'pagination' => 'numbers']));
    }

    // ── 执行器：分类、ID、排序、日期、OR ─────────────────────────────

    public function testCategoriesChildrenExclusionIdsAndOrdering(): void
    {
        $this->seedContent();
        // 默认：置顶 → 发布时间倒序；内容默认不含子栏目
        self::assertSame(['Alpha', 'Bravo', 'Charlie', 'Delta'], $this->titles(['source' => 'type:article', 'limit' => 10]));
        self::assertSame(['Alpha'], $this->titles(['source' => 'type:article', 'cat' => 'news', 'limit' => 10]));
        self::assertSame(['Alpha', 'Bravo', 'Charlie', 'Delta'], $this->titles(['source' => 'type:article', 'cats' => ['1'], 'children' => true, 'limit' => 10]));
        self::assertSame(['Alpha', 'Delta'], $this->titles(['source' => 'type:article', 'cats' => ['1'], 'children' => true, 'cats_exclude' => ['industry'], 'limit' => 10]));
        // 多分类包含（OR）；解析不到的分类：空，不退化为全量
        self::assertSame(['Bravo', 'Charlie', 'Delta'], $this->titles(['source' => 'type:article', 'cats' => ['2', '3'], 'limit' => 10]));
        self::assertSame([], $this->titles(['source' => 'type:article', 'cats' => ['nope'], 'limit' => 10]));
        // ID 包含 + 手动顺序；ID 排除
        self::assertSame(['Delta', 'Alpha'], $this->titles(['source' => 'type:article', 'ids' => [4, 1], 'order' => 'manual', 'limit' => 10]));
        self::assertSame(['Alpha', 'Delta'], $this->titles(['source' => 'type:article', 'exclude_ids' => [2, 3], 'limit' => 10]));
        // 排序
        self::assertSame(['Delta', 'Charlie', 'Bravo', 'Alpha'], $this->titles(['source' => 'type:article', 'order' => 'oldest', 'limit' => 10]));
        self::assertSame(['Bravo', 'Charlie', 'Alpha', 'Delta'], $this->titles(['source' => 'type:article', 'order' => 'views', 'limit' => 10]));
        self::assertSame(['Delta', 'Charlie', 'Bravo', 'Alpha'], $this->titles(['source' => 'type:article', 'order' => 'title_desc', 'limit' => 10]));
        self::assertCount(4, $this->titles(['source' => 'type:article', 'order' => 'random', 'limit' => 10]));
        // 日期：最近 N 天 / 起止日期
        self::assertSame(['Alpha', 'Bravo'], $this->titles(['source' => 'type:article', 'date_within' => 7, 'limit' => 10]));
        self::assertSame(['Delta'], $this->titles(['source' => 'type:article', 'date_to' => date('Y-m-d', time() - 100 * 86400), 'limit' => 10]));
        // 内容类型隔离：案例不混入文章
        self::assertSame(['Case One'], $this->titles(['source' => 'type:case', 'limit' => 10]));
        // 关键词里的 % 是字面量
        self::assertSame([], $this->titles(['source' => 'type:article', 'keyword' => '%', 'limit' => 10]));
    }

    public function testMetaFiltersSupportOrRelation(): void
    {
        $this->seedContent();
        $owner = function_exists('resolveExtFieldOwner') ? resolveExtFieldOwner('article') : 'content';
        $this->insertRow('metas', ['owner_type' => $owner, 'owner_id' => 1, 'meta_key' => 'color', 'meta_value' => 'red']);
        $this->insertRow('metas', ['owner_type' => $owner, 'owner_id' => 2, 'meta_key' => 'size', 'meta_value' => 'xl']);
        $filters = [['field' => 'color', 'op' => '=', 'value' => 'red'], ['field' => 'size', 'op' => '=', 'value' => 'xl']];
        self::assertSame([], $this->titles(['source' => 'type:article', 'limit' => 10, 'filters' => $filters]));
        self::assertSame(['Alpha', 'Bravo'], $this->titles(['source' => 'type:article', 'limit' => 10, 'filters' => $filters, 'filter_relation' => 'or']));
    }

    // ── 新来源 ──────────────────────────────────────────────────────

    public function testTermDownloadAndJobSources(): void
    {
        $this->seedContent();
        $terms = BloxLoopQuery::run(['source' => 'terms:content', 'parent_term' => 1, 'limit' => 10], '');
        self::assertSame(['行业', '公司'], array_column($terms['rows'], 'title'));
        self::assertSame(['2', '1'], array_map('strval', array_column($terms['rows'], 'item_count')));
        self::assertSame('term', $terms['kind']);
        BloxLoopQuery::resetForTests();
        self::assertSame(['行业', '新闻', '公司'], array_column(BloxLoopQuery::run(['source' => 'terms:content', 'hide_empty' => true, 'order' => 'count', 'exclude_ids' => [4], 'cats_exclude' => ['4'], 'limit' => 10], '')['rows'], 'title'));

        $this->insertRow('download_categories', ['name' => '手册', 'name_en' => 'Manuals', 'slug' => 'manuals']);
        $this->insertRow('downloads', ['category_id' => 1, 'title' => 'Guide', 'description' => 'PDF guide', 'file_size' => 2048]);
        $this->insertRow('downloads', ['category_id' => 0, 'title' => 'Hidden', 'status' => 0]);
        $this->insertRow('jobs', ['title' => 'Engineer', 'location' => 'Shanghai', 'is_top' => 1]);
        $this->insertRow('jobs', ['title' => 'Closed', 'status' => 0]);
        BloxLoopQuery::resetForTests();
        $downloads = BloxLoopQuery::run(['source' => 'type:download', 'cats' => ['manuals'], 'limit' => 10], '');
        self::assertSame(['Guide'], array_column($downloads['rows'], 'title'));
        self::assertSame('PDF guide', $downloads['rows'][0]['summary'], 'description doubles as summary');
        self::assertSame(['Engineer'], $this->titles(['source' => 'type:job', 'top' => true, 'limit' => 10]));

        $html = $this->renderHost(['_query' => ['source' => 'type:download', 'limit' => 5]], [
            ['id' => 'dl', 'type' => 'heading', 'data' => ['text' => '{{loop.title}} · {{loop.category}}', 'url' => '{{loop.url}}']],
        ]);
        self::assertStringContainsString('Guide · 手册', $html);
        self::assertStringContainsString('/download.php?fid=1', $html);
    }

    // ── 上下文：相关内容、排除当前、嵌套循环 ─────────────────────────

    public function testRelatedScopeAndExcludeCurrentUseTheDetailItem(): void
    {
        $this->seedContent();
        // 不在详情页：相关内容为空态（绝不退化为全量）
        self::assertSame([], $this->titles(['source' => 'type:article', 'scope' => 'related', 'limit' => 10]));
        TagEngine::setItem(['id' => 2, 'channel_id' => 2, 'type' => 'article', 'title' => 'Bravo'], 'content');
        self::assertSame(['Charlie'], $this->titles(['source' => 'type:article', 'scope' => 'related', 'limit' => 10]));
        self::assertSame(['Alpha', 'Charlie', 'Delta'], $this->titles(['source' => 'type:article', 'exclude_current' => true, 'limit' => 10]));
        // 类型不符（产品循环在文章详情页）：排除当前不生效，相关内容为空
        self::assertSame([], $this->titles(['source' => 'type:product', 'scope' => 'related', 'limit' => 10]));
    }

    public function testNestedLoopReadsTheOuterRow(): void
    {
        $this->seedContent();
        $html = $this->renderHost(['_query' => ['source' => 'terms:content', 'parent_term' => 1, 'limit' => 10]], [[
            'id' => 'card', 'type' => 'div', 'data' => ['children' => [
                ['id' => 'term-title', 'type' => 'heading', 'data' => ['text' => 'T:{{loop.title}}']],
                ['id' => 'inner', 'type' => 'div', 'data' => [
                    '_query' => ['source' => 'type:article', 'scope' => 'parent', 'limit' => 5],
                    'children' => [['id' => 'item', 'type' => 'heading', 'data' => ['text' => 'I:{{loop.title}}@{{parent.title}}']]],
                ]],
            ]],
        ]]);
        self::assertStringContainsString('T:行业', $html);
        self::assertStringContainsString('I:Bravo@行业', $html);
        self::assertStringContainsString('I:Charlie@行业', $html);
        self::assertStringContainsString('I:Delta@公司', $html);
        self::assertStringNotContainsString('I:Alpha', $html, 'inner loop only lists the outer category');
        self::assertNull(TagEngine::currentContext());
        self::assertNull(BloxLoopQuery::parentRow());
        // 不在循环里：外层范围为空态
        self::assertSame([], $this->titles(['source' => 'type:article', 'scope' => 'parent', 'limit' => 5]));
    }

    // ── 循环标签 ─────────────────────────────────────────────────────

    public function testLoopCounterAndFlagTags(): void
    {
        $this->seedContent();
        $html = $this->renderHost(['_query' => ['source' => 'type:article', 'limit' => 3]], [
            ['id' => 'row', 'type' => 'heading', 'data' => ['text' => '{{loop.index}}/{{loop.count}}/{{loop.total}} first={{loop.first | no}} odd={{loop.odd | no}}']],
        ]);
        self::assertStringContainsString('1/3/4 first=1 odd=1', $html);
        self::assertStringContainsString('2/3/4 first=no odd=no', $html);
        self::assertStringContainsString('3/3/4 first=no odd=1', $html);
    }

    // ── 分页：AJAX / 加载更多 / 无限滚动 + 片段响应 ───────────────────

    public function testLivePaginationMarkupAndFragmentDelivery(): void
    {
        $this->seedContent();
        $children = [['id' => 'row', 'type' => 'heading', 'data' => ['text' => '{{loop.title}}']]];
        $more = $this->renderHost(['_query' => ['source' => 'type:article', 'limit' => 2, 'pagination' => 'load_more', 'load_more_text' => '再来']], $children);
        self::assertStringContainsString('data-yk-query="host1"', $more);
        self::assertMatchesRegularExpression('/data-yk-query-param="ykq_[0-9a-f]{10}"/', $more);
        self::assertStringContainsString('data-yk-query-total="4"', $more);
        self::assertSame(2, substr_count($more, 'data-yk-loop-item="host1"'));
        self::assertStringContainsString('data-yk-query-more', $more);
        self::assertStringContainsString('>再来</a>', $more);
        self::assertStringContainsString('yikay-query.js', implode(',', \BloxAssetCollector::scripts()));

        $param = BloxLoopQuery::paginationParam(['source' => 'type:article'], 'host1');
        $infinite = $this->renderHost(['_query' => ['source' => 'type:article', 'limit' => 2, 'pagination' => 'infinite']], $children);
        self::assertStringContainsString('data-yk-query-infinite', $infinite);
        self::assertStringContainsString($param . '=2', $infinite);
        $ajax = $this->renderHost(['_query' => ['source' => 'type:article', 'limit' => 2, 'pagination' => 'ajax']], $children);
        self::assertStringContainsString('data-yk-query-nav', $ajax);

        // 片段：同一文档、同一上下文，只返回该循环第 2 页的循环项与新的分页标记
        $captured = null;
        BloxQueryFragment::setSinkForTests(static function (array $payload) use (&$captured): void { $captured = $payload; });
        $_GET = [$param => '2', BloxQueryFragment::PARAM => 'host1'];
        BloxLoopQuery::resetForTests();
        $this->renderHost(['_query' => ['source' => 'type:article', 'limit' => 2, 'pagination' => 'load_more']], $children);
        self::assertIsArray($captured);
        self::assertSame(2, $captured['page']);
        self::assertSame(2, $captured['pages']);
        self::assertSame(4, $captured['total']);
        self::assertStringContainsString('Charlie', $captured['html']);
        self::assertStringNotContainsString('Alpha', $captured['html']);
        self::assertStringNotContainsString('data-yk-query-more', $captured['html'], 'last page has no load-more button');
        // 分页链接不泄漏片段参数
        $_GET = [BloxQueryFragment::PARAM => 'host1'];
        $_SERVER['REQUEST_URI'] = '/news?' . BloxQueryFragment::PARAM . '=host1';
        self::assertSame('/news?' . $param . '=2', TagEngine::pageUrl($param, 2));
    }

    // ── 前台筛选 ─────────────────────────────────────────────────────

    public function testFilterOverridesOnlyNarrowTheQuery(): void
    {
        $this->seedContent();
        $prefix = BloxQueryFilters::prefix('host1');
        $overrides = BloxQueryFilters::requestOverrides('host1', [
            $prefix . '_cat' => ['2', 'x'], $prefix . '_s' => ' Char ', $prefix . '_sort' => 'evil',
            $prefix . '_m_color' => 'red,blue', 'other_s' => 'ignored',
        ]);
        self::assertSame(['search' => 'Char', 'cats' => [2], 'meta' => ['color' => ['red', 'blue']]], $overrides);

        // 分类取交集：查询限定了公司栏目，筛选选行业 → 空（不能越出作者设定的范围）
        $run = static fn (array $query, array $over): array => array_column(BloxLoopQuery::run($query, '', $over)['rows'], 'title');
        self::assertSame(['Bravo', 'Charlie'], $run(['source' => 'type:article', 'limit' => 10], ['cats' => [2]]));
        BloxLoopQuery::resetForTests();
        self::assertSame([], $run(['source' => 'type:article', 'cats' => ['3'], 'limit' => 10], ['cats' => [2]]));
        BloxLoopQuery::resetForTests();
        self::assertSame(['Charlie'], $run(['source' => 'type:article', 'limit' => 10], ['search' => 'har']));
        BloxLoopQuery::resetForTests();
        self::assertSame(['Delta', 'Charlie', 'Bravo', 'Alpha'], $run(['source' => 'type:article', 'limit' => 10], ['sort' => 'oldest']));
        BloxLoopQuery::resetForTests();
        self::assertSame(['Alpha', 'Bravo'], $run(['source' => 'type:article', 'limit' => 10], ['days' => 7]));

        // 渲染：筛选后无结果时宿主仍输出（empty_mode=hidden 也保留，否则无法放宽条件）
        $_GET = [$prefix . '_s' => 'zzz'];
        BloxLoopQuery::resetForTests();
        $html = $this->renderHost(['_query' => ['source' => 'type:article', 'limit' => 5, 'empty_mode' => 'hidden']],
            [['id' => 'row', 'type' => 'heading', 'data' => ['text' => '{{loop.title}}']]]);
        self::assertStringContainsString('data-yk-query-empty', $html);
    }

    public function testFilterElementRendersProgressiveForms(): void
    {
        $this->seedContent();
        $prefix = BloxQueryFilters::prefix('host1');
        $_GET = [$prefix . '_cat' => '2', 'utm' => 'a"b', 'ykq_abc' => '3', 'page' => '2'];
        $element = new QueryFilterElement();
        $pills = $element->render(['target' => 'host1', 'filter_type' => 'terms', 'display' => 'pills', 'parent_term' => '1', 'show_count' => true]);
        self::assertStringContainsString('data-yk-filter-target="host1"', $pills);
        self::assertStringContainsString('data-yk-filter-prefix="' . $prefix . '"', $pills);
        self::assertStringContainsString('name="' . $prefix . '_cat" value="2" checked', $pills);
        self::assertStringContainsString('行业<span class="yk-filter-count">2</span>', $pills);
        self::assertStringContainsString('<input type="hidden" name="utm" value="a&quot;b">', $pills, 'other params survive a no-JS submit');
        self::assertStringNotContainsString('name="ykq_abc"', $pills, 'page params reset on a new filter');
        self::assertStringNotContainsString('name="page"', $pills);

        $checkbox = $element->render(['target' => 'host1', 'filter_type' => 'field', 'display' => 'checkbox', 'field_key' => 'color', 'field_options' => "red|红色\nblue"]);
        self::assertStringContainsString('name="' . $prefix . '_m_color[]" value="red"', $checkbox);
        self::assertStringContainsString('<span>红色</span>', $checkbox);

        $reset = $element->render(['target' => 'host1', 'filter_type' => 'reset']);
        self::assertStringContainsString('data-yk-filter-reset="' . $prefix . '"', $reset);
        self::assertStringNotContainsString(' hidden', $reset, 'visible while a filter is active');

        // 摘要：循环已先渲染时服务端直接给出数字
        BloxLoopQuery::rememberTotal('host1', 7);
        self::assertStringContainsString('>7', $element->render(['target' => 'host1', 'filter_type' => 'summary', 'summary_text' => ':count 条']));
        self::assertSame('', $element->render(['filter_type' => 'search']), 'no target → nothing on the site');
    }

    // ── 画布预览与授权 ───────────────────────────────────────────────

    public function testCanvasRendersFirstRowEditableAndTheRestAsInertPreviews(): void
    {
        $this->seedContent();
        $html = BlockRenderer::renderElementNode(['id' => 'host1', 'type' => 'div', 'data' => [
            '_query' => ['source' => 'type:article', 'limit' => 3, 'pagination' => 'load_more'],
            'children' => [['id' => 'row', 'type' => 'heading', 'data' => ['text' => '{{loop.title}}']]],
        ]], 0, true, [0, 0, 0]);
        self::assertSame(1, substr_count($html, 'data-yk-el-id="row"'), 'only the first row is selectable');
        self::assertStringContainsString('Alpha', $html);
        self::assertSame(2, substr_count($html, 'yk-loop-ghost'));
        self::assertStringContainsString('inert', $html);
        self::assertStringNotContainsString('data-yk-query-more', $html, 'no pagination in the canvas');
    }

    public function testFilterElementIsLicensedAuthoring(): void
    {
        $sections = [['id' => 's', 'columns' => [['id' => 'c', 'elements' => [
            ['id' => 'f1', 'type' => 'query-filter', 'data' => ['target' => 'host1', 'filter_type' => 'search']],
        ]]]]];
        BloxQueryLoopPolicy::assertSectionsAllowed($sections, true);
        $this->expectException(RuntimeException::class);
        BloxQueryLoopPolicy::assertSectionsAllowed($sections, false);
    }
}
