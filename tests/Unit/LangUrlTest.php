<?php
/**
 * langUrl() 语言前缀拼接回归。
 *
 * 起因：旧实现 '/' . $lang . ltrim($url, '/') 丢了分隔符，langUrl('/contact.html', 'en')
 * 得到 /encontact.html。语言首页须为 /en/：服务器前缀规则 ^(ja|en|...)/(.*)$ 不匹配裸 /en。
 */
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/lang_url.php';

final class LangUrlTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_config']['site_lang']);
        parent::tearDown();
    }

    public function testDefaultLanguageReturnsUrlUnchanged(): void
    {
        self::assertSame('/contact.html', langUrl('/contact.html', 'zh-CN'));
        self::assertSame('/', langUrl('/', 'zh-CN'));
    }

    public function testNonDefaultLanguageRootUsesTrailingSlash(): void
    {
        self::assertSame('/en/', langUrl('/', 'en'));
    }

    public function testNonDefaultLanguagePathKeepsSeparator(): void
    {
        self::assertSame('/en/contact.html', langUrl('/contact.html', 'en'));
        self::assertSame('/ja/news/article/a.html', langUrl('news/article/a.html', 'ja'));
    }
}
