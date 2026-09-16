<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxRemoteTemplateInstaller;
use BloxRemoteTemplateProvider;
use MarketDownloadUrl;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use RuntimeException;
use ThemeInstaller;
use ThemeMarket;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/ThemeMarket.php';
require_once ROOT_PATH . '/includes/ThemeInstaller.php';
require_once ROOT_PATH . '/includes/PluginInstaller.php';
require_once ROOT_PATH . '/includes/License.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class G5MarketClientTest extends TestCase
{
    private const FIXTURES = ROOT_PATH . '/tests/fixtures/g5-market/';
    private const TOKEN_URL = MarketDownloadUrl::ENDPOINT . '?token=b2ZmbGluZQ.dHJhbnNwb3J0';
    private string $tempRoot;

    protected function setUp(): void
    {
        // Future-release fixtures must not change the actual CMS release version or public key.
        if (!defined('CMS_VERSION')) define('CMS_VERSION', '1.20.0');
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir() . '/yikai-g5-client-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->tempRoot, 0700));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempRoot);
        parent::tearDown();
    }

    protected function schemaSql(): array
    {
        $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql');
        $statements = [];
        foreach (['blox_templates', 'blox_remote_template_states', 'blox_import_reviews'] as $table) {
            self::assertSame(1, preg_match('/CREATE TABLE "yikai_' . $table . '" \([\s\S]*?\n\);/', $sql, $match));
            $statements[] = str_replace('"yikai_' . $table . '"', '"' . $table . '"', $match[0]);
        }
        return $statements;
    }

    public function testPublishedSamplesHaveValidSignaturesButNotProductionTrust(): void
    {
        foreach (['plugin' => 'plugins', 'theme' => 'themes', 'template' => 'templates'] as $kind => $key) {
            $item = $this->catalog($kind)['data'][$key][0];
            $bytes = (string) file_get_contents(self::FIXTURES . $kind . '-package.zip');
            self::assertSame($item['hash'], 'sha256:' . hash('sha256', $bytes));
            self::assertTrue(ThemeMarket::verifyPackageSignature($item['slug'], $item['version'], $item['hash'], $item['sig'], $this->publicKey()));
            self::assertFalse(ThemeMarket::verifyPackageSignature($item['slug'], $item['version'], $item['hash'], $item['sig'], license_pubkey()));
        }
    }

    public function testThemeTokenCatalogDownloadsAndInstallsWithoutChangingActiveTheme(): void
    {
        $catalog = $this->catalog('theme');
        self::assertSame([], ThemeMarket::request('', static fn (): string => json_encode($catalog, JSON_THROW_ON_ERROR))['data']['themes']);
        $catalog['data']['themes'][0]['download_url'] = self::TOKEN_URL;
        $response = ThemeMarket::request('', static fn (): string => json_encode($catalog, JSON_THROW_ON_ERROR));
        self::assertCount(1, $response['data']['themes']);
        $item = $response['data']['themes'][0];
        $file = $this->tempRoot . '/theme.zip';
        $bytes = (string) file_get_contents(self::FIXTURES . 'theme-package.zip');
        $result = ThemeMarket::downloadPackageToFile($item['download_url'], $file, ThemeMarket::MAX_PACKAGE_BYTES, 10,
            static function (string $url, callable $length, callable $write) use ($bytes): array {
                self::assertSame(self::TOKEN_URL, $url);
                self::assertTrue($length(strlen($bytes)));
                self::assertSame(strlen($bytes), $write($bytes));
                return ['status' => 200];
            });
        self::assertTrue($result['ok']);
        self::assertSame($item['hash'], 'sha256:' . hash_file('sha256', $file));
        self::assertTrue(ThemeMarket::verifyPackageSignature($item['slug'], $item['version'], $item['hash'], $item['sig'], $this->publicKey()));
        $installer = new ThemeInstaller($this->tempRoot . '/themes', $this->tempRoot . '/storage');
        $installed = $installer->install($file, $item['slug'], $item['version']);
        self::assertTrue($installed['ok'], json_encode($installed));
        self::assertFileExists($this->tempRoot . '/themes/' . $item['slug'] . '/layouts/header.php');
        self::assertSame('', $installed['backup']);
        $replaced = $installer->install($file, $item['slug'], $item['version']);
        self::assertTrue($replaced['ok']);
        self::assertFileExists($replaced['backup'] . '/layouts/header.php');
    }

    public function testThemeFailureDoesNotRetainAnErrorBodyAsAPackage(): void
    {
        foreach ([302, 401, 410, 429, 503] as $status) {
            $path = $this->tempRoot . '/error.zip';
            $result = ThemeMarket::downloadPackageToFile(self::TOKEN_URL, $path, 1024, 10,
                static function (string $url, callable $length, callable $write) use ($status): array {
                    $write('{"error":"denied"}');
                    return ['status' => $status];
                });
            self::assertFalse($result['ok']);
            self::assertSame('http_error', $result['code']);
            self::assertFileDoesNotExist($path);
        }
    }

    public function testBloxReviewInstallsSignedContentOnceAndCopyRemainsIndependent(): void
    {
        $item = $this->catalog('template')['data']['templates'][0];
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $review = $installer->prepareInstall($item['slug'], 9);
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $result = $installer->confirmInstall($review['review_id'], [], 9);
        $row = bloxTemplateModel()->findForExport($result['id']);
        $document = json_decode($row['draft_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Hello', $document['sections'][0]['columns'][0]['elements'][0]['data']['text']);
        self::assertSame(0, (int) $row['status']);
        self::assertNull($row['published_data']);
        $replay = $installer->confirmInstall($review['review_id'], [], 9);
        self::assertSame($result['id'], $replay['id']);
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $copyReview = $installer->prepareCopy($item['slug'], 9);
        $copy = $installer->confirmCopy($copyReview['review_id'], [], 9);
        self::assertNotSame($result['id'], $copy['id']);
        self::assertSame('import', bloxTemplateModel()->findForExport($copy['id'])['source']);
        self::assertNull(bloxRemoteTemplateStateModel()->forTemplate($copy['id']));
    }

    public function testBloxTamperedBytesAndSignatureNeverCreateReviewsOrTemplates(): void
    {
        $slug = $this->catalog('template')['data']['templates'][0]['slug'];
        foreach (['bytes', 'signature'] as $tamper) {
            try {
                (new BloxRemoteTemplateInstaller($this->provider($tamper)))->prepareInstall($slug, 9);
                self::fail('Tampered package must be rejected');
            } catch (RuntimeException $e) {
                self::assertSame($tamper === 'bytes' ? 'blox_template_remote_hash_failed' : 'blox_template_remote_signature_failed', $e->getMessage());
            }
            self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
            self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_import_reviews'));
        }
    }

    public function testLiveOfficialCatalogAndBloxInstall(): void
    {
        $base = (string) getenv('YIKAI_G5_OFFICIAL_BASE');
        if ($base === '') self::markTestSkipped('Opt-in official v2 HTTP integration');
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#D', $base);
        $keyFile = (string) getenv('YIKAI_G5_TEST_PUBLIC_KEY');
        self::assertFileExists($keyFile);
        $key = (string) file_get_contents($keyFile);
        $provider = new BloxRemoteTemplateProvider(
            static function (string $url) use ($base): string {
                $catalog = str_starts_with($url, BloxRemoteTemplateProvider::API_URL);
                if ($catalog) {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    $query['key'] = 'TEST-OFFICIAL';
                    $query['domain'] = 'customer.test';
                    $target = $base . '/api/templates/list.php?' . http_build_query($query);
                } else {
                    self::assertTrue(MarketDownloadUrl::isTokenUrl($url));
                    $target = $base . '/api/market/download.php' . substr($url, strlen(MarketDownloadUrl::ENDPOINT));
                }
                $body = file_get_contents($target, false, stream_context_create(['http' => [
                    'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 0,
                ]]));
                self::assertMatchesRegularExpression('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '');
                self::assertIsString($body);
                if (!$catalog) return $body;
                $response = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
                foreach ($response['data']['templates'] as &$item) {
                    if (($item['download_url'] ?? '') === '') continue;
                    self::assertStringStartsWith($base . '/api/market/download.php?token=', $item['download_url']);
                    $item['download_url'] = MarketDownloadUrl::ENDPOINT . substr($item['download_url'], strlen($base . '/api/market/download.php'));
                }
                unset($item);
                return json_encode($response, JSON_THROW_ON_ERROR);
            },
            static fn (string $canonical, string $signature): bool => openssl_verify($canonical, base64_decode($signature, true), $key, OPENSSL_ALGO_SHA256) === 1,
            'en'
        );
        $items = $provider->installable();
        self::assertContains('remote:header-mega', array_column($items, 'key'));
        self::assertContains('remote:community-example', array_column($items, 'key'));
        $installer = new BloxRemoteTemplateInstaller($provider);
        $review = $installer->prepareInstall('advantages-3icon', 9);
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $installed = $installer->confirmInstall($review['review_id'], [], 9);
        $row = bloxTemplateModel()->findForExport($installed['id']);
        self::assertSame('advantages-3icon', $row['source_ref']);
        self::assertNull($row['published_data']);
        $document = json_decode($row['draft_data'], true, 128, JSON_THROW_ON_ERROR);
        self::assertNotEmpty($document['sections']);
        self::assertNotEmpty($document['sections'][0]['columns'][0]['elements']);
    }

    public function testLiveMarketDownloadAndClientInstall(): void
    {
        if (getenv('YIKAI_G5_LIVE') !== '1') {
            self::markTestSkipped('Opt-in isolated G5 HTTP round trip');
        }
        $base = 'http://127.0.0.1:18082';
        $publicFile = (string) getenv('YIKAI_G5_TEST_PUBLIC_KEY');
        self::assertFileExists($publicFile);
        $key = (string) file_get_contents($publicFile);
        $get = static function (string $url) use ($base): string {
            self::assertStringStartsWith($base . '/api/market/', $url);
            $context = stream_context_create(['http' => ['follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true, 'timeout' => 15]]);
            $body = file_get_contents($url, false, $context);
            self::assertMatchesRegularExpression('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '');
            self::assertIsString($body);
            return $body;
        };
        // Transport-only mapping: production URL validation still sees the fixed HTTPS origin.
        $catalog = static function (string $kind) use ($get, $base): string {
            $data = json_decode($get($base . '/api/market/catalog.php?kind=' . $kind . '&protocol_version=2'), true, 512, JSON_THROW_ON_ERROR);
            $list = $kind === 'theme' ? 'themes' : 'templates';
            foreach ($data['data'][$list] as &$item) {
                if (($item['download_url'] ?? '') === '') continue;
                self::assertStringStartsWith($base . '/api/market/download.php?token=', $item['download_url']);
                $item['download_url'] = MarketDownloadUrl::ENDPOINT . substr($item['download_url'], strlen($base . '/api/market/download.php'));
                self::assertTrue(MarketDownloadUrl::isTokenUrl($item['download_url']));
            }
            unset($item);
            return json_encode($data, JSON_THROW_ON_ERROR);
        };
        $download = static function (string $url) use ($get, $base): string {
            self::assertTrue(MarketDownloadUrl::isTokenUrl($url));
            return $get($base . '/api/market/download.php' . substr($url, strlen(MarketDownloadUrl::ENDPOINT)));
        };
        $themes = ThemeMarket::request('', static fn (): string => $catalog('theme'));
        self::assertNotEmpty($themes['data']['themes']);
        $theme = $themes['data']['themes'][0];
        $path = $this->tempRoot . '/live-theme.zip';
        $result = ThemeMarket::downloadPackageToFile($theme['download_url'], $path, ThemeMarket::MAX_PACKAGE_BYTES, 15,
            static function (string $url, callable $length, callable $write) use ($download): array {
                $bytes = $download($url);
                self::assertTrue($length(strlen($bytes)));
                self::assertSame(strlen($bytes), $write($bytes));
                return ['status' => 200];
            });
        self::assertTrue($result['ok']);
        self::assertSame($theme['hash'], 'sha256:' . hash_file('sha256', $path));
        self::assertTrue(ThemeMarket::verifyPackageSignature($theme['slug'], $theme['version'], $theme['hash'], $theme['sig'], $key));
        $installed = (new ThemeInstaller($this->tempRoot . '/themes', $this->tempRoot . '/storage'))->install($path, $theme['slug'], $theme['version']);
        self::assertTrue($installed['ok'], json_encode($installed));

        $provider = new BloxRemoteTemplateProvider(
            static fn (string $url): string => MarketDownloadUrl::isTokenUrl($url) ? $download($url) : $catalog('template'),
            static fn (string $canonical, string $sig): bool => openssl_verify($canonical, (string) base64_decode($sig, true), $key, OPENSSL_ALGO_SHA256) === 1,
            'en'
        );
        $items = $provider->installable(true);
        self::assertNotEmpty($items);
        $slug = substr($items[0]['key'], strlen('remote:'));
        $installer = new BloxRemoteTemplateInstaller($provider);
        $review = $installer->prepareInstall($slug, 9);
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $saved = $installer->confirmInstall($review['review_id'], [], 9);
        $row = bloxTemplateModel()->findForExport($saved['id']);
        $document = json_decode($row['draft_data'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Hello', $document['sections'][0]['columns'][0]['elements'][0]['data']['text']);
        self::assertNull($row['published_data']);
    }

    private function provider(string $tamper = ''): BloxRemoteTemplateProvider
    {
        $catalog = $this->catalog('template');
        $catalog['data']['templates'][0]['download_url'] = self::TOKEN_URL;
        if ($tamper === 'signature') $catalog['data']['templates'][0]['sig'] = base64_encode('bad-signature');
        $body = json_encode($catalog, JSON_THROW_ON_ERROR);
        $package = (string) file_get_contents(self::FIXTURES . 'template-package.zip');
        if ($tamper === 'bytes') $package .= 'tampered';
        $key = $this->publicKey();
        return new BloxRemoteTemplateProvider(
            static fn (string $url): string => $url === self::TOKEN_URL ? $package : $body,
            static fn (string $canonical, string $sig): bool => openssl_verify($canonical, (string) base64_decode($sig, true), $key, OPENSSL_ALGO_SHA256) === 1,
            'en'
        );
    }

    private function catalog(string $kind): array
    {
        $catalog = json_decode((string) file_get_contents(self::FIXTURES . 'catalog-' . $kind . '.json'), true, 512, JSON_THROW_ON_ERROR);
        // The preserved pre-v2 samples supply package bytes/signatures, not the current HTTP envelope.
        $catalog['data']['protocol_version'] = 2;
        return $catalog;
    }

    public function testLiveV2ThemeAndPluginDelivery(): void
    {
        $base = (string) getenv('YIKAI_G5_V2_BASE');
        if ($base === '') self::markTestSkipped('Opt-in isolated themes/plugins v2 HTTP integration');
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#D', $base);
        $keyFile = (string) getenv('YIKAI_G5_TEST_PUBLIC_KEY');
        self::assertFileExists($keyFile);
        $key = (string) file_get_contents($keyFile);
        $get = static function (string $path) use ($base): array {
            self::assertStringStartsWith('/api/', $path);
            $body = file_get_contents($base . $path, false, stream_context_create(['http' => [
                'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0,
            ]]));
            self::assertMatchesRegularExpression('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '');
            self::assertIsString($body);
            return [$body, $http_response_header];
        };
        $transport = static function (string $url) use ($get, $base): string {
            self::assertStringStartsWith('https://update.yikaicms.com/api/', $url);
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            self::assertSame('2', $query['protocol_version']);
            self::assertSame('1.20.0', $query['cms_version']);
            // Test credentials only; real license/configuration is never loaded.
            $query['key'] = 'TEST-OFFICIAL';
            $query['domain'] = 'customer.test';
            [$body] = $get(parse_url($url, PHP_URL_PATH) . '?' . http_build_query($query));
            $data = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            $list = isset($data['data']['themes']) ? 'themes' : 'plugins';
            foreach ($data['data'][$list] as &$item) {
                if (($item['download_url'] ?? '') === '') continue;
                self::assertStringStartsWith($base . '/api/market/download.php?token=', $item['download_url']);
                $item['download_url'] = \MarketDownloadUrl::ENDPOINT . substr($item['download_url'], strlen($base . '/api/market/download.php'));
            }
            unset($item);
            return json_encode($data, JSON_THROW_ON_ERROR);
        };
        $themes = ThemeMarket::request('', $transport);
        self::assertNotNull($themes);
        self::assertFalse($themes['data']['market']['community_unavailable']);
        $themeItems = array_column($themes['data']['themes'], null, 'slug');
        self::assertSame('cms_version_required', $themeItems['lighthouse']['locked_reason']);
        self::assertSame('official', $themeItems['lighthouse']['source']);
        $plugins = \MarketCatalogRequest::decode($transport('https://update.yikaicms.com/api/plugins/list.php?' . \MarketCatalogRequest::query()), 'plugins');
        self::assertNotNull($plugins);
        $pluginItems = array_column(\MarketCatalogItems::select($plugins['data']['plugins']), null, 'slug');
        self::assertSame('download_unavailable', $pluginItems['blox-pro']['locked_reason']);
        foreach (['theme' => ['business', 'tidewater'], 'plugin' => ['announcement', 'community-tool']] as $kind => $slugs) {
            foreach ($slugs as $index => $slug) {
                $item = ($kind === 'theme' ? $themeItems : $pluginItems)[$slug];
                self::assertSame($index === 0 ? 'official' : 'community', $item['source']);
                self::assertSame('', \MarketDownloadStatus::reason($item));
                $path = $this->tempRoot . '/' . $slug . '.zip';
                $download = ThemeMarket::downloadPackageToFile($item['download_url'], $path, ThemeMarket::MAX_PACKAGE_BYTES, 15,
                    static function (string $url, callable $length, callable $write) use ($get): array {
                        self::assertTrue(\MarketDownloadUrl::isTokenUrl($url));
                        [$bytes] = $get('/api/market/download.php' . substr($url, strlen(\MarketDownloadUrl::ENDPOINT)));
                        self::assertTrue($length(strlen($bytes)));
                        self::assertSame(strlen($bytes), $write($bytes));
                        return ['status' => 200];
                    });
                self::assertTrue($download['ok']);
                self::assertSame($item['hash'], 'sha256:' . hash_file('sha256', $path));
                self::assertTrue(ThemeMarket::verifyPackageSignature($slug, $item['version'], $item['hash'], $item['sig'], $key));
                [, $headers] = $get('/api/market/download.php' . substr($item['download_url'], strlen(\MarketDownloadUrl::ENDPOINT)));
                self::assertContains('X-Market-Quota-Replay: 1', $headers);
                $directory = $this->tempRoot . '/' . $kind . 's';
                if ($kind === 'theme') {
                    $installer = new ThemeInstaller($directory, $this->tempRoot . '/storage');
                    $installed = $installer->install($path, $slug, $item['version'], $item['source']);
                    self::assertTrue($installed['ok'], json_encode($installed));
                    $replacement = $installer->install($path, $slug, $item['version'], $item['source']);
                    self::assertTrue($replacement['ok'], json_encode($replacement));
                    self::assertFileExists($replacement['backup'] . '/theme.json');
                } else {
                    $installer = new \PluginInstaller($directory, $this->tempRoot . '/storage');
                    $installed = $installer->install($path, static function (string $actual) use ($slug): void {
                        self::assertSame($slug, $actual);
                    }, $slug, $item['version'], $item['source']);
                    self::assertTrue($installed['ok'], json_encode($installed));
                    $before = file_get_contents($directory . '/' . $slug . '/plugin.json');
                    $failed = $installer->install($path, static function (): void {
                        throw new RuntimeException('simulated_registration_failure');
                    }, $slug, $item['version'], $item['source']);
                    self::assertFalse($failed['ok']);
                    self::assertSame($before, file_get_contents($directory . '/' . $slug . '/plugin.json'));
                }
                $origin = json_decode((string) file_get_contents($directory . '/' . $slug . '/.yikai-market-origin.json'), true, 32, JSON_THROW_ON_ERROR);
                self::assertSame($item['source'], $origin['origin']);
                self::assertSame($kind, $origin['kind']);
            }
        }
    }

    private function publicKey(): string
    {
        return (string) file_get_contents(self::FIXTURES . 'market-test-public.pem');
    }

    public function testLiveV2NewVersionUpgrade(): void
    {
        $fixture = (string) getenv('YIKAI_G5_UPGRADE_FIXTURES');
        if ($fixture === '') self::markTestSkipped('Opt-in actual new-version HTTP integration');
        $fixture = str_replace('\\', '/', (string) realpath($fixture));
        self::assertStringStartsWith(str_replace('\\', '/', sys_get_temp_dir()) . '/g5-r2-market-http-', $fixture);
        $base = (string) getenv('YIKAI_G5_V2_BASE');
        self::assertMatchesRegularExpression('#^http://127\.0\.0\.1:\d+$#D', $base);
        $key = (string) file_get_contents((string) getenv('YIKAI_G5_TEST_PUBLIC_KEY'));
        $get = static function (string $url) use ($base): array {
            self::assertStringStartsWith($base . '/api/', $url);
            $body = file_get_contents($url, false, stream_context_create(['http' => [
                'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0,
            ]]));
            preg_match('/^HTTP\/\S+ (\d+)/', $http_response_header[0] ?? '', $match);
            return [(int) ($match[1] ?? 0), (string) $body, $http_response_header ?? []];
        };
        $cases = json_decode((string) file_get_contents($fixture . '/cases.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertCount(4, $cases);
        foreach ($cases as $case) {
            $old = $case['old'];
            $slug = $old['slug'];
            self::assertMatchesRegularExpression('/^[a-z0-9-]+$/D', $slug);
            $kind = $case['kind'];
            self::assertContains($kind, ['theme', 'plugin']);
            $oldZip = $fixture . '/' . $slug . '-old.zip';
            self::assertSame($old['hash'], 'sha256:' . hash_file('sha256', $oldZip));
            self::assertTrue(ThemeMarket::verifyPackageSignature($slug, $old['version'], $old['hash'], $old['sig'], $key));
            $directory = $this->tempRoot . '/' . $kind . 's';
            $storage = $this->tempRoot . '/storage';
            $register = static function (): void {};
            $installer = $kind === 'theme' ? new ThemeInstaller($directory, $storage) : new \PluginInstaller($directory, $storage);
            $install = static function ($engine, string $zip, string $version, string $origin) use ($kind, $slug, $register): array {
                return $kind === 'theme' ? $engine->install($zip, $slug, $version, $origin)
                    : $engine->install($zip, $register, $slug, $version, $origin);
            };
            self::assertTrue($install($installer, $oldZip, $old['version'], $old['source'])['ok']);
            $manifestPath = $directory . '/' . $slug . '/' . ($kind === 'theme' ? 'theme.json' : 'plugin.json');
            $originPath = $directory . '/' . $slug . '/.yikai-market-origin.json';
            $beforeManifest = file_get_contents($manifestPath);
            $beforeOrigin = file_get_contents($originPath);

            [$status, $body] = $get($base . '/api/' . ($kind === 'theme' ? 'themes' : 'plugins') . '/list.php?' . \MarketCatalogRequest::query($slug));
            self::assertSame(200, $status);
            $catalog = \MarketCatalogRequest::decode($body, $kind === 'theme' ? 'themes' : 'plugins');
            self::assertNotNull($catalog);
            $items = array_column(\MarketCatalogItems::select($catalog['data'][$kind === 'theme' ? 'themes' : 'plugins']), null, 'slug');
            $current = $items[$slug];
            self::assertSame($case['next'], $current['version']);
            self::assertSame($old['source'], $current['source']);
            self::assertTrue(version_compare($current['version'], $old['version'], '>'));
            \MarketInstallOrigin::assertAllowed($directory, $kind, $slug, $current['source']);
            try {
                \MarketInstallOrigin::assertAllowed($directory, $kind, $slug, $current['source'] === 'official' ? 'community' : 'official');
                self::fail('Source change must fail before downloading');
            } catch (RuntimeException $error) {
                self::assertSame('origin_changed', $error->getMessage());
            }
            [$status] = $get($old['download_url']);
            self::assertSame(410, $status, 'Previous-version token must no longer serve a replaced catalog record');
            [$status, $bytes] = $get($current['download_url']);
            self::assertSame(200, $status);
            self::assertSame($current['hash'], 'sha256:' . hash('sha256', $bytes));
            self::assertTrue(ThemeMarket::verifyPackageSignature($slug, $current['version'], $current['hash'], $current['sig'], $key));
            $newZip = $this->tempRoot . '/' . $slug . '-new.zip';
            file_put_contents($newZip, $bytes);

            if ($kind === 'theme') {
                $failReplacement = static function (string $from, string $to): bool {
                    // Fail only staged->active rename; allow old->backup and backup->active recovery.
                    if (str_contains(str_replace('\\', '/', $from), '/theme-staging/')) return false;
                    return rename($from, $to);
                };
                $failed = $install(new ThemeInstaller($directory, $storage, $failReplacement), $newZip, $current['version'], $current['source']);
            } else {
                $failed = $installer->install($newZip, static function (): void {
                    throw new RuntimeException('simulated_registration_failure');
                }, $slug, $current['version'], $current['source']);
            }
            self::assertFalse($failed['ok'], json_encode($failed));
            self::assertSame($beforeManifest, file_get_contents($manifestPath));
            self::assertSame($beforeOrigin, file_get_contents($originPath));
            self::assertFileDoesNotExist($directory . '/' . $slug . '/market-upgrade-proof.txt');
            [$status, $retryBytes, $headers] = $get($current['download_url']);
            self::assertSame(200, $status);
            self::assertSame($bytes, $retryBytes);
            self::assertContains('X-Market-Quota-Replay: 1', $headers);
            $updated = $install($installer, $newZip, $current['version'], $current['source']);
            self::assertTrue($updated['ok'], json_encode($updated));
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 64, JSON_THROW_ON_ERROR);
            self::assertSame($case['next'], $manifest['version']);
            self::assertSame($case['next'], file_get_contents($directory . '/' . $slug . '/market-upgrade-proof.txt'));
            self::assertSame($beforeManifest, file_get_contents($updated['backup'] . '/' . basename($manifestPath)));
            self::assertSame($beforeOrigin, file_get_contents($updated['backup'] . '/.yikai-market-origin.json'));
            $origin = json_decode((string) file_get_contents($originPath), true, 32, JSON_THROW_ON_ERROR);
            self::assertSame($old['source'], $origin['origin']);
            self::assertSame($case['next'], $origin['version']);
        }
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $child = $path . '/' . $name;
            if (is_dir($child) && !is_link($child)) $this->removeTree($child);
            else unlink($child);
        }
        rmdir($path);
    }
}
