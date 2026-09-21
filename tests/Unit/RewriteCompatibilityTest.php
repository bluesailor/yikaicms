<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once ROOT_PATH . '/includes/RewriteProbe.php';
require_once ROOT_PATH . '/includes/CompatibleLinks.php';

final class RewriteCompatibilityTest extends TestCase
{
    public function testFreshInstallDefaultsToAccessibleMode(): void
    {
        foreach ([null, '', '0', 1, true, [], 'pretty'] as $result) self::assertSame('query', RewriteProbe::installMode($result));
        self::assertSame('pretty', RewriteProbe::installMode('1'));
    }

    public function testKnownRoutesKeepIdentityLanguageFiltersAndFragments(): void
    {
        foreach ([
            '/contact.html' => ['contact', []],
            '/en/contact.html?utm_source=demo#form' => ['contact', ['lang' => 'en', 'utm_source' => 'demo']],
            '/news/article/12.html' => ['article', ['id' => '12']],
            '/product/tools/drill.html' => ['product', ['slug' => 'drill']],
            '/product/tools/page/2.html?sort=price' => ['list', ['slug' => 'product', 'cat' => 'tools', 'page' => '2', 'sort' => 'price']],
            '/about/team.html' => ['page', ['parent' => 'about', 'slug' => 'team']],
            '/list/4/page/3.html' => ['list', ['id' => '4', 'page' => '3']],
            '/en/' => ['home', ['lang' => 'en']],
            'http://site.test/contact.html' => ['contact', []],
            '//site.test/contact.html' => ['contact', []],
            'contact.html' => ['contact', []],
        ] as $url => [$route, $params]) {
            $converted = CompatibleLinks::url($url, 'http://site.test');
            self::assertStringStartsWith('/index.php?', $converted, $url);
            parse_str((string) parse_url($converted, PHP_URL_QUERY), $query);
            self::assertSame($route, $query['yk_route']);
            foreach ($params as $key => $value) self::assertSame($value, $query[$key]);
            self::assertNotNull(Dispatcher::dynamicQuery($query));
            if (str_contains($url, '#form')) self::assertStringEndsWith('#form', $converted);
        }
    }

    public function testDoesNotTakeOverOtherOriginsFilesOrUnknownRoutes(): void
    {
        foreach (['https://other.test/contact.html', '//other.test/contact.html', 'http://site.test:81/contact.html',
            'https://site.test/contact.html', 'mailto:a@site.test', '#form', '/index.php?yk_route=contact',
            '/admin/contact.html', '/plugins/custom/page.html', '/uploads/demo.html', '/assets/style.css',
            '/a/b/c.html', 'javascript:alert(1)', '/contact.html?bad[]=x&page[]=1'] as $url) {
            self::assertSame($url, CompatibleLinks::url($url, 'http://site.test'), $url);
        }
        self::assertSame('/robots.txt', CompatibleLinks::url('/robots.txt', 'http://site.test', '/', ROOT_PATH));
    }

    /**
     * sitemap 改为接管（2026-09-21 决定）。此前它被归入"不接管"清单，但伪静态不可用时
     * /sitemap.xml 匹配不到任何路由 = 404，等于把死地址交给搜索引擎。
     * 它没有 ?yk_route= 形式，所以指向真实入口 /sitemap.php；后台 SEO 页展示的地址同步跟随。
     */
    public function testSitemapPointsAtTheRealEntryInCompatibilityMode(): void
    {
        self::assertSame('/sitemap.php', CompatibleLinks::url('/sitemap.xml', 'http://site.test'));
        self::assertSame('https://other.test/sitemap.xml',
            CompatibleLinks::url('https://other.test/sitemap.xml', 'http://site.test'), '外站地址不归我们管');
    }

    public function testOnlyNavigationAttributesAreChanged(): void
    {
        $html = '<a href="/contact.html?utm=x&amp;sort=asc#form">Contact</a>'
            . '<a download href="/contact.html">File</a><script>const x = \'<a href="/contact.html">\';</script>'
            . '<img src="/contact.html"><a href="https://other.test/contact.html">Other</a>';
        $actual = CompatibleLinks::html($html, 'http://site.test');
        self::assertStringContainsString('href="/index.php?utm=x&amp;sort=asc&amp;yk_route=contact#form"', $actual);
        self::assertStringContainsString('<a download href="/contact.html">', $actual);
        self::assertStringContainsString('<script>const x = \'<a href="/contact.html">\';</script>', $actual);
        self::assertStringContainsString('<img src="/contact.html">', $actual);
        self::assertStringContainsString('https://other.test/contact.html', $actual);
        $withBase = '<base href="https://other.test/"><a href="contact.html">Contact</a><a href="/contact.html">Root</a>';
        self::assertSame($withBase, CompatibleLinks::html($withBase, 'http://site.test'));
    }
}
