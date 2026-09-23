<?php
/**
 * 页面目录里的「结构页面」：栏目首页与详情页模板的入口契约。
 *
 * 红线：这些卡片只负责把人送进编辑器排版，不提供启停/删除等页面管理动作；
 * 不可排版的栏目类型（case/download/job）要明说原因，不能假装能编。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WebsiteStructuralPagesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        require_once ROOT_PATH . '/admin/includes/website_pages.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['_test_admin_perms'] = ['blox_edit', 'blox_global', 'edit_page'];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_test_admin_perms'] = null;
    }

    private static function item(array $overrides = []): array
    {
        return array_merge([
            'kind' => 'channel', 'id' => 5, 'name' => '产品中心', 'lang' => 'zh-CN',
            'channel_type' => 'product', 'designable' => true, 'publication' => 'published',
            'disabled' => false, 'edit_url' => '/admin/blox_editor.php?id=5',
            'public_url' => '/product.html', 'can_edit' => true,
        ], $overrides);
    }

    public function testChannelLandingCardLinksStraightIntoTheEditor(): void
    {
        $html = renderWebsiteStructuralCard(self::item());

        self::assertStringContainsString('data-testid="website-structural-card-channel-5"', $html);
        self::assertStringContainsString('href="/admin/blox_editor.php?id=5"', $html);
        self::assertStringContainsString('data-testid="structural-edit-channel-5"', $html);
        self::assertStringContainsString('data-structural-kind="channel"', $html);
        // 只排版，不做页面管理：没有启停/删除入口
        self::assertStringNotContainsString('toggleStatus(', $html);
        self::assertStringNotContainsString('data-page-delete=', $html);
    }

    public function testDetailTemplateCardUsesTheTemplateEditorUrl(): void
    {
        $html = renderWebsiteStructuralCard(self::item([
            'kind' => 'detail', 'id' => 42, 'name' => '经典文章阅读',
            'channel_type' => 'article-detail', 'edit_url' => '/admin/blox_editor.php?template=42',
            'public_url' => '', 'publication' => 'draft',
        ]));

        self::assertStringContainsString('href="/admin/blox_editor.php?template=42"', $html);
        self::assertStringContainsString('data-testid="website-structural-card-detail-42"', $html);
        // 模板没有前台地址：不渲染"浏览"链接
        self::assertStringNotContainsString('target="_blank"', $html);
        self::assertStringContainsString('is-pending', $html, '草稿态要标出来');
    }

    public function testNonDesignableChannelSaysWhyInsteadOfOfferingAnEditor(): void
    {
        $html = renderWebsiteStructuralCard(self::item([
            'id' => 6, 'name' => '成功案例', 'channel_type' => 'case',
            'designable' => false, 'edit_url' => '', 'can_edit' => false,
        ]));

        self::assertStringNotContainsString('/admin/blox_editor.php', $html);
        self::assertStringContainsString(__('website_structural_no_layout'), $html);
        self::assertStringContainsString(__('website_structural_no_layout_hint'), $html);
        // 也不能误报成"没权限"
        self::assertStringNotContainsString(__('website_pages_no_permission'), $html);
    }

    public function testMissingPermissionHidesTheDesignEntryOnDesignableItems(): void
    {
        $html = renderWebsiteStructuralCard(self::item(['can_edit' => false]));

        self::assertStringNotContainsString('/admin/blox_editor.php', $html);
        self::assertStringContainsString(__('website_pages_no_permission'), $html);
    }

    public function testCatalogueRendersStructuralSectionOnlyWhenNotFilteringPages(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/page.php');

        self::assertStringContainsString(
            "\$structuralPages = (\$pageQuery === '' && \$pageFilter === 'all') ? websiteStructuralPages(\$_viewLang) : [];",
            $source,
            '搜索/筛选单页时不混入结构页面，否则"共 N 个页面"的计数对不上'
        );
        $divider = strpos($source, 'website-structural-divider');
        $disabled = strpos($source, 'website-pages-disabled-divider');
        self::assertNotFalse($divider);
        self::assertNotFalse($disabled);
        self::assertTrue($divider < $disabled, '结构页面排在已停用分区之前');
        self::assertStringContainsString('renderWebsiteStructuralCard($item)', $source);
    }

    /**
     * 可排版的栏目类型必须与编辑器/前台的真实闸口一致，不能各说各话：所有闸口都读
     * ChannelBloxDocument::supportsType() 这一处（product 另走 PageBloxDocument）。
     * 2026-09-23 起案例栏目加入；下载与招聘各有独立的表，尚无目录数据源，仍标「暂不支持排版」。
     */
    public function testDesignableChannelTypesMatchTheEditorAndFrontendGates(): void
    {
        require_once ROOT_PATH . '/includes/builder/ChannelBloxDocument.php';
        self::assertSame(['list', 'case'], \ChannelBloxDocument::CHANNEL_TYPES);
        foreach (['download', 'job', 'page', 'product', 'link'] as $type) {
            self::assertFalse(\ChannelBloxDocument::supportsType($type), $type);
        }
        self::assertSame('article', \ChannelBloxDocument::contentType('list'));
        self::assertSame('case', \ChannelBloxDocument::contentType('case'));

        $gates = [
            'admin/includes/website_pages.php' => "\$designable = \$type === 'product' || ChannelBloxDocument::supportsType(\$type);",
            'admin/blox_editor.php' => "\$isChannelLanding = \$pageType === 'product' || ChannelBloxDocument::supportsType(\$pageType);",
            'admin/blox_page_api.php' => "ChannelBloxDocument::supportsType((string) (\$targetChannel['type'] ?? ''))",
            'list.php' => "ChannelBloxDocument::supportsType((string) (\$channel['type'] ?? '')) ? \$channel : null",
            'includes/builder/BloxPublicationStatus.php' => 'ChannelBloxDocument::supportsType(',
            'includes/builder/BloxCatalogItems.php' => 'ChannelBloxDocument::supportsType($type)',
        ];
        foreach ($gates as $file => $needle) {
            self::assertStringContainsString($needle, (string) file_get_contents(ROOT_PATH . '/' . $file), $file);
        }
        $channelDocument = (string) file_get_contents(ROOT_PATH . '/includes/builder/ChannelBloxDocument.php');
        self::assertStringContainsString("!self::supportsType((string) (\$channel['type'] ?? ''))", $channelDocument);
        // 画布预览按 match 分派，新增类型时要一起补
        self::assertStringContainsString("'list', 'case' => ChannelBloxDocument::load(", (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxCanvasPreview.php'));
    }
}
