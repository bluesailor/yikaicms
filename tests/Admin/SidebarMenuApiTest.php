<?php
/**
 * Tests for the sidebar menu registration API.
 *
 * Locks in:
 *   - register_admin_menu() requires key/label/url
 *   - registered items merge into the matching group
 *   - groupDefaults create missing groups; without them registration is silent no-op
 *   - groups + items sort by 'priority' ascending
 *   - admin_sidebar filter can rewrite the whole structure
 *   - renderAdminMenuItem produces correct active classes
 */

declare(strict_types=1);

namespace Yikai\Tests\Admin;

use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/includes/hooks.php';
require_once dirname(__DIR__, 2) . '/admin/includes/sidebar_menu_api.php';

// __() and isSuperAdmin() come from CMS internals; provide stubs.
if (!function_exists('__')) {
    function __(string $key, string $default = ''): string { return $default !== '' ? $default : $key; }
}
if (!function_exists('isSuperAdmin')) {
    function isSuperAdmin(): bool { return true; }
}

class SidebarMenuApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset the registry + hook system between tests so filters
        // registered in one test don't leak into the next (PHPUnit's
        // defects-first execution order can interleave them).
        $GLOBALS['_yikai_admin_menu_registry'] = [];
        $GLOBALS['ik_actions'] = [];
        $GLOBALS['ik_filters'] = [];
    }

    public function testRegisterRequiresKeyLabelUrl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        register_admin_menu('content', ['label' => 'Oops']);    // no key/url
    }

    public function testRegisteredItemAppearsInResolvedMenu(): void
    {
        register_admin_menu('appearance', [
            'key'   => 'my_plugin',
            'label' => 'My Plugin',
            'url'   => '/admin/myplugin.php',
        ]);

        $menu = resolveAdminSidebar();

        $this->assertArrayHasKey('appearance', $menu);
        $keys = array_column($menu['appearance']['items'], 'key');
        $this->assertContains('my_plugin', $keys);
    }

    public function testRegisterIntoNewGroupNeedsDefaults(): void
    {
        // Without defaults: silently dropped (we don't crash the admin).
        register_admin_menu('totally_new_group', [
            'key' => 'x', 'label' => 'X', 'url' => '/x',
        ]);
        $menu = resolveAdminSidebar();
        $this->assertArrayNotHasKey('totally_new_group', $menu);
    }

    public function testRegisterIntoNewGroupWithDefaultsCreatesGroup(): void
    {
        register_admin_menu('reports', [
            'key' => 'sales', 'label' => 'Sales', 'url' => '/admin/sales.php',
        ], [
            'label'    => 'Reports',
            'priority' => 65,
        ]);

        $menu = resolveAdminSidebar();
        $this->assertArrayHasKey('reports', $menu);
        $this->assertSame('Reports', $menu['reports']['label']);
        $this->assertSame(65, $menu['reports']['priority']);
        $this->assertSame('sales', $menu['reports']['items'][0]['key']);
    }

    public function testItemsSortByPriorityAscending(): void
    {
        register_admin_menu('content', ['key' => 'late',  'label' => 'L', 'url' => '/l', 'priority' => 200]);
        register_admin_menu('content', ['key' => 'early', 'label' => 'E', 'url' => '/e', 'priority' => 1]);

        $menu = resolveAdminSidebar();
        $keys = array_column($menu['content']['items'], 'key');

        // 'early' should come before 'late', and both should sit somewhere
        // near the existing default channel/page items by priority order.
        $earlyPos = array_search('early', $keys, true);
        $latePos  = array_search('late', $keys, true);
        $this->assertNotFalse($earlyPos);
        $this->assertNotFalse($latePos);
        $this->assertLessThan($latePos, $earlyPos);
    }

    public function testAdminSidebarFilterCanRewriteEntirely(): void
    {
        add_filter('admin_sidebar', function (array $menu): array {
            return ['only' => [
                'label'    => 'Solo',
                'priority' => 1,
                'items'    => [['key' => 'lonely', 'label' => 'Lonely', 'url' => '/x']],
            ]];
        });
        $menu = resolveAdminSidebar();
        $this->assertSame(['only'], array_keys($menu));
    }

    public function testRenderActiveItem(): void
    {
        $html = renderAdminMenuItem(
            ['key' => 'channel', 'label' => 'Channel', 'url' => '/admin/channel.php', 'icon' => '<path d="x"/>'],
            'channel'
        );
        $this->assertStringContainsString('href="/admin/channel.php"', $html);
        $this->assertStringContainsString('Channel', $html);
        $this->assertStringContainsString(' active', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('focusable="false"', $html);
    }

    public function testRenderInactiveItem(): void
    {
        $html = renderAdminMenuItem(
            ['key' => 'channel', 'label' => 'Channel', 'url' => '/admin/channel.php'],
            'something_else'
        );
        $this->assertStringNotContainsString(' active', $html);
        $this->assertStringNotContainsString('aria-current=', $html);
    }

    public function testActiveKeysExpandActiveDetection(): void
    {
        $html = renderAdminMenuItem(
            ['key' => 'product', 'label' => 'Product', 'url' => '/admin/product.php',
             'active_keys' => ['product', 'product_setting']],
            'product_setting'
        );
        $this->assertStringContainsString(' active', $html);
    }

    public function testHtmlEscapesLabelAndUrl(): void
    {
        $html = renderAdminMenuItem(
            ['key' => 'x', 'label' => '<script>', 'url' => '/admin/x.php?a=1&b=2'],
            ''
        );
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function testWebsiteDesignGroupOwnsDesignEntries(): void
    {
        $menu = resolveAdminSidebar();

        $this->assertArrayHasKey('design', $menu);
        $this->assertSame(65, $menu['design']['priority']);
        $this->assertSame(
            ['site_design', 'blox_design', 'blox_templates'],
            array_column($menu['design']['items'], 'key')
        );
        $this->assertNotContains('setting_home', array_column($menu['site']['items'], 'key'));
        $this->assertNotContains('blox_templates', array_column($menu['appearance']['items'], 'key'));
        $this->assertContains('recipe', array_column($menu['appearance']['items'], 'key'));
    }
}
