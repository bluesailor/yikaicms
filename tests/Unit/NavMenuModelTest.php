<?php
/**
 * Tests for includes/models/NavMenuModel.php — 多组菜单（组名+项 JSON）。
 * sanitizeItems 白名单清洗 + treeFor 渲染树（栏目引用活解析/自定义链接/失效跳过）。
 */

declare(strict_types=1);

namespace {

require_once dirname(__DIR__) . '/Controllers/_fixtures/helpers.php';

if (!function_exists('channelUrl')) {
    function channelUrl(array $channel): string
    {
        return '/' . ($channel['slug'] ?? '') . '.html';
    }
}

}

namespace Yikai\Tests\Unit {

use NavMenuModel;
use Yikai\Tests\TestCase;

final class NavMenuModelTest extends TestCase
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
            "CREATE TABLE nav_menus (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                items TEXT,
                sort_order INTEGER DEFAULT 0,
                created_at INTEGER DEFAULT 0,
                updated_at INTEGER DEFAULT 0
            )",
        ];
    }

    private function model(): NavMenuModel
    {
        return new NavMenuModel();
    }

    public function testSanitizeWhitelistsAndCapsDepth(): void
    {
        $count = 0;
        $clean = $this->model()->sanitizeItems([
            ['channel_id' => 3, 'label' => '覆盖名', 'url' => '/ignored.html', 'evil' => 'x', 'children' => [
                ['channel_id' => 0, 'label' => '外链', 'url' => 'https://a.com', 'target' => '_blank', 'children' => [
                    ['channel_id' => 5, 'children' => [
                        ['channel_id' => 9], // 第 4 级：丢弃
                    ]],
                ]],
            ]],
            ['channel_id' => 0, 'label' => '缺 url——丢弃'],
            ['channel_id' => 0, 'label' => 'js 协议', 'url' => 'javascript:alert(1)'], // url 清空 → 丢弃
        ], 1, $count);

        $this->assertCount(1, $clean);
        $this->assertArrayNotHasKey('evil', $clean[0]);
        $this->assertSame('', $clean[0]['url']); // 栏目引用不存自定义 url
        $link = $clean[0]['children'][0];
        $this->assertSame('_blank', $link['target']);
        $this->assertSame([], $link['children'][0]['children']); // 第 4 级被剪
    }

    public function testTreeForResolvesChannelsAndSkipsDeadRefs(): void
    {
        $this->insertRow('channels', ['name' => '产品', 'slug' => 'product']);
        $this->insertRow('channels', ['name' => '停用', 'slug' => 'dead', 'status' => 0]);
        $gid = (int) $this->model()->create(['name' => '页脚菜单', 'items' => json_encode([
            ['channel_id' => 1, 'label' => '', 'url' => '', 'target' => '', 'children' => [
                ['channel_id' => 0, 'label' => '文档', 'url' => 'https://docs.example.com', 'target' => '_blank', 'children' => []],
            ]],
            ['channel_id' => 2, 'children' => []], // 停用栏目：连子树跳过
            ['channel_id' => 1, 'label' => '产品别名', 'children' => []],
        ], JSON_UNESCAPED_UNICODE), 'created_at' => 1, 'updated_at' => 1]);

        $tree = $this->model()->treeFor($gid);
        $this->assertCount(2, $tree);
        $this->assertSame('产品', $tree[0]['name']);
        $this->assertSame('/product.html', $tree[0]['url']);
        $this->assertSame('/product.html', $tree[0]['_url']); // 双键兼容两类消费者
        $this->assertSame('文档', $tree[0]['children'][0]['name']);
        $this->assertSame('_blank', $tree[0]['children'][0]['link_target']);
        $this->assertSame('产品别名', $tree[1]['name']); // label 覆盖显示名
    }

    public function testFooterLinksFlattensTopLevelAndIgnoresChildren(): void
    {
        $this->insertRow('channels', ['name' => '产品', 'slug' => 'product']);
        $gid = (int) $this->model()->create(['name' => '页脚组', 'items' => json_encode([
            ['channel_id' => 1, 'label' => '', 'url' => '', 'target' => '', 'children' => [
                ['channel_id' => 0, 'label' => '子项', 'url' => '/sub', 'target' => '', 'children' => []],
            ]],
            ['channel_id' => 0, 'label' => '文档', 'url' => 'https://docs.example.com', 'target' => '_blank', 'children' => []],
        ], JSON_UNESCAPED_UNICODE), 'created_at' => 1, 'updated_at' => 1]);

        $links = $this->model()->footerLinks($gid);
        // 只投影顶层——嵌套是 mega menu 的语义，页脚一栏就是一列链接
        $this->assertCount(2, $links);
        $this->assertSame(['name' => '产品', 'url' => '/product.html', 'target' => ''], $links[0]);
        $this->assertSame(['name' => '文档', 'url' => 'https://docs.example.com', 'target' => '_blank'], $links[1]);
    }

    public function testFooterLinksUnknownGroupIsEmpty(): void
    {
        // 组不存在 / 项全失效 → []，主题据此回退到自定义内容渲染
        $this->assertSame([], $this->model()->footerLinks(999));
    }

    public function testTreeForUnknownGroupOrBadJsonIsEmpty(): void
    {
        $this->assertSame([], $this->model()->treeFor(999));
        $gid = (int) $this->model()->create(['name' => 'x', 'items' => '{bad json', 'created_at' => 1, 'updated_at' => 1]);
        $this->assertSame([], $this->model()->treeFor($gid));
    }
}

}
