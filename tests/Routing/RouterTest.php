<?php
/**
 * Tests for includes/Router.php — the named-route URL builder.
 *
 * Locks in the URL formats the existing .htaccess / nginx.conf already
 * support, so a future format change (e.g. dropping ".html") is one
 * obvious diff in Router.php instead of a multi-template hunt.
 */

declare(strict_types=1);

namespace Yikai\Tests\Routing;

use InvalidArgumentException;
use Yikai\Tests\TestCase;

require_once dirname(__DIR__, 2) . '/includes/Router.php';

class RouterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Wipe between tests so plugin-style register() calls don't bleed.
        \Router::reset();
    }

    // ───── default routes ─────

    public function testHome(): void
    {
        $this->assertSame('/', \Router::url('home'));
    }

    public function testChannelBySlug(): void
    {
        $this->assertSame('/news.html', \Router::url('channel', ['slug' => 'news']));
    }

    public function testChannelBySlugWithPage(): void
    {
        $this->assertSame('/news/page/3.html', \Router::url('channel', ['slug' => 'news', 'page' => 3]));
    }

    public function testChannelByIdWhenNoSlug(): void
    {
        $this->assertSame('/list/42.html', \Router::url('channel', ['id' => 42]));
    }

    public function testChannelByIdWithPage(): void
    {
        $this->assertSame('/list/42/page/2.html', \Router::url('channel', ['id' => 42, 'page' => 2]));
    }

    public function testChannelWithKeyword(): void
    {
        $url = \Router::url('channel', ['slug' => 'news', 'keyword' => '林']);
        $this->assertStringStartsWith('/news.html?', $url);
        $this->assertStringContainsString('keyword=', $url);
    }

    public function testChannelMissingArgsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \Router::url('channel', []);
    }

    public function testDetail(): void
    {
        $this->assertSame('/detail/7.html', \Router::url('detail', ['id' => 7]));
    }

    public function testDetailMissingIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \Router::url('detail');
    }

    public function testArticleById(): void
    {
        $this->assertSame('/news/article/123.html', \Router::url('article', ['id' => 123]));
    }

    public function testArticleBySlugUrlEncodes(): void
    {
        $this->assertSame('/news/article/hello-world.html', \Router::url('article', ['slug' => 'hello-world']));
    }

    public function testArticleSlugWinsOverId(): void
    {
        // When both provided, slug wins (matches the existing .htaccess behavior).
        $url = \Router::url('article', ['id' => 99, 'slug' => 'foo']);
        $this->assertSame('/news/article/foo.html', $url);
    }

    public function testNewsList(): void
    {
        $this->assertSame('/news.html', \Router::url('news'));
        $this->assertSame('/news/page/2.html', \Router::url('news', ['page' => 2]));
        $this->assertSame('/news/biz.html', \Router::url('news', ['cat' => 'biz']));
        $this->assertSame('/news/biz/page/3.html', \Router::url('news', ['cat' => 'biz', 'page' => 3]));
    }

    public function testProductById(): void
    {
        $this->assertSame('/product/detail/55.html', \Router::url('product', ['id' => 55]));
    }

    public function testProductBySlug(): void
    {
        $this->assertSame('/product/detail/abc-123.html', \Router::url('product', ['slug' => 'abc-123']));
    }

    public function testProductCategory(): void
    {
        $this->assertSame('/product/cat-a.html', \Router::url('product.category', ['slug' => 'cat-a']));
        $this->assertSame('/product/cat-a/page/2.html', \Router::url('product.category', ['slug' => 'cat-a', 'page' => 2]));
    }

    public function testJob(): void
    {
        $this->assertSame('/job/8.html', \Router::url('job', ['id' => 8]));
    }

    public function testSearchAppendsKeyword(): void
    {
        $url = \Router::url('search', ['keyword' => 'hello']);
        $this->assertSame('/search.html?keyword=hello', $url);
    }

    public function testSitemapAndContact(): void
    {
        $this->assertSame('/sitemap.xml',  \Router::url('sitemap'));
        $this->assertSame('/contact.html', \Router::url('contact'));
    }

    // ───── unknown route + custom registration ─────

    public function testUnknownRouteThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \Router::url('totally.unknown.route');
    }

    public function testHas(): void
    {
        $this->assertTrue(\Router::has('home'));
        $this->assertFalse(\Router::has('not.registered'));
    }

    public function testListRoutesReturnsPatterns(): void
    {
        $list = \Router::listRoutes();
        $this->assertIsArray($list);
        $this->assertArrayHasKey('home', $list);
        $this->assertArrayHasKey('article', $list);
    }

    public function testCanRegisterCustomRoute(): void
    {
        \Router::register('vendor.report', '/report/{year}.html',
            fn(array $p) => '/report/' . (int)($p['year'] ?? 0) . '.html');

        $this->assertTrue(\Router::has('vendor.report'));
        $this->assertSame('/report/2026.html', \Router::url('vendor.report', ['year' => 2026]));
    }

    public function testCustomRouteCanOverrideBuiltin(): void
    {
        // Plugins can intentionally redirect a known route to a different
        // URL format. Last write wins.
        \Router::register('home', '/', fn($p) => '/welcome.html');
        $this->assertSame('/welcome.html', \Router::url('home'));
    }

    // ───── route() global helper ─────

    public function testGlobalRouteHelper(): void
    {
        $this->assertSame('/news/article/42.html', route('article', ['id' => 42]));
    }
}
