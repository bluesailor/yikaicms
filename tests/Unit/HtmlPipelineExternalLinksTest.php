<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HtmlPipeline;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/HtmlPipeline.php';

final class HtmlPipelineExternalLinksTest extends TestCase
{
    private mixed $savedHost;
    private mixed $savedConfig;

    protected function setUp(): void
    {
        $this->savedHost = $_SERVER['HTTP_HOST'] ?? null;
        $this->savedConfig = $GLOBALS['_test_config'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedHost === null) unset($_SERVER['HTTP_HOST']); else $_SERVER['HTTP_HOST'] = $this->savedHost;
        if ($this->savedConfig === null) unset($GLOBALS['_test_config']); else $GLOBALS['_test_config'] = $this->savedConfig;
    }

    /** 站点域名留空时，指向本站的绝对链接也不能被改成新标签页打开。 */
    public function testOwnAbsoluteLinksStayInPlaceWhenSiteUrlIsEmpty(): void
    {
        $GLOBALS['_test_config'] = ['site_url' => ''];
        $_SERVER['HTTP_HOST'] = 'Example.COM:8080';

        $html = HtmlPipeline::secureExternalLinks('<a href="http://example.com/about.html">About</a>');

        self::assertStringNotContainsString('target=', $html);
        self::assertStringNotContainsString('noopener', $html);
    }

    public function testOtherSitesStillOpenInANewTab(): void
    {
        $GLOBALS['_test_config'] = ['site_url' => 'https://www.example.com'];
        $_SERVER['HTTP_HOST'] = 'www.example.com';

        $html = HtmlPipeline::secureExternalLinks(
            '<a href="https://www.example.com/a.html">own</a><a href="https://partner.example.net/" rel="nofollow">partner</a>'
        );

        self::assertStringContainsString('<a href="https://www.example.com/a.html">own</a>', $html);
        self::assertMatchesRegularExpression('#<a (?=[^>]*target="_blank")(?=[^>]*rel="nofollow noopener noreferrer")[^>]*href="https://partner\.example\.net/"#', $html);
    }

    /** 语言域名：正在访问的语言域名是本站，后台填的主域名也是本站。 */
    public function testRequestHostAndConfiguredHostAreBothInternal(): void
    {
        $GLOBALS['_test_config'] = ['site_url' => 'https://example.com'];
        $_SERVER['HTTP_HOST'] = 'en.example.com';

        $html = HtmlPipeline::secureExternalLinks('<a href="https://en.example.com/p.html">en</a><a href="https://example.com/">main</a>');

        self::assertStringNotContainsString('target=', $html);
    }

    public function testRelativeAndAnchorLinksAreUntouched(): void
    {
        $GLOBALS['_test_config'] = ['site_url' => ''];
        unset($_SERVER['HTTP_HOST']);
        $source = '<a href="/contact.html">c</a><a href="#top">t</a><a href="mailto:a@example.com">m</a>';

        self::assertSame($source, HtmlPipeline::secureExternalLinks($source));
    }
}
