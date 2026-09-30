<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 社媒入口（2.0.3，来自英文模板制作反馈）：
 * - social_links 随整站包导出 / 导入，且两侧都剔除不安全条目；
 * - 品牌图标不在前台常用子集里时，按需补载完整图标字体（不再空白）。
 */
final class SocialLinksPortabilityTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        require_once ROOT_PATH . '/includes/SiteTemplateData.php';
    }

    protected function setUp(): void
    {
        $GLOBALS['_test_config'] = [];
        BloxAssetCollector::resetForTests();
    }

    protected function tearDown(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    public function testSocialLinksArePortableWithAnExactKeyOnly(): void
    {
        self::assertTrue(SiteTemplateData::settingAllowed('social_links'));
        foreach (['social_links_secret', 'social_token', 'socials'] as $key) {
            self::assertFalse(SiteTemplateData::settingAllowed($key), $key);
        }
    }

    public function testPortableValueDropsUnsafeEntries(): void
    {
        $raw = json_encode([
            ['platform' => 'facebook', 'url' => 'https://www.facebook.com/acme'],
            ['platform' => 'youtube', 'url' => 'javascript:alert(1)'],
            ['platform' => 'x', 'url' => '//evil.test/x'],
            ['platform' => 'Bad Platform!', 'url' => 'https://example.com'],
            ['platform' => 'line', 'url' => '/contact.html'],
            ['platform' => 'whatsapp', 'url' => 'https://wa.me/123"><script>'],
            'junk',
        ]);
        self::assertSame(
            '[{"platform":"facebook","url":"https://www.facebook.com/acme"},{"platform":"line","url":"/contact.html"}]',
            SiteTemplateData::portableValue('social_links', (string) $raw)
        );
        self::assertSame('[]', SiteTemplateData::portableValue('social_links', 'not json'));
        self::assertSame('untouched', SiteTemplateData::portableValue('site_name', 'untouched'), 'other settings pass through');
    }

    public function testBrandIconsOutsideTheSubsetLoadTheFullIconFont(): void
    {
        $GLOBALS['_test_config']['social_links'] = json_encode([
            ['platform' => 'facebook', 'url' => 'https://www.facebook.com/acme'],
            ['platform' => 'pinterest', 'url' => 'https://www.pinterest.com/acme'],
        ]);
        $element = new SocialLinksElement();
        self::assertContains(BloxIcon::TABLER_STYLESHEET, $element->stylesFor([]), 'declared up front for head output');

        BloxAssetCollector::resetForTests();
        $html = $element->render([]);
        self::assertStringContainsString('ti ti-brand-facebook', $html);
        self::assertStringContainsString('ti ti-brand-pinterest', $html);
        self::assertContains(BloxIcon::TABLER_STYLESHEET, BloxAssetCollector::styles(), 'collected at render time too (footer areas render after head)');
    }

    public function testIconsInsideTheSubsetDoNotPullTheFullFont(): void
    {
        $GLOBALS['_test_config']['social_links'] = json_encode([['platform' => 'note', 'url' => 'https://note.com/acme']]);
        $element = new SocialLinksElement();
        self::assertSame([], $element->stylesFor([]));
        $element->render([]);
        self::assertNotContains(BloxIcon::TABLER_STYLESHEET, BloxAssetCollector::styles());
    }
}
