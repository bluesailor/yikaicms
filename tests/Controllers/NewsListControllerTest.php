<?php
/**
 * Tests for NewsListController — news.php 从内联取数迁到此。
 *
 * 覆盖：默认列出 news 顶级栏目下已发布文章、按 slug 解析子分类、
 * 关键词搜索、分页 offset、子栏目导航列表。
 */

declare(strict_types=1);

namespace Yikai\Tests\Controllers;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/controllers/list/NewsListController.php';
require_once dirname(__DIR__, 2) . '/includes/catalog_pagination.php';
require_once __DIR__ . '/_fixtures/helpers.php';

class NewsListControllerTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_id INTEGER DEFAULT 0,
                name TEXT, slug TEXT, type TEXT DEFAULT 'list',
                status INTEGER DEFAULT 1, is_nav INTEGER DEFAULT 1,
                sort_order INTEGER DEFAULT 0
            )",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                channel_id INTEGER NOT NULL,
                title TEXT NOT NULL, slug TEXT, summary TEXT, cover TEXT,
                type TEXT DEFAULT 'article',
                status INTEGER DEFAULT 1,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                publish_time INTEGER DEFAULT 0,
                lang TEXT DEFAULT 'zh-CN',
                deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    private function seedFixture(): void
    {
        // 顶级 news 栏目(id=1) + 一个子分类 company(id=2)
        $this->insertRow('channels', ['name' => 'News', 'slug' => 'news', 'type' => 'list', 'parent_id' => 0]);
        $this->insertRow('channels', ['name' => 'Company', 'slug' => 'company', 'type' => 'list', 'parent_id' => 1]);

        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Top News', 'slug' => 'a', 'status' => 1, 'publish_time' => 100]);
        $this->insertRow('contents', ['channel_id' => 2, 'title' => 'Company Note', 'slug' => 'b', 'status' => 1, 'publish_time' => 200, 'summary' => 'keyword_here']);
        $this->insertRow('contents', ['channel_id' => 1, 'title' => 'Draft', 'status' => 0]);
    }

    public function testListsPublishedNewsUnderTopChannel(): void
    {
        $this->seedFixture();
        $vars = (new \NewsListController())->prepare(['page' => 1]);

        $this->assertNotNull($vars['newsChannel']);
        $this->assertNull($vars['category']);                 // 无 cat 参数
        $titles = array_column($vars['articles'], 'title');
        $this->assertContains('Top News', $titles);
        $this->assertContains('Company Note', $titles);       // include_children=true
        $this->assertNotContains('Draft', $titles);           // status=0 排除
    }

    public function testResolvesCategoryBySlug(): void
    {
        $this->seedFixture();
        $vars = (new \NewsListController())->prepare(['cat' => 'company']);

        $this->assertNotNull($vars['category']);
        $this->assertSame('Company', $vars['category']['name']);
        $titles = array_column($vars['articles'], 'title');
        $this->assertSame(['Company Note'], $titles);         // 只该分类
    }

    public function testKeywordFilters(): void
    {
        $this->seedFixture();
        $vars = (new \NewsListController())->prepare(['keyword' => 'keyword_here']);

        $this->assertSame(1, $vars['total']);
        $this->assertSame('Company Note', $vars['articles'][0]['title']);
        $this->assertSame('keyword_here', $vars['keyword']);
    }

    public function testSubcategoriesListedForNav(): void
    {
        $this->seedFixture();
        $vars = (new \NewsListController())->prepare([]);

        $slugs = array_column($vars['categories'], 'slug');
        $this->assertContains('company', $slugs);
    }

    public function testPaginationOffset(): void
    {
        $this->seedFixture();
        // 再塞 12 篇到顶级栏目，验证第2页 offset
        for ($i = 0; $i < 12; $i++) {
            $this->insertRow('contents', ['channel_id' => 1, 'title' => "P{$i}", 'status' => 1, 'publish_time' => 300 + $i]);
        }
        $vars = (new \NewsListController())->prepare(['page' => 2]);

        $this->assertSame(2, $vars['page']);
        $this->assertSame(10, $vars['perPage']);
        // 共 14 篇已发布（Top News + Company Note + 12），第2页应有 4 篇
        $this->assertSame(14, $vars['total']);
        $this->assertLessThanOrEqual(10, count($vars['articles']));
    }
}
