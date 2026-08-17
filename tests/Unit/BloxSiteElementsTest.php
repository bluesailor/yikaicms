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
