<?php
/** 官方模板从服务期授权到安全落库的完整链路。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxRemoteTemplateInstaller;
use BloxRemoteTemplateProvider;
use BloxTemplateImporter;
use RuntimeException;
use Yikai\Tests\TestCase;
use ZipArchive;

require_once ROOT_PATH . '/includes/License.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxRemoteTemplateInstallerTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $previousOverrides = [];
    private string $previousHost = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousOverrides = is_array($GLOBALS['yikai_config_runtime_overrides'] ?? null)
            ? $GLOBALS['yikai_config_runtime_overrides']
            : [];
        $this->previousHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $GLOBALS['yikai_config_runtime_overrides']['license_key'] = 'service-key-123';
        $_SERVER['HTTP_HOST'] = 'licensed.example.test';
    }

    protected function tearDown(): void
    {
        $GLOBALS['yikai_config_runtime_overrides'] = $this->previousOverrides;
        $_SERVER['HTTP_HOST'] = $this->previousHost;
        parent::tearDown();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'user',
                source_ref TEXT NOT NULL DEFAULT '',
                schema_version INTEGER NOT NULL DEFAULT 1,
                draft_data TEXT NOT NULL,
                published_data TEXT,
                requirements TEXT,
                metadata TEXT,
                conditions TEXT,
                thumbnail TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testAuthorizedJourneyBrowsesVerifiesImportsAndReinstallsIdempotently(): void
    {
        $package = $this->package($this->templateJson());
        $hash = 'sha256:' . hash('sha256', $package);
        $catalog = $this->catalogResponse($this->catalogItem(['hash' => $hash]));
        $catalogUrls = [];
        $canonical = [];
        $provider = new BloxRemoteTemplateProvider(
            static function (string $url) use ($catalog, $package, &$catalogUrls): string {
                if (str_contains($url, '/packages/templates/')) {
                    return $package;
                }
                $catalogUrls[] = $url;
                return $catalog;
            },
            static function (string $value, string $signature) use (&$canonical): bool {
                $canonical[] = $value;
                return $signature === 'valid-signature';
            },
            'en'
        );

        $items = $provider->installable();
        $this->assertCount(1, $items);
        $this->assertFalse($items[0]['locked']);

        $installer = new BloxRemoteTemplateInstaller($provider);
        $first = $installer->install('pricing-3col', 9);
        $second = $installer->install('pricing-3col', 9);

        $this->assertFalse($first['updated']);
        $this->assertTrue($second['updated']);
        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $row = db()->fetchOne('SELECT * FROM blox_templates WHERE id = ?', [$first['id']]);
        $this->assertSame('remote', $row['source']);
        $this->assertSame('pricing-3col', $row['source_ref']);
        $this->assertStringContainsString('Pricing plans', (string) $row['draft_data']);
        $this->assertSame(9, (int) $row['admin_id']);
        $this->assertNotEmpty($canonical);
        $this->assertSame('pricing-3col|1.0.0|' . $hash, $canonical[0]);
        $this->assertNotEmpty($catalogUrls);
        $this->assertStringContainsString('key=service-key-123', $catalogUrls[0]);
        $this->assertStringContainsString('domain=licensed.example.test', $catalogUrls[0]);
    }

    public function testExpiredServiceLocksBrowseAndPreventsPackageDownloadOrPersistence(): void
    {
        $packageRequests = 0;
        $catalog = $this->catalogResponse($this->catalogItem([
            'tier' => 'pro',
            'paid' => true,
            'entitled' => false,
            'locked_reason' => 'license_expired',
        ]));
        $provider = new BloxRemoteTemplateProvider(
            static function (string $url) use ($catalog, &$packageRequests): string {
                if (str_contains($url, '/packages/templates/')) {
                    $packageRequests++;
                    return 'must-not-download';
                }
                return $catalog;
            },
            static fn (): bool => true
        );

        $items = $provider->installable();
        $this->assertTrue($items[0]['locked']);
        $this->assertSame('license_expired', $items[0]['locked_reason']);

        try {
            (new BloxRemoteTemplateInstaller($provider))->install('pricing-3col', 9);
            $this->fail('Expired service must not install a remote template.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_template_locked_expired', $e->getMessage());
        }
        $this->assertSame(0, $packageRequests);
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
    }

    public function testBadHashAndSignatureNeverPersistRemoteDocuments(): void
    {
        $package = $this->package($this->templateJson());
        $badHashProvider = $this->provider($package, $this->catalogItem([
            'hash' => 'sha256:' . str_repeat('0', 64),
        ]), true);
        try {
            (new BloxRemoteTemplateInstaller($badHashProvider))->install('pricing-3col');
            $this->fail('A bad package hash must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_template_remote_hash_failed', $e->getMessage());
        }
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));

        $signedItem = $this->catalogItem(['hash' => 'sha256:' . hash('sha256', $package)]);
        try {
            (new BloxRemoteTemplateInstaller($this->provider($package, $signedItem, false)))
                ->install('pricing-3col');
            $this->fail('A bad package signature must be rejected.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_template_remote_signature_failed', $e->getMessage());
        }
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
    }

    public function testSupportAndPaidDownloadsShareTheServicePeriodDecision(): void
    {
        $license = (string) file_get_contents(ROOT_PATH . '/includes/License.php');
        $admin = (string) file_get_contents(ROOT_PATH . '/admin/license.php');

        $this->assertStringContainsString('function license_service_active(?array $state = null): bool', $license);
        $this->assertStringContainsString('license_service_active($st) && !empty($st[\'modules\'])', $license);
        $this->assertStringContainsString('$serviceActive = license_service_active($st);', $admin);
        $this->assertStringContainsString('$serviceActive ? __(\'lic_support_active\')', $admin);
    }

    /** @param array<string,mixed> $item */
    private function provider(string $package, array $item, bool $signatureValid): BloxRemoteTemplateProvider
    {
        $catalog = $this->catalogResponse($item);
        return new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/packages/templates/') ? $package : $catalog,
            static fn (): bool => $signatureValid
        );
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function catalogItem(array $overrides = []): array
    {
        return array_replace([
            'slug' => 'pricing-3col',
            'type' => 'section',
            'category' => 'marketing',
            'tier' => 'pro',
            'name' => 'Three-column pricing',
            'version' => '1.0.0',
            'hash' => 'sha256:' . str_repeat('0', 64),
            'sig' => 'valid-signature',
            'paid' => true,
            'entitled' => true,
            'download_url' => 'https://update.yikaicms.com/packages/templates/pricing-3col-v1.0.0.zip',
            'locked_reason' => '',
        ], $overrides);
    }

    /** @param array<string,mixed> $item */
    private function catalogResponse(array $item): string
    {
        return json_encode([
            'code' => 0,
            'data' => ['updated_at' => '2026-08-29', 'templates' => [$item]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function templateJson(): string
    {
        return json_encode([
            'format' => BloxTemplateImporter::FORMAT,
            'version' => BloxTemplateImporter::VERSION,
            'type' => 'section',
            'name' => 'Three-column pricing',
            'requires' => ['elements' => ['heading'], 'plugins' => []],
            'meta' => ['source_ref' => 'pricing-3col'],
            'document' => [[
                'type' => 'section',
                'settings' => [],
                'columns' => [[
                    'span' => 12,
                    'elements' => [[
                        'type' => 'heading',
                        'data' => ['text' => 'Pricing plans'],
                    ]],
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function package(string $json): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blox-installer-test-');
        $this->assertNotFalse($path);
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $this->assertTrue($zip->addFromString('template.json', $json));
        $this->assertTrue($zip->close());
        $package = file_get_contents($path);
        @unlink($path);
        $this->assertIsString($package);
        return $package;
    }
}
