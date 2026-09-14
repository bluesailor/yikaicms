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
            "CREATE TABLE blox_remote_template_states (
                template_id INTEGER PRIMARY KEY,
                installed_version TEXT NOT NULL DEFAULT '',
                backup_version TEXT NOT NULL DEFAULT '',
                backup_draft TEXT,
                backup_requirements TEXT,
                backup_metadata TEXT,
                backup_created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testImportedCopyHasIndependentIdentityAndNoManagedUpdateState(): void
    {
        $package = $this->package($this->templateJson());
        $hash = 'sha256:' . hash('sha256', $package);
        $catalog = $this->catalogResponse($this->catalogItem(['hash' => $hash]));
        $catalogUrls = [];
        $canonical = [];
        $provider = new BloxRemoteTemplateProvider(
            static function (string $url) use ($catalog, $package, &$catalogUrls): string {
                if (str_contains($url, '/api/templates/download.php')) {
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
        $second = $installer->importCopy('pricing-3col', 9);

        $this->assertFalse($first['updated']);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame(2, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $copy = bloxTemplateModel()->findForExport($second['id']);
        $this->assertSame('import', $copy['source']);
        $this->assertSame('pricing-3col', $copy['source_ref']);
        $this->assertSame(0, (int) $copy['status']);
        $this->assertNull($copy['published_data']);
        $this->assertNull(bloxRemoteTemplateStateModel()->forTemplate($second['id']));
        $row = db()->fetchOne('SELECT * FROM blox_templates WHERE id = ?', [$first['id']]);
        $this->assertSame('remote', $row['source']);
        $this->assertSame('pricing-3col', $row['source_ref']);
        $this->assertStringContainsString('Pricing plans', (string) $row['draft_data']);
        $this->assertSame(9, (int) $row['admin_id']);
        $state = db()->fetchOne('SELECT * FROM blox_remote_template_states WHERE template_id = ?', [$first['id']]);
        $this->assertSame('1.0.0', $state['installed_version']);
        $this->assertNull($state['backup_draft']);
        $this->assertNotEmpty($canonical);
        $this->assertSame('pricing-3col|1.0.0|' . $hash, $canonical[0]);
        $this->assertNotEmpty($catalogUrls);
        $this->assertStringContainsString('key=service-key-123', $catalogUrls[0]);
        $this->assertStringContainsString('domain=licensed.example.test', $catalogUrls[0]);
    }

    public function testUpdateProtectsDraftAndRollbackNeverChangesPublishedDocument(): void
    {
        $v1Package = $this->package($this->templateJson('Original draft'));
        $v1Item = $this->catalogItem([
            'version' => '1.0.0',
            'hash' => 'sha256:' . hash('sha256', $v1Package),
            'download_url' => 'https://update.yikaicms.com/api/templates/download.php?protocol_version=2&slug=pricing-3col&version=1.0.0',
        ]);
        $v1Installer = new BloxRemoteTemplateInstaller($this->provider($v1Package, $v1Item, true));
        $installed = $v1Installer->install('pricing-3col', 9);
        bloxTemplateModel()->publishDraft($installed['id']);

        $v2Package = $this->package($this->templateJson('Updated remote draft'));
        $v2Item = $this->catalogItem([
            'version' => '1.1.0',
            'hash' => 'sha256:' . hash('sha256', $v2Package),
            'download_url' => 'https://update.yikaicms.com/api/templates/download.php?protocol_version=2&slug=pricing-3col&version=1.1.0',
        ]);
        $v2Installer = new BloxRemoteTemplateInstaller($this->provider($v2Package, $v2Item, true));
        $updated = $v2Installer->update(
            $installed['id'], BloxRemoteTemplateInstaller::revision(bloxTemplateModel()->findForExport($installed['id'])), '1.1.0'
        );

        $this->assertTrue($updated['updated']);
        $this->assertTrue($updated['backup_created']);
        $row = db()->fetchOne('SELECT * FROM blox_templates WHERE id = ?', [$installed['id']]);
        $this->assertStringContainsString('Updated remote draft', (string) $row['draft_data']);
        $this->assertStringContainsString('Original draft', (string) $row['published_data']);
        $this->assertStringNotContainsString('Updated remote draft', (string) $row['published_data']);
        $state = db()->fetchOne('SELECT * FROM blox_remote_template_states WHERE template_id = ?', [$installed['id']]);
        $this->assertSame('1.1.0', $state['installed_version']);
        $this->assertSame('1.0.0', $state['backup_version']);
        $this->assertStringContainsString('Original draft', (string) $state['backup_draft']);

        $rolledBack = $v2Installer->rollback($installed['id']);
        $this->assertSame('1.0.0', $rolledBack['version']);
        $row = db()->fetchOne('SELECT * FROM blox_templates WHERE id = ?', [$installed['id']]);
        $this->assertStringContainsString('Original draft', (string) $row['draft_data']);
        $this->assertStringContainsString('Original draft', (string) $row['published_data']);
        $state = db()->fetchOne('SELECT * FROM blox_remote_template_states WHERE template_id = ?', [$installed['id']]);
        $this->assertSame('1.0.0', $state['installed_version']);
        $this->assertNull($state['backup_draft']);
        $this->assertSame(0, (int) $state['backup_created_at']);
    }

    public function testExpiredServiceLocksBrowseAndPreventsPackageDownloadOrPersistence(): void
    {
        $packageRequests = 0;
        $catalog = $this->catalogResponse($this->catalogItem([
            'tier' => 'pro',
            'access' => 'licensed',
            'module' => 'blox',
            'paid' => true,
            'entitled' => false,
            'locked_reason' => 'license_expired',
        ]));
        $provider = new BloxRemoteTemplateProvider(
            static function (string $url) use ($catalog, &$packageRequests): string {
                if (str_contains($url, '/api/templates/download.php')) {
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

    public function testInstallCannotReplaceAnExistingDraft(): void
    {
        $package = $this->package($this->templateJson());
        $installer = new BloxRemoteTemplateInstaller($this->provider($package, $this->catalogItem([
            'hash' => 'sha256:' . hash('sha256', $package),
        ]), true));
        $first = $installer->install('pricing-3col');
        $before = bloxTemplateModel()->findForExport($first['id']);
        $this->expectExceptionMessage('blox_tpl_remote_already_imported');
        try {
            $installer->install('pricing-3col');
        } finally {
            $this->assertSame($before, bloxTemplateModel()->findForExport($first['id']));
            $this->assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        }
    }

    public function testUpdateRejectsMissingStaleRevisionAndChangedRemoteVersion(): void
    {
        $package = $this->package($this->templateJson());
        $installer = new BloxRemoteTemplateInstaller($this->provider($package, $this->catalogItem([
            'hash' => 'sha256:' . hash('sha256', $package),
        ]), true));
        $first = $installer->install('pricing-3col');
        $row = bloxTemplateModel()->findForExport($first['id']);
        $revision = BloxRemoteTemplateInstaller::revision($row);
        foreach ([['', '1.0.0', 'blox_save_conflict'],
            [str_repeat('0', 64), '1.0.0', 'blox_save_conflict'],
            [$revision, '2.0.0', 'blox_tpl_remote_version_conflict']] as [$base, $version, $message]) {
            try {
                $installer->update($first['id'], $base, $version);
                $this->fail('Conflicting updates must be rejected');
            } catch (RuntimeException $e) {
                $this->assertSame($message, $e->getMessage());
            }
            $this->assertSame($row, bloxTemplateModel()->findForExport($first['id']));
            $this->assertNull(bloxRemoteTemplateStateModel()->forTemplate($first['id'])['backup_draft']);
        }
    }

    public function testEditDuringDownloadCannotBeOverwritten(): void
    {
        $package = $this->package($this->templateJson());
        $item = $this->catalogItem(['hash' => 'sha256:' . hash('sha256', $package)]);
        $first = (new BloxRemoteTemplateInstaller($this->provider($package, $item, true)))->install('pricing-3col');
        $revision = BloxRemoteTemplateInstaller::revision(bloxTemplateModel()->findForExport($first['id']));
        $catalog = $this->catalogResponse($item);
        $provider = new BloxRemoteTemplateProvider(
            static function (string $url) use ($package, $catalog, $first): string {
                if (str_contains($url, '/api/templates/download.php')) {
                    bloxTemplateModel()->saveMetadata($first['id'], ['purpose' => 'cta']);
                    return $package;
                }
                return $catalog;
            },
            static fn (): bool => true
        );
        $this->expectExceptionMessage('blox_save_conflict');
        try {
            (new BloxRemoteTemplateInstaller($provider))->update($first['id'], $revision, '1.0.0');
        } finally {
            $row = bloxTemplateModel()->findForExport($first['id']);
            $this->assertSame('cta', json_decode($row['metadata'], true)['purpose']);
            $this->assertNull(bloxRemoteTemplateStateModel()->forTemplate($first['id'])['backup_draft']);
        }
    }

    public function testCopyCannotBeUsedAsManagedUpdateTarget(): void
    {
        $package = $this->package($this->templateJson());
        $installer = new BloxRemoteTemplateInstaller($this->provider($package, $this->catalogItem([
            'hash' => 'sha256:' . hash('sha256', $package),
        ]), true));
        $copy = $installer->importCopy('pricing-3col');
        $row = bloxTemplateModel()->findForExport($copy['id']);
        $this->expectExceptionMessage('blox_tpl_not_found');
        $installer->update($copy['id'], BloxRemoteTemplateInstaller::revision($row), '1.0.0');
    }

    /** @param array<string,mixed> $item */
    private function provider(string $package, array $item, bool $signatureValid): BloxRemoteTemplateProvider
    {
        $catalog = $this->catalogResponse($item);
        return new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
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
            'access' => 'licensed',
            'module' => 'blox',
            'name' => 'Three-column pricing',
            'version' => '1.0.0',
            'hash' => 'sha256:' . str_repeat('0', 64),
            'sig' => 'valid-signature',
            'paid' => true,
            'entitled' => true,
            'download_url' => 'https://update.yikaicms.com/api/templates/download.php?protocol_version=2&slug=pricing-3col&version=1.0.0',
            'locked_reason' => '',
        ], $overrides);
    }

    /** @param array<string,mixed> $item */
    private function catalogResponse(array $item): string
    {
        return json_encode([
            'code' => 0,
            'data' => ['protocol_version' => 2, 'updated_at' => '2026-08-29', 'templates' => [$item]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function templateJson(string $heading = 'Pricing plans'): string
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
                        'data' => ['text' => $heading],
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
