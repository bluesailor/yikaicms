<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxSiteElementsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testDynamicSiteElementsAreRegisteredWithEditableControls(): void
    {
        $meta = BuilderRegistry::meta();
        foreach (['site-copyright', 'site-contact', 'social-links', 'site-search', 'language-switcher'] as $type) {
            self::assertArrayHasKey($type, $meta);
            self::assertTrue($meta[$type]['dynamic']);
            self::assertNotEmpty($meta[$type]['controls']);
        }
    }

    public function testCopyrightAndPhoneBindingsHaveDeterministicFormatting(): void
    {
        self::assertSame('© 2026 Example Inc.', SiteCopyrightElement::formatText('© {year} {site_name}.', 'Example Inc', 2026));
        self::assertSame('© 2026 Example Inc', SiteCopyrightElement::formatText('', 'Example Inc', 2026));
        self::assertSame('tel:+864000000000', SiteContactElement::phoneHref('+86 (400) 000-0000'));
        self::assertSame('', SiteContactElement::phoneHref('extension only'));
    }

    public function testFilingOnlyAppliesToSimplifiedChinese(): void
    {
        self::assertTrue(SiteCopyrightSettings::filingApplies('zh-CN'));
        foreach (['zh-TW', 'en', 'ja', ''] as $language) {
            self::assertFalse(SiteCopyrightSettings::filingApplies($language), $language);
        }

        $filingControls = array_values(array_filter(
            (new SiteCopyrightElement())->controls(),
            static fn (array $control): bool => in_array($control['key'], ['show_icp', 'show_police'], true)
        ));
        self::assertCount(2, $filingControls);
        foreach ($filingControls as $control) {
            self::assertSame(['zh-CN'], $control['site_langs']);
        }
    }

    public function testCopyrightKeyMatchesFrontendLanguageLookup(): void
    {
        $store = [];
        $read = static function (string $key) use (&$store): string {
            return $store[$key] ?? '';
        };

        self::assertSame('footer_copyright_text', SiteCopyrightSettings::copyrightKey('zh-CN', 'zh-CN', $read));
        self::assertSame('footer_copyright_text_en', SiteCopyrightSettings::copyrightKey('en', 'zh-CN', $read));
        // 默认语言若已存在非空语言键，前台 configRawLang() 优先读它，写入也必须落到同一键
        $store['footer_copyright_text_zh-CN'] = '© legacy';
        self::assertSame('footer_copyright_text_zh-CN', SiteCopyrightSettings::copyrightKey('zh-CN', 'zh-CN', $read));
    }

    public function testCopyrightEditorStateFallsBackAndOnlyWritesSubmittedFiling(): void
    {
        $store = [
            'footer_copyright_text' => '© {year} 中文站',
            'site_icp' => '沪ICP备00000000号',
            'site_police' => '',
        ];
        $read = static fn (string $key): string => $store[$key] ?? '';

        $english = SiteCopyrightSettings::editorState('en', $read);
        self::assertSame('© {year} 中文站', $english['copyright']);
        self::assertFalse($english['filing']);
        self::assertTrue(SiteCopyrightSettings::editorState('zh-CN', $read)['filing']);

        // 未提交备案字段（面板在该语境隐藏了备案）时不写备案键，原值保留
        self::assertSame(
            ['footer_copyright_text_en' => '© {year} Example'],
            SiteCopyrightSettings::normalizeInput(
                ['copyright' => " © {year} Example\n"],
                'en',
                'zh-CN',
                $read
            )
        );
        self::assertSame(
            ['footer_copyright_text' => '© {year} 示例', 'site_icp' => '京ICP备1号'],
            SiteCopyrightSettings::normalizeInput(['copyright' => '© {year} 示例', 'icp' => ' 京ICP备1号 '], 'zh-CN', 'zh-CN', $read)
        );
    }

    public function testLanguageSwitcherPreservesPathAndQueryWithoutStackingPrefixes(): void
    {
        $languages = ['zh-CN', 'en', 'ja'];
        self::assertSame('/ja/products/list.html?page=2', LanguageSwitcherElement::switchUrl(
            '/en/products/list.html?page=2', 'ja', 'zh-CN', $languages
        ));
        self::assertSame('/products/list.html?page=2', LanguageSwitcherElement::switchUrl(
            '/ja/products/list.html?page=2', 'zh-CN', 'zh-CN', $languages
        ));
        self::assertSame('/en/page.php?id=2', LanguageSwitcherElement::switchUrl(
            '/page.php?id=2&_lang=zh-CN', 'en', 'zh-CN', $languages
        ));
        self::assertSame('/en/', LanguageSwitcherElement::switchUrl(
            '/admin/blox_preview.php?template=3', 'en', 'zh-CN', $languages
        ));
    }

    public function testLanguageSwitcherDefaultsToAccessibleDropdown(): void
    {
        $element = new LanguageSwitcherElement();
        self::assertSame('dropdown', $element->defaults()['layout']);
        self::assertSame(['/assets/js/blox-language-switcher.js'], $element->scriptsFor([]));
        self::assertSame([], $element->scriptsFor(['layout' => 'inline']));

        $html = LanguageSwitcherElement::renderForLanguages(
            ['zh-CN' => '中文', 'en' => 'English', 'ja' => '日本語'],
            'en',
            'zh-CN',
            ['zh-CN', 'en', 'ja'],
            '/en/products/list.html?page=2',
            ['display' => 'name', 'tone' => 'dark']
        );

        self::assertStringContainsString('data-yk-language-switcher="dropdown"', $html);
        self::assertStringContainsString('<details class="group relative">', $html);
        self::assertStringContainsString('data-yk-language-trigger', $html);
        self::assertStringContainsString('<span>English</span>', $html);
        self::assertStringContainsString('href="/ja/products/list.html?page=2"', $html);
        self::assertStringContainsString('aria-current="page" hreflang="en"', $html);
        self::assertStringNotContainsString('javascript:', $html);
    }

    public function testLanguageSwitcherShowsFlagsWhenEnabled(): void
    {
        $langs = ['zh-CN' => '中文', 'en' => 'English', 'ja' => '日本語', 'xx' => 'Unknown'];
        $known = ['zh-CN', 'en', 'ja', 'xx'];

        $off = LanguageSwitcherElement::renderForLanguages($langs, 'en', 'zh-CN', $known, '/en/', ['display' => 'name']);
        self::assertStringNotContainsString('/assets/icons/flags/', $off, '默认不显示国旗，向后兼容');

        $on = LanguageSwitcherElement::renderForLanguages($langs, 'en', 'zh-CN', $known, '/en/', ['display' => 'name', 'show_flag' => true]);
        // 用随包 SVG（Windows 不渲染 emoji 国旗），而非 emoji
        self::assertStringContainsString('/assets/icons/flags/cn.svg', $on);
        self::assertStringContainsString('/assets/icons/flags/us.svg', $on, 'en → 美国旗');
        self::assertStringContainsString('/assets/icons/flags/jp.svg', $on);
        self::assertStringNotContainsString('🇺🇸', $on, '不用 emoji 国旗');
        // 旗对读屏隐藏（hreflang 已表达语言）
        self::assertStringContainsString('aria-hidden="true"', $on);
        // 未列入映射的语言不硬塞旗，仍显示文字
        self::assertStringContainsString('Unknown', $on);
        self::assertStringNotContainsString('/flags/xx', $on);

        // inline 布局同样支持国旗
        $inline = LanguageSwitcherElement::renderForLanguages(
            ['zh-CN' => '中文', 'ja' => '日本語'], 'zh-CN', 'zh-CN', ['zh-CN', 'ja'], '/',
            ['layout' => 'inline', 'show_flag' => true]
        );
        self::assertStringContainsString('data-yk-language-switcher="inline"', $inline);
        self::assertStringContainsString('/assets/icons/flags/jp.svg', $inline);
    }

    public function testLanguageSwitcherCanRetainInlineLinks(): void
    {
        $html = LanguageSwitcherElement::renderForLanguages(
            ['zh-CN' => '中文', 'en' => 'English'],
            'zh-CN',
            'zh-CN',
            ['zh-CN', 'en'],
            '/',
            ['layout' => 'inline', 'display' => 'code']
        );

        self::assertStringContainsString('data-yk-language-switcher="inline"', $html);
        self::assertStringContainsString('ZH-CN', $html);
        self::assertStringNotContainsString('<details', $html);
    }

    public function testSocialLinksDropUnknownPlatformsAndUnsafeUrls(): void
    {
        $links = SocialLinksElement::decodeLinks(json_encode([
            ['platform' => 'instagram', 'url' => 'https://instagram.com/example'],
            ['platform' => 'youtube', 'url' => 'javascript:alert(1)'],
            ['platform' => 'unknown', 'url' => 'https://example.com'],
        ], JSON_THROW_ON_ERROR));
        self::assertSame([
            ['platform' => 'instagram', 'url' => 'https://instagram.com/example'],
        ], $links);
    }

    public function testBooleanControlsAcceptJsonBooleans(): void
    {
        $contact = (new SiteContactElement())->render([
            'show_phone' => true,
            'show_email' => false,
            'show_address' => false,
            'show_hours' => false,
            'show_icons' => false,
        ]);
        self::assertStringNotContainsString('ti-mail', $contact);
        self::assertStringNotContainsString('ti-map-pin', $contact);
        self::assertStringNotContainsString('ti-phone', $contact);

        $search = (new SiteSearchElement())->render(['show_label' => false]);
        self::assertSame(1, substr_count($search, __('blox_search_submit')));
    }
}
