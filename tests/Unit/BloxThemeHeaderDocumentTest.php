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
}
