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
        $dir = ROOT_PATH . '/themes/business';
        if (is_file($dir . '/theme.json')) {
            return false;
        }

        if (!is_dir($dir . '/layouts')) {
            mkdir($dir . '/layouts', 0777, true);
        }
        file_put_contents($dir . '/theme.json', '{"name":"Business","version":"test"}');
        file_put_contents($dir . '/layouts/header.php', '<?php echo "<header>Business</header>";');
        file_put_contents($dir . '/layouts/footer.php', '<?php echo "<footer>Business</footer>";');
        return true;
    }

    private function removeBusinessThemeFixture(bool $created): void
    {
        if (!$created) {
            return;
        }

        $dir = ROOT_PATH . '/themes/business';
        @unlink($dir . '/layouts/header.php');
        @unlink($dir . '/layouts/footer.php');
        @rmdir($dir . '/layouts');
        @unlink($dir . '/theme.json');
        @rmdir($dir);
    }
}
