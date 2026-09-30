<?php
/**
 * 授权码的传输安全（2026-09-30）。
 *
 * 响应签名只能证明「服务器的回复」没被伪造；授权码在「请求」里，要靠 TLS 证书校验
 * 与不进网址来保护：不校验证书，中间人能读走授权码；写进网址，会留在服务器访问日志、
 * CDN 与代理记录里。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LicenseTransportTest extends TestCase
{
    public function testLicenseRequestsAlwaysVerifyCertificates(): void
    {
        $src = (string) file_get_contents(ROOT_PATH . '/includes/License.php');
        self::assertDoesNotMatchRegularExpression("/'verify_peer'\\s*=>\\s*false/", $src);
        self::assertDoesNotMatchRegularExpression('/CURLOPT_SSL_VERIFYPEER\\s*=>\\s*false/', $src);
        self::assertStringContainsString('CURLOPT_SSL_VERIFYHOST => 2', $src);
        self::assertStringContainsString('CURLOPT_FOLLOWLOCATION => false', $src);
        // 只有证书校验失败才换用随包根证书重试；连不上服务器不重试
        self::assertStringContainsString('if ($response !== null || !$tlsFailed) {', $src);
        self::assertStringContainsString('in_array($errno, [35, 51, 58, 60, 77, 83], true)', $src);
    }

    public function testBundledTrustAnchorsAreExactlyTheIsrgRoots(): void
    {
        require_once ROOT_PATH . '/includes/License.php';
        $file = license_ca_bundle();
        self::assertStringEndsWith('/includes/certs/isrg-roots.pem', str_replace('\\', '/', $file));
        preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', (string) file_get_contents($file), $m);
        $fingerprints = array_map(static fn (string $pem): string => (string) openssl_x509_fingerprint($pem, 'sha256'), $m[0]);
        self::assertSame([
            '96bcec06264976f37460779acf28c5a7cfe8a3c0aae11a8ffcee05c0bddf08c6',   // ISRG Root X1
            '69729b8e15a86efc177a57afb7171dfc64add28c2fca8cf1507e34453ccb1470',   // ISRG Root X2
        ], $fingerprints);
    }

    public function testCatalogRequestsKeepTheKeyOutOfTheUrl(): void
    {
        require_once ROOT_PATH . '/includes/MarketCatalogRequest.php';
        parse_str(\MarketCatalogRequest::query('seo'), $query);
        self::assertArrayNotHasKey('key', $query);
        self::assertArrayNotHasKey('domain', $query);
        parse_str(\MarketCatalogRequest::credentials(), $body);
        self::assertSame(['key', 'domain'], array_keys($body));

        $plugin = (string) file_get_contents(ROOT_PATH . '/includes/PluginMarketInstall.php');
        self::assertStringContainsString('MarketCatalogRequest::query($query), 15, 0, MarketCatalogRequest::credentials())', $plugin);
        $admin = (string) file_get_contents(ROOT_PATH . '/admin/plugin.php');
        self::assertStringContainsString('pluginMarketHttpGet($url, 15, $status, 0, $tooLarge, MarketCatalogRequest::credentials())', $admin);

        $media = (string) file_get_contents(ROOT_PATH . '/includes/RemoteOfficialMedia.php');
        self::assertDoesNotMatchRegularExpression("/'key' => license_key\\(\\),\\s*'domain' => license_domain\\(\\),\\s*\\];\\s*\\n\\s*\\n?\\s*(?:\\/\\/[^\\n]*\\n\\s*)?\\\$url = [^;]*list\\.php\\?/", $media);
        self::assertStringContainsString("\$body = self::httpRequest(\$url, 'POST', http_build_query([", $media);

        $blox = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxRemoteTemplateProvider.php');
        self::assertStringContainsString("\$query = ['protocol_version' => (string) self::PROTOCOL_VERSION];", $blox);
        self::assertStringContainsString('($this->httpGet)($url, 15, self::MAX_CATALOG_BYTES, $credentials)', $blox);
    }
}
