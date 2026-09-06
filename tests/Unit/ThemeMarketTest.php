<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use ReflectionMethod;
use ThemeMarket;

require_once ROOT_PATH . '/config/version.php';
require_once ROOT_PATH . '/includes/ThemeMarket.php';
require_once ROOT_PATH . '/includes/License.php';

final class ThemeMarketTest extends TestCase
{
    public function testCatalogIsNormalizedAndMalformedEntriesAreIgnored(): void
    {
        $valid = $this->catalogTheme();
        $response = ThemeMarket::request('', static fn (string $_url): string => json_encode([
            'code' => 0,
            'data' => ['updated_at' => '2026-09-01', 'themes' => [
                $valid,
                array_merge($valid, ['slug' => '../bad']),
                array_merge($valid, ['name' => 'Duplicate']),
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertNotNull($response);
        self::assertSame('https://update.yikaicms.com/api/themes/list.php', $this->capturedUrl(''));
        self::assertCount(1, $response['data']['themes']);
        self::assertSame('business', $response['data']['themes'][0]['slug']);
        self::assertSame('sha256:' . str_repeat('a', 64), $response['data']['themes'][0]['hash']);
        self::assertSame('https://update.yikaicms.com/assets/themes/business/screenshot.jpg', $response['data']['themes'][0]['screenshot']);
    }

    /** @dataProvider invalidMetadataProvider */
    public function testOfficialCatalogMetadataFailsClosed(array $override): void
    {
        $response = ThemeMarket::request('', fn (string $_url): string => json_encode([
            'code' => 0,
            'data' => ['themes' => [array_merge($this->catalogTheme(), $override)]],
        ], JSON_THROW_ON_ERROR));

        self::assertNotNull($response);
        self::assertSame([], $response['data']['themes']);
    }

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function invalidMetadataProvider(): array
    {
        return [
            'unknown CMS constraint' => [['requires_cms' => '^1.19.0']],
            'short PHP constraint' => [['requires_php' => '>=8.0']],
            'incompatible CMS constraint' => [['requires_cms' => '>=99.0.0']],
            'invalid hash' => [['hash' => 'sha256:nope']],
            'invalid signature' => [['sig' => '***']],
            'package/version mismatch' => [['package' => 'business-v9.0.0.zip']],
            'URL/package mismatch' => [['download_url' => 'https://update.yikaicms.com/packages/themes/minimal-v1.0.1.zip']],
            'oversized declaration' => [['size_kb' => 51201]],
        ];
    }

    public function testOnlyNewerInstalledThemesBecomeUpdates(): void
    {
        $updates = ThemeMarket::availableUpdates(
            ['business' => '1.0.0', 'default' => '1.0.0'],
            [
                ['slug' => 'business', 'name' => 'Business', 'version' => '1.0.1'],
                ['slug' => 'default', 'name' => 'Default', 'version' => '1.0.0'],
                ['slug' => 'trade', 'name' => 'Trade', 'version' => '2.0.0'],
            ]
        );

        self::assertCount(1, $updates);
        self::assertSame('1.0.0', $updates[0]['current_version']);
        self::assertSame('1.0.1', $updates[0]['latest_version']);
    }

    public function testServerSideVersionGateRejectsEqualOlderAndInvalidVersions(): void
    {
        $local = ['business' => '1.2.0'];

        self::assertTrue(ThemeMarket::isRemoteVersionNewer($local, 'business', '1.2.1'));
        self::assertFalse(ThemeMarket::isRemoteVersionNewer($local, 'business', '1.2.0'));
        self::assertFalse(ThemeMarket::isRemoteVersionNewer($local, 'business', '1.1.9'));
        self::assertFalse(ThemeMarket::isRemoteVersionNewer($local, 'business', 'next'));
        self::assertTrue(ThemeMarket::isRemoteVersionNewer($local, 'minimal', '1.0.0'));
    }

    #[RequiresPhpExtension('openssl')]
    public function testSignatureCoversCatalogSlugVersionAndHash(): void
    {
        $hash = 'sha256:36c35cff81e43b21a096a43628d19d3defa5a70d109de029512369f8c1f64107';
        $encoded = 'H/iaHUcz0vRkXMJdXOsgy8D3o+KYwfmdnqSVIDMdpEseG3khMCYF85XYch0bKLZtToFB15X5z4m82RYcFo9SM+qKLRXDn/3cvKWa8QW1NMYe9i26+BDzeiRzh6wWq/SfQozwPHTSCO7FR8mVnISUieyjaIltA1dJeCUOQAqLz7kC3nmi5XsN991bmzgwk/eVRWOp01zuJXm5OvFREsoqL8pxd5R0dGq8guMh1MTrOlM2O9fqdPvjRhyGBerliHDPAMxw74a5+wMTzJ1kSg5Ki70F8OZ8AihAEb5GJSGcp/uH3THENjRyxasy+M2yEvE6qEXacDB7bwijPkJmK1sQsA==';

        self::assertTrue(ThemeMarket::verifyPackageSignature('aurora', '1.0.3', $hash, $encoded, license_pubkey()));
        self::assertFalse(ThemeMarket::verifyPackageSignature('aurora', '1.0.4', $hash, $encoded, license_pubkey()));
        self::assertFalse(ThemeMarket::verifyPackageSignature('aurora', '1.0.3', 'sha256:bad', $encoded, license_pubkey()));
        self::assertFalse(ThemeMarket::verifyPackageSignature('aurora', '1.0.3', $hash, '***', license_pubkey()));
    }

    public function testMarketplaceEndpointClosesVersionGateBeforeDownloadAndUsesExpectedPackageVersion(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/theme.php');
        self::assertNotFalse($source);
        $gate = strpos($source, 'ThemeMarket::isRemoteVersionNewer');
        $download = strpos($source, 'ThemeMarket::downloadPackageToFile');

        self::assertIsInt($gate);
        self::assertIsInt($download);
        self::assertLessThan($download, $gate);
        self::assertStringContainsString('->install($tmpZip, $slug, $remoteVersion)', $source);
        self::assertStringNotContainsString('ThemeMarket::downloadPackage(', $source);
    }

    public function testInvalidResponseFailsClosed(): void
    {
        self::assertNull(ThemeMarket::request('', static fn (string $_url): string => '<html>error</html>'));
        self::assertNull(ThemeMarket::request('', static fn (string $_url): string => '{"code":1}'));
    }

    public function testCatalogScreenshotMustUseOfficialStaticThemePath(): void
    {
        $response = ThemeMarket::request('', fn (string $_url): string => json_encode([
            'code' => 0,
            'data' => ['themes' => [
                $this->catalogTheme(['screenshot' => 'https://example.com/theme.jpg']),
                $this->catalogTheme([
                    'slug' => 'minimal',
                    'version' => '1.0.2',
                    'package' => 'minimal-v1.0.2.zip',
                    'download_url' => 'https://update.yikaicms.com/packages/themes/minimal-v1.0.2.zip',
                    'screenshot' => 'https://update.yikaicms.com/assets/themes/business/screenshot.jpg',
                ]),
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertNotNull($response);
        self::assertSame('', $response['data']['themes'][0]['screenshot']);
        self::assertSame('', $response['data']['themes'][1]['screenshot']);
    }

    public function testPackageDownloadRejectsNonOfficialLocationsBeforeRequest(): void
    {
        $target = sys_get_temp_dir() . '/theme-market-invalid-' . bin2hex(random_bytes(4));
        $called = false;
        $transport = static function () use (&$called): array {
            $called = true;
            return ['status' => 200];
        };

        foreach ([
            'http://update.yikaicms.com/packages/themes/business.zip',
            'https://evil.example/packages/themes/business.zip',
            'https://update.yikaicms.com/redirect?file=business.zip',
            'https://update.yikaicms.com/packages/themes/../business.zip',
        ] as $url) {
            $result = ThemeMarket::downloadPackageToFile($url, $target, 100, 5, $transport);
            self::assertFalse($result['ok']);
            self::assertSame('invalid_url', $result['code']);
        }
        self::assertFalse($called);
        self::assertFileDoesNotExist($target);
    }

    public function testStreamingDownloadAcceptsMissingContentLength(): void
    {
        $target = $this->tempTarget();
        $transport = static function (string $url, callable $acceptLength, callable $writeChunk, int $timeout): array {
            if (!$acceptLength(null) || $timeout !== 5 || !str_starts_with($url, 'https://update.yikaicms.com/')) {
                return ['status' => 0, 'error' => 'bad test input'];
            }
            $writeChunk('abc');
            $writeChunk('def');
            return ['status' => 200];
        };

        $result = ThemeMarket::downloadPackageToFile($this->packageUrl(), $target, 6, 5, $transport);

        self::assertTrue($result['ok']);
        self::assertSame(6, $result['bytes']);
        self::assertSame('abcdef', file_get_contents($target));
        @unlink($target);
    }

    public function testStreamingLimitStopsOversizedBodyAndRemovesPartialFile(): void
    {
        $target = $this->tempTarget();
        $secondWrite = -1;
        $transport = static function (string $url, callable $acceptLength, callable $writeChunk) use (&$secondWrite): array {
            $writeChunk('abc');
            $secondWrite = $writeChunk('def');
            return ['status' => 200];
        };

        $result = ThemeMarket::downloadPackageToFile($this->packageUrl(), $target, 5, 5, $transport);

        self::assertFalse($result['ok']);
        self::assertSame('too_large', $result['code']);
        self::assertSame(0, $secondWrite);
        self::assertFileDoesNotExist($target);
    }

    public function testOversizedDeclaredLengthStopsBeforeFirstChunk(): void
    {
        $target = $this->tempTarget();
        $wrote = false;
        $transport = static function (string $url, callable $acceptLength, callable $writeChunk) use (&$wrote): array {
            if ($acceptLength(101)) {
                $wrote = $writeChunk('unexpected') > 0;
            }
            return ['status' => 200];
        };

        $result = ThemeMarket::downloadPackageToFile($this->packageUrl(), $target, 100, 5, $transport);

        self::assertFalse($result['ok']);
        self::assertSame('too_large', $result['code']);
        self::assertFalse($wrote);
        self::assertFileDoesNotExist($target);
    }

    public function testUnderreportedContentLengthCannotBypassStreamingLimit(): void
    {
        $target = $this->tempTarget();
        $transport = static function (string $_url, callable $acceptLength, callable $writeChunk): array {
            $acceptLength(1);
            $writeChunk('abc');
            $writeChunk('def');
            return ['status' => 200];
        };

        $result = ThemeMarket::downloadPackageToFile($this->packageUrl(), $target, 5, 5, $transport);

        self::assertFalse($result['ok']);
        self::assertSame('too_large', $result['code']);
        self::assertFileDoesNotExist($target);
    }

    public function testInterruptedTransferRemovesPartialFile(): void
    {
        $target = $this->tempTarget();
        $transport = static function (string $url, callable $acceptLength, callable $writeChunk): array {
            $acceptLength(20);
            $writeChunk('partial');
            return ['status' => 0, 'error' => 'connection reset'];
        };

        $result = ThemeMarket::downloadPackageToFile($this->packageUrl(), $target, 100, 5, $transport);

        self::assertFalse($result['ok']);
        self::assertSame('http_error', $result['code']);
        self::assertFileDoesNotExist($target);
    }

    public function testFopenFallbackCopyReadsTheStreamInChunks(): void
    {
        $stream = fopen('php://temp', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, str_repeat('a', 9000));
        rewind($stream);
        $chunks = [];
        $copy = new ReflectionMethod(ThemeMarket::class, 'copyStream');
        $copy->setAccessible(true);

        $error = $copy->invoke(null, $stream, static function (string $chunk) use (&$chunks): int {
            $chunks[] = strlen($chunk);
            return strlen($chunk);
        });
        fclose($stream);

        self::assertSame('', $error);
        self::assertSame([8192, 808], $chunks);
    }

    /** @return array<string,mixed> */
    private function catalogTheme(array $override = []): array
    {
        return array_merge([
            'slug' => 'business',
            'name' => 'Business',
            'name_en' => 'Business',
            'name_ja' => 'Business',
            'version' => '1.0.1',
            'package' => 'business-v1.0.1.zip',
            'download_url' => 'https://update.yikaicms.com/packages/themes/business-v1.0.1.zip',
            'size_kb' => 1,
            'hash' => 'SHA256:' . str_repeat('a', 64),
            'sig' => base64_encode('signature'),
            'requires_cms' => '>=1.0.0',
            'requires_php' => '>=8.0.0',
            'screenshot' => 'https://update.yikaicms.com/assets/themes/business/screenshot.jpg',
        ], $override);
    }

    private function capturedUrl(string $query): string
    {
        $url = '';
        ThemeMarket::request($query, static function (string $value) use (&$url): string {
            $url = $value;
            return '{"code":0,"data":{"themes":[]}}';
        });
        return $url;
    }

    private function packageUrl(): string
    {
        return 'https://update.yikaicms.com/packages/themes/business-v1.0.1.zip';
    }

    private function tempTarget(): string
    {
        return sys_get_temp_dir() . '/theme-market-' . bin2hex(random_bytes(5));
    }
}
