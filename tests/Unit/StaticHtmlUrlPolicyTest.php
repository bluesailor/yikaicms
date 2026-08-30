<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/StaticHtmlUrlPolicy.php';

final class StaticHtmlUrlPolicyTest extends TestCase
{
    /** @dataProvider rejectedBases */
    public function testRejectsUntrustedBaseUrls(string $url): void
    {
        $this->expectException(InvalidArgumentException::class);
        StaticHtmlUrlPolicy::baseUrl($url, 'https://example.test');
    }

    public static function rejectedBases(): array
    {
        return array_map(static fn (string $url): array => [$url], [
            'http://example.test', 'https://example.test:444', 'https://evil.test',
            'http://127.0.0.1', 'http://169.254.169.254/latest/meta-data', 'http://2130706433',
            'http://[::1]', '//example.test', 'file:///etc/passwd', 'gopher://127.0.0.1',
            'https://example.test@evil.test', 'https://user:pass@example.test',
            'https://example.test.evil.test', 'https://example.test./', 'https://example.test?x=1',
            'https://example.test#x', 'https://example.test/path', 'https://example.test/../',
            'https://example.test/%2e%2e', 'https://example.test/%252e',
            "https://example.test\\@evil.test", "https://example.test/\r\nHost: evil.test",
        ]);
    }

    public function testAcceptsOnlyConfiguredOriginAndOptionalInstallPath(): void
    {
        self::assertSame('https://example.test', StaticHtmlUrlPolicy::baseUrl('https://EXAMPLE.test:443/', 'https://example.test'));
        self::assertSame('http://localhost:8080', StaticHtmlUrlPolicy::baseUrl('', 'http://localhost:8080'));
        self::assertSame('https://example.test/cms', StaticHtmlUrlPolicy::baseUrl('', 'https://example.test/cms/'));
        self::assertTrue(StaticHtmlUrlPolicy::allowsRequest('https://example.test/cms/about.html', 'https://example.test/cms'));
        self::assertFalse(StaticHtmlUrlPolicy::allowsRequest('https://example.test/cms-evil/about.html', 'https://example.test/cms'));
        self::assertFalse(StaticHtmlUrlPolicy::allowsRequest('https://example.test/cms/../admin/', 'https://example.test/cms'));
        self::assertFalse(StaticHtmlUrlPolicy::allowsRequest('https://evil.test/cms/about.html', 'https://example.test/cms'));
        self::assertFalse(StaticHtmlUrlPolicy::allowsRequest('https://example.test/about.html?q=1', 'https://example.test'));
    }

    public function testHttpHostIsNotAnOutboundAuthorityAndTlsAndRedirectsStayGuarded(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/static_html.php');
        $service = (string) file_get_contents(ROOT_PATH . '/includes/StaticHtml.php');
        self::assertStringNotContainsString("\$_SERVER['HTTP_HOST']", $page . $service);
        self::assertStringContainsString('StaticHtmlUrlPolicy::allowsRequest($url, self::baseUrl())', $service);
        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $service);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYPEER => true', $service);
    }

    public function testEncodedMultilingualSlugsDoNotRelaxPathGuards(): void
    {
        $base = 'https://example.test/cms';
        self::assertTrue(StaticHtmlUrlPolicy::allowsRequest($base . '/%E4%BA%A7%E5%93%81.html', $base));
        foreach (['/%2e%2e/admin', '/%252e%252e/admin', '/%2f%2fevil.test', '/%5cadmin', '/%0d%0a', '/bad%xx'] as $path) {
            self::assertFalse(StaticHtmlUrlPolicy::allowsRequest($base . $path, $base));
        }
    }
}
