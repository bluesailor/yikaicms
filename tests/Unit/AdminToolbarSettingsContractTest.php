<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.3.1 后台顶栏设置的接线契约：保存端点只认当前会话账号；每个入口在顶栏和「更多」两处都接上，
 * 原有的可用规则不变；收起的查找页面 / 提醒仍挂载，快捷键和计数照常。
 */
final class AdminToolbarSettingsContractTest extends TestCase
{
    private const ITEMS = ['search', 'language', 'notifications', 'help', 'visit', 'cache', 'ai'];

    private function source(string $path): string
    {
        $src = file_get_contents(ROOT_PATH . '/' . $path);
        self::assertIsString($src);
        return $src;
    }

    public function testEndpointSavesOnlyTheSignedInAccountWithCsrf(): void
    {
        $src = $this->source('admin/toolbar_prefs_api.php');
        self::assertStringContainsString('checkLogin();', $src);
        self::assertStringContainsString('verifyCsrf();', $src);
        self::assertMatchesRegularExpression("/REQUEST_METHOD.*!== 'POST'/", $src);
        self::assertStringContainsString('$adminId = getAdminId();', $src);
        self::assertDoesNotMatchRegularExpression("/post\\(\\s*'(admin_id|user_id|id)'/", $src, 'the account must never come from the request');
    }

    public function testEveryShortcutIsWiredInTheToolbarAndTheMoreMenu(): void
    {
        require_once ROOT_PATH . '/includes/AdminToolbarPrefs.php';
        self::assertSame(self::ITEMS, AdminToolbarPrefs::ITEMS);

        $header = $this->source('admin/includes/header.php');
        $bell = $this->source('admin/includes/admin_bell.php');
        foreach (self::ITEMS as $item) {
            if ($item === 'notifications') {
                self::assertStringContainsString("\$__bellToolbarAttr = \$__tbAttr('notifications');", $header);
                self::assertStringContainsString('<?php echo $__bellToolbarAttr ?? \'\'; ?>', $bell);
            } else {
                self::assertStringContainsString("\$__tbAttr('{$item}')", $header, "{$item} toolbar entry");
            }
            self::assertStringContainsString("\$__tbMoreAttr('{$item}')", $header, "{$item} More entry");
            self::assertStringContainsString("'admin_toolbar_item_{$item}'", $header);
        }
        self::assertStringContainsString('data-testid="admin-toolbar-settings-open"', $header, 'Toolbar Settings lives in the account menu');
        self::assertStringContainsString('data-yk-tb-reset', $header);
    }

    public function testAvailabilityRulesStayTheSameInBothPlaces(): void
    {
        $header = $this->source('admin/includes/header.php');
        self::assertStringContainsString("'language' => count(\$availableLangs) >= 2,", $header);
        self::assertStringContainsString("'notifications' => hasPermission('*'),", $header);
        // 企业管理员（受限内容角色）不显示 AI 助手与缓存入口：AI 助手尚无单独授权，缓存属站点设置
        self::assertStringContainsString("\$__tbAiReady = !isEnterpriseContentAdmin() && class_exists('AiService') && aiService()->isConfigured();", $header);
        self::assertStringContainsString("'cache' => !isEnterpriseContentAdmin(),", $header);
        self::assertSame(2, substr_count($header, "<?php if (in_array('cache', \$__tbRendered, true)): ?>"), 'cache is gated in the bar and in More');
        self::assertStringContainsString("\$key !== 'ai' || \$currentMenu !== 'ai_assistant'", $header);
        // 顶栏那份沿用原来的判断（语言 count >= 2、提醒在 admin_bell.php 里判权限），「更多」那份按同一份可用清单
        self::assertStringContainsString('<?php if (count($availableLangs) >= 2): ?>', $header);
        self::assertStringContainsString("<?php if (in_array('ai', \$__tbRendered, true)): ?>
                    <div class=\"relative\" x-data=\"aiBubble()\"", $header);
        foreach (['language', 'notifications', 'ai'] as $item) {
            self::assertGreaterThanOrEqual(1, substr_count($header, "in_array('{$item}', \$__tbRendered, true)"), "{$item} is gated in More");
        }
        self::assertStringStartsWith('<?php', $this->source('admin/includes/admin_bell.php'));
        self::assertStringContainsString("!hasPermission('*')", $this->source('admin/includes/admin_bell.php'));
    }

    public function testHiddenFindPageAndBellKeepWorking(): void
    {
        $header = $this->source('admin/includes/header.php');
        // Ctrl/⌘+K 走 reveal()：输入框不在版面上时先展开浮层
        self::assertMatchesRegularExpression("/e\\.key\\.toLowerCase\\(\\) === 'k'\\) \\{\\s*e\\.preventDefault\\(\\);\\s*this\\.reveal\\(\\);/", $header);
        self::assertStringContainsString('if (this.$refs.input.offsetParent) { focus(); return; }', $header);
        self::assertStringContainsString("@yk-toolbar-open.window=\"if (\$event.detail === 'search') reveal()\"", $header);
        // 计数广播到「更多」按钮
        $bell = $this->source('admin/includes/admin_bell.php');
        self::assertStringContainsString("new CustomEvent('yk-bell-count'", $bell);
        self::assertStringContainsString("@yk-toolbar-open.window=\"if (\$event.detail === 'notifications') { open = true;", $bell);
        self::assertStringContainsString('@yk-bell-count.window="bellCount = Number($event.detail) || 0"', $header);

        $css = $this->source('assets/css/admin.css');
        self::assertStringContainsString("[data-yk-tb-item][data-pinned=\"0\"][data-yk-tb-detach],\n[data-yk-tb-item][data-overflow][data-yk-tb-detach] {", $css, 'collapsed widgets move out of the toolbar instead of unmounting');
        self::assertStringContainsString('[data-yk-tb-hidden~="notifications"] .yk-tb-more-badge', $css);
        self::assertStringContainsString('@media (max-width: 639.98px)', $css);
        // 放不下时（中等宽度 + 展开侧栏）脚本临时收起入口，账号菜单不被挤出屏幕
        self::assertStringContainsString('[data-yk-toolbar][data-yk-tb-overflowing] .yk-tb-more', $css);
        self::assertStringContainsString("var OVERFLOW_ORDER = ['cache', 'help', 'visit', 'language', 'ai'];", $this->source('assets/js/admin-toolbar.js'));
    }

    public function testKeyboardFocusFollowsPanelsOpenedFromMore(): void
    {
        $header = $this->source('admin/includes/header.php');
        $bell = $this->source('admin/includes/admin_bell.php');
        // 从「更多」打开：AI 进输入框，提醒进面板第一个可操作元素；Esc 关掉回「更多」按钮
        self::assertStringContainsString("\$nextTick(() => \$el.querySelector('#aiAssistantPrompt').focus())", $header);
        self::assertStringContainsString('@keydown.escape="if (open) { open = false; YikaiAdminToolbar.returnFocus($el) }"', $header);
        self::assertStringContainsString('@keydown.escape="if (open) { open = false; $refs.moreButton.focus() }"', $header);
        self::assertStringContainsString('YikaiAdminToolbar.returnFocus($root)', $header);
        self::assertStringContainsString("YikaiAdminToolbar.focusFirst(\$el.querySelector('[data-testid=admin-bell-panel]'))", $bell);
        self::assertStringContainsString('if (inside) YikaiAdminToolbar.returnFocus($el)', $bell);

        $css = $this->source('assets/css/admin.css');
        // 收起的按钮要 display:none：带文字的按钮比 36px 锚点宽，visibility:hidden 仍会撑出顶栏溢出
        self::assertStringContainsString("[data-yk-tb-item][data-overflow][data-yk-tb-detach] [data-yk-tb-trigger] { display: none !important; }", $css);
        self::assertStringNotContainsString('[data-yk-tb-trigger] { visibility: hidden; }', $css);
        // 从右到左时「更多」菜单和收起面板从行尾一侧展开
        self::assertStringContainsString('[data-testid="admin-toolbar-more-menu"] { right: auto; inset-inline-end: 0; }', $css);
        self::assertStringContainsString('[data-yk-tb-panel="drop"] { top: 100%; right: auto; inset-inline-end: 0; }', $css);
        $js = $this->source('assets/js/admin-toolbar.js');
        self::assertStringContainsString('if (!panel.getClientRects().length) {', $js, 'fixed panels have no offsetParent');
    }

    public function testInlineScriptStringsInTheMoreMenuUseJsString(): void
    {
        $header = $this->source('admin/includes/header.php');
        self::assertStringContainsString('onclick="switchAdminLang(<?php echo e(jsString((string) $code)); ?>)"', $header);
    }
}
