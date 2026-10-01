<?php
/**
 * 语言域名模式：主机名决定语言、跨主机链接给完整地址、走错主机 301 到规范地址、存量链接输出改写。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use LanguageDomains;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/i18n/LanguageDomains.php';

final class LanguageDomainsTest extends TestCase
{
    private const MAP = ['en' => 'en.example.com', 'de' => 'example.de'];
    private array $savedConfig = [];

    protected function setUp(): void
    {
        $this->savedConfig = $GLOBALS['_test_config'] ?? [];
        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';
        $this->on('example.com');
    }

    protected function tearDown(): void
    {
        LanguageDomains::setForTests(null);
        $GLOBALS['_test_config'] = $this->savedConfig;
    }

    private function on(string $host): void
    {
        LanguageDomains::setForTests(self::MAP, 'https://example.com', $host);
    }

    public function testParseKeepsOnlyEnabledNonDefaultLanguagesWithDistinctValidHosts(): void
    {
        $json = json_encode([
            'en' => 'https://EN.example.com/path', 'zh-CN' => 'cn.example.com', 'xx' => 'xx.example.com',
            'fr' => 'fr.example.com', 'ja' => 'not a host', 'de' => 'www.example.com', 'ko' => 'en.example.com',
        ]);
        $map = LanguageDomains::parse((string) $json, 'zh-CN', ['zh-CN', 'en', 'ja', 'de', 'ko'], 'example.com');
        // fr 未启用、ja 格式错、de 是主域名的 www 变体、ko 与 en 重复
        self::assertSame(['en' => 'en.example.com'], $map);
        self::assertSame([], LanguageDomains::parse('not json', 'zh-CN', ['en'], 'example.com'));
    }

    public function testNormalizeHost(): void
    {
        self::assertSame('example.de', LanguageDomains::normalizeHost(' HTTPS://Example.DE./about '));
        self::assertSame('en.example.com:8443', LanguageDomains::normalizeHost('en.example.com:8443'));
        self::assertSame('xn--fiqs8s.com', LanguageDomains::normalizeHost('xn--fiqs8s.com'));
        self::assertNull(LanguageDomains::normalizeHost('中国.com'));
        self::assertNull(LanguageDomains::normalizeHost('evil.com@example.com'));
        self::assertNull(LanguageDomains::normalizeHost(''));
    }

    public function testHostDecidesTheLanguage(): void
    {
        self::assertNull(LanguageDomains::currentLanguage());
        $this->on('en.example.com');
        self::assertSame('en', LanguageDomains::currentLanguage());
        $this->on('www.example.de');
        self::assertSame('de', LanguageDomains::currentLanguage());
    }

    public function testLinksAreRelativeOnTheSameHostAndAbsoluteAcrossHosts(): void
    {
        self::assertSame('/news.html', LanguageDomains::url('zh-CN', '/news.html'));
        self::assertSame('https://en.example.com/news.html', LanguageDomains::url('en', '/news.html'));
        self::assertSame('/ja/news.html', LanguageDomains::url('ja', '/news.html'), '前缀式语言仍在主域名');
        $this->on('en.example.com');
        self::assertSame('/news.html', LanguageDomains::url('en', '/news.html'));
        self::assertSame('https://example.com/news.html', LanguageDomains::url('zh-CN', 'news.html'));
        self::assertSame('https://example.com/ja/news.html', LanguageDomains::url('ja', '/news.html'));
        self::assertSame('https://example.de/', LanguageDomains::url('de', '/'));
    }

    public function testWrongHostOrPrefixRedirectsToTheCanonicalAddress(): void
    {
        self::assertSame('https://en.example.com/news.html?page=2', LanguageDomains::redirectTarget('/en/news.html', 'page=2'));
        self::assertSame('https://en.example.com/', LanguageDomains::redirectTarget('/en/', ''));
        self::assertSame('https://example.com/about.html', LanguageDomains::redirectTarget('/zh-CN/about.html', ''));
        self::assertNull(LanguageDomains::redirectTarget('/ja/news.html', ''), '主域名上的前缀式语言不跳');
        self::assertNull(LanguageDomains::redirectTarget('/news.html', ''));

        $this->on('en.example.com');
        self::assertNull(LanguageDomains::redirectTarget('/news.html', ''));
        self::assertSame('https://en.example.com/news.html', LanguageDomains::redirectTarget('/en/news.html', ''));
        self::assertSame('https://example.com/ja/news.html', LanguageDomains::redirectTarget('/ja/news.html', ''));
        self::assertSame('https://example.de/x.html', LanguageDomains::redirectTarget('/de/x.html', ''));
        self::assertSame('https://example.com/admin/index.php?a=1', LanguageDomains::redirectTarget('/admin/index.php', 'a=1'));
    }

    public function testStoredLinksAreRewrittenOnOutput(): void
    {
        self::assertSame('https://en.example.com/about.html#team', LanguageDomains::rewriteUrl('/en/about.html#team'));
        self::assertSame('https://en.example.com/x.html', LanguageDomains::rewriteUrl('https://example.com/en/x.html'));
        self::assertSame('/ja/about.html', LanguageDomains::rewriteUrl('/ja/about.html'));
        self::assertSame('/assets/app.css', LanguageDomains::rewriteUrl('/assets/app.css'));
        self::assertSame('https://other.com/en/x', LanguageDomains::rewriteUrl('https://other.com/en/x'));
        self::assertSame('#top', LanguageDomains::rewriteUrl('#top'));

        $this->on('en.example.com');
        self::assertSame('/about.html', LanguageDomains::rewriteUrl('/en/about.html'));
        self::assertSame('https://example.com/ja/about.html', LanguageDomains::rewriteUrl('/ja/about.html'));
        self::assertSame('/contact.html', LanguageDomains::rewriteUrl('/contact.html'));

        $html = '<html><body><a href="/en/a.html">A</a><form action="/en/search.html"></form>'
            . '<img src="/en/logo.png"><link rel="stylesheet" href="/en/x.css"></body></html>';
        $out = LanguageDomains::html($html);
        self::assertStringContainsString('<a href="/a.html">', $out);
        self::assertStringContainsString('<form action="/search.html">', $out);
        self::assertStringContainsString('src="/en/logo.png"', $out, '只改链接，不改资源');
        self::assertStringContainsString('href="/en/x.css"', $out);
    }

    public function testEachHostListsOnlyItsOwnLanguagesInTheSitemap(): void
    {
        self::assertSame(['zh-CN', 'ja'], LanguageDomains::languagesServedHere(['zh-CN', 'en', 'ja', 'de']));
        $this->on('example.de');
        self::assertSame(['de'], LanguageDomains::languagesServedHere(['zh-CN', 'en', 'ja', 'de']));
    }

    public function testSiteHealthProbesEachDomainOnlyWhenTheModeIsOn(): void
    {
        require_once ROOT_PATH . '/includes/SiteHealth.php';
        $seen = [];
        $results = \SiteHealth::checkLanguageDomains(static function (string $host) use (&$seen): string {
            $seen[] = $host;
            return $host === 'example.de' ? 'tls' : 'ok';
        });
        self::assertSame(['en.example.com', 'example.de'], $seen);
        self::assertCount(1, $results);
        self::assertSame('critical', $results[0]['status']);
        self::assertSame('language_domains', $results[0]['id']);

        $good = \SiteHealth::checkLanguageDomains(static fn(string $host): string => 'ok');
        self::assertSame('good', $good[0]['status']);

        LanguageDomains::setForTests([], 'https://example.com', 'example.com');
        self::assertSame([], \SiteHealth::checkLanguageDomains(static fn(string $host): string => 'ok'), '没启用时不出这一项');
    }

    public function testIntegrationPoints(): void
    {
        $read = static fn(string $f): string => (string) file_get_contents(ROOT_PATH . '/' . $f);
        self::assertStringContainsString('LanguageDomains::currentLanguage() !== null && LanguageDomains::mainHost()', $read('includes/License.php'), '授权按主域名上报');
        self::assertStringContainsString('LanguageDomains::redirectTarget(', $read('includes/init.php'));
        self::assertStringContainsString('if (self::blockedByLanguageDomains()) return false;', $read('includes/StaticHtml.php'));
        self::assertStringContainsString('LanguageDomains::languagesServedHere(', $read('sitemap.php'));
        self::assertStringContainsString("isset(\$_GET['yk_lang_domain_probe'])", $read('index.php'));
    }
}
