<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxThemeHeaderDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testRightLayoutReflectsCurrentThemeSettings(): void
    {
        $previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        try {
            $GLOBALS['yikai_config_runtime_overrides'] = [
                'current_theme' => 'default',
                'header_nav_layout' => 'right',
                'header_sticky' => '0',
                'header_bg_color' => '#fefefe',
                'header_text_color' => '#334155',
                'site_logo_max_height' => '40',
                'show_lang_switcher' => '0',
            ];

            $document = BloxThemeHeaderDocument::current('test-current-header');

            self::assertFalse($document['settings']['sticky']);
            self::assertFalse($document['settings']['header_overlay_enabled']);
            self::assertSame('#fefefe', $document['settings']['header_states']['normal']['background']);
            self::assertSame('#334155', $document['settings']['header_states']['normal']['text']);
            self::assertCount(1, $document['sections']);
            self::assertSame('#fefefe', $document['sections'][0]['settings']['bg_color']);
            $children = $document['sections'][0]['columns'][0]['elements'][0]['data']['children'];
            self::assertSame(['logo', 'nav-mega', 'nav-drawer'], array_column($children, 'type'));
            self::assertSame('md', $children[0]['data']['height']);
            // v1.18.6 Typed Schema：checkbox 经保存管线归一为 '1'/'0' 字符串
            //（兼容 !empty() 与 (string)$v !== '0' 两种存量渲染判定）
            self::assertSame('0', $children[1]['data']['full_width']);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previous;
            }
        }
    }

    public function testRightLayoutKeepsTheCurrentLanguageDropdown(): void
    {
        $previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        try {
            $GLOBALS['yikai_config_runtime_overrides'] = [
                'current_theme' => 'default',
                'header_nav_layout' => 'right',
                'show_lang_switcher' => '1',
            ];

            $document = BloxThemeHeaderDocument::current('test-current-header-language');
            $children = $document['sections'][0]['columns'][0]['elements'][0]['data']['children'];

            self::assertSame(
                ['logo', 'nav-mega', 'language-switcher', 'nav-drawer'],
                array_column($children, 'type')
            );
            self::assertSame('dropdown', $children[2]['data']['layout']);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previous;
            }
        }
    }

    public function testBelowLayoutCreatesSeparateNavigationRow(): void
    {
        $previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        try {
            $GLOBALS['yikai_config_runtime_overrides'] = [
                'current_theme' => 'default',
                'header_nav_layout' => 'below',
                'header_sticky' => '1',
                'header_bg_color' => '#ffffff',
                'header_text_color' => '#111827',
                'site_logo_max_height' => '64',
                'show_lang_switcher' => '1',
            ];

            $document = BloxThemeHeaderDocument::current('test-current-header-below');

            self::assertTrue($document['settings']['sticky']);
            self::assertCount(2, $document['sections']);
            self::assertSame('lg', $document['sections'][0]['columns'][0]['elements'][0]['data']['children'][0]['data']['height']);
            self::assertSame('nav-mega', $document['sections'][1]['columns'][0]['elements'][0]['data']['children'][0]['type']);
            self::assertSame('none', $document['sections'][1]['settings']['padding']);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previous;
            }
        }
    }

    public function testMinimalThemeHeaderStartsFromCleanWhiteHeader(): void
    {
        $createdTheme = $this->ensureMinimalThemeFixture();
        $previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        try {
            $GLOBALS['yikai_config_runtime_overrides'] = [
                'current_theme' => 'minimal',
                'header_sticky' => '1',
                'site_logo_max_height' => '32',
                'show_lang_switcher' => '1',
            ];

            $document = BloxThemeHeaderDocument::current('test-current-minimal-header');

            self::assertTrue($document['settings']['sticky']);
            self::assertFalse($document['settings']['header_overlay_enabled']);
            self::assertSame('#ffffff', $document['settings']['header_states']['normal']['background']);
            self::assertSame('#4b5563', $document['settings']['header_states']['normal']['text']);
            self::assertSame('#e5e7eb', $document['settings']['header_states']['normal']['border']);
            self::assertSame('none', $document['settings']['header_states']['normal']['shadow']);
            self::assertSame('#e5e7eb', $document['settings']['header_states']['stuck']['border']);
            self::assertSame('sm', $document['settings']['header_states']['stuck']['shadow']);
            self::assertCount(1, $document['sections']);
            self::assertSame('#ffffff', $document['sections'][0]['settings']['bg_color']);

            $children = $document['sections'][0]['columns'][0]['elements'][0]['data']['children'];
            self::assertSame(['logo', 'nav', 'language-switcher', 'nav-drawer'], array_column($children, 'type'));
            self::assertSame('dark', $children[0]['data']['tone']);
            self::assertSame('1', $children[1]['data']['dropdown']);
            self::assertSame('1', $children[1]['data']['desktop_only']);
            self::assertSame('flex flex-nowrap items-center gap-8 whitespace-nowrap', $children[1]['data']['wrap_class']);

            // sticky 跟随 header_sticky 设置，语言切换关闭时不出现语言元素
            $GLOBALS['yikai_config_runtime_overrides']['header_sticky'] = '0';
            $GLOBALS['yikai_config_runtime_overrides']['show_lang_switcher'] = '0';
            $document = BloxThemeHeaderDocument::current('test-current-minimal-header');
            self::assertFalse($document['settings']['sticky']);
            $children = $document['sections'][0]['columns'][0]['elements'][0]['data']['children'];
            self::assertSame(['logo', 'nav', 'nav-drawer'], array_column($children, 'type'));

            // 生成的文档 ID 唯一（BloxAreaDocument::process 的硬性契约）
            $ids = [];
            array_walk_recursive(
                $document['sections'],
                function ($value) use (&$ids): void {
                    if (is_string($value) && preg_match('/^[a-z0-9-]+$/', $value) === 1 && str_contains($value, '-header')) {
                        $ids[$value] = ($ids[$value] ?? 0) + 1;
                    }
                }
            );
            $duplicated = array_filter($ids, fn (int $count): bool => $count > 1);
            self::assertSame([], $duplicated);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previous;
            }
            $this->removeMinimalThemeFixture($createdTheme);
        }
    }

    public function testBusinessThemeHeaderStartsFromVisibleDarkHeader(): void
    {
        $createdTheme = $this->ensureBusinessThemeFixture();
        $previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        try {
            $GLOBALS['yikai_config_runtime_overrides'] = [
                'current_theme' => 'business',
                'site_logo_max_height' => '48',
                'show_lang_switcher' => '0',
            ];

            $document = BloxThemeHeaderDocument::current('test-current-business-header');

            self::assertTrue($document['settings']['sticky']);
            self::assertFalse($document['settings']['header_overlay_enabled']);
            self::assertSame('#1e293b', $document['settings']['header_states']['normal']['background']);
            self::assertSame('#d1d5db', $document['settings']['header_states']['normal']['text']);
            self::assertSame('lg', $document['settings']['header_states']['normal']['shadow']);
            self::assertCount(1, $document['sections']);
            self::assertSame('#1e293b', $document['sections'][0]['settings']['bg_color']);

            $children = $document['sections'][0]['columns'][0]['elements'][0]['data']['children'];
            self::assertSame(['logo', 'nav-mega', 'nav-drawer'], array_column($children, 'type'));
            self::assertSame('light', $children[0]['data']['tone']);
            self::assertSame((string) __('detail_consult'), $children[1]['data']['cta_text']);
            self::assertSame('/contact.html', $children[1]['data']['cta_url']);
            self::assertSame('solid', $children[1]['data']['cta_style']);
            self::assertSame('/contact.html', $children[2]['data']['cta_url']);
        } finally {
            if ($previous === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previous;
            }
            $this->removeBusinessThemeFixture($createdTheme);
        }
    }

    private function ensureBusinessThemeFixture(): bool
    {
        return $this->ensureRuntimeThemeFromMarketplace('business');
    }

    private function ensureMinimalThemeFixture(): bool
    {
        return $this->ensureRuntimeThemeFromMarketplace('minimal');
    }

    /**
     * 保证 themes/<theme> 运行副本与 marketplace 唯一源码一致（已安装则不动）。
     * ThemeRuntime::resolve 需要运行目录里存在 theme.json + header/footer 才会
     * 认可该主题；CI 无已安装主题，从唯一源码同步最小骨架。
     */
    private function ensureRuntimeThemeFromMarketplace(string $theme): bool
    {
        $dir = ROOT_PATH . '/themes/' . $theme;
        if (is_file($dir . '/theme.json')) {
            return false;
        }

        $source = ROOT_PATH . '/marketplace/themes/' . $theme;
        foreach (['theme.json', 'layouts/header.php', 'layouts/footer.php'] as $rel) {
            if (!is_file($source . '/' . $rel)) {
                self::fail("marketplace/themes/{$theme} 缺少 {$rel}，无法建立运行时夹具");
            }
        }
        foreach (['theme.json', 'layouts/header.php', 'layouts/footer.php'] as $rel) {
            $dst = $dir . '/' . $rel;
            if (!is_dir(dirname($dst))) {
                mkdir(dirname($dst), 0777, true);
            }
            copy($source . '/' . $rel, $dst);
        }
        return true;
    }

    private function removeBusinessThemeFixture(bool $created): void
    {
        $this->removeRuntimeThemeFixture('business', $created);
    }

    private function removeMinimalThemeFixture(bool $created): void
    {
        $this->removeRuntimeThemeFixture('minimal', $created);
    }

    private function removeRuntimeThemeFixture(string $theme, bool $created): void
    {
        if (!$created) {
            return;
        }

        $dir = ROOT_PATH . '/themes/' . $theme;
        @unlink($dir . '/layouts/header.php');
        @unlink($dir . '/layouts/footer.php');
        @rmdir($dir . '/layouts');
        @unlink($dir . '/theme.json');
        @rmdir($dir);
    }
}
