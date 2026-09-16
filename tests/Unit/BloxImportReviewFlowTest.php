<?php
/** 导入检查-确认两段式：服务端待确认记录的属主、时效、设计版本与幂等。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxImportReview;
use BloxRemoteTemplateInstaller;
use BloxRemoteTemplateProvider;
use BloxTemplateImporter;
use RuntimeException;
use Yikai\Tests\TestCase;
use ZipArchive;

require_once ROOT_PATH . '/includes/License.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxImportReviewFlowTest extends TestCase
{
    private array $previousOverrides = [];
    private string $previousHost = '';
    private mixed $previousTestConfig = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousOverrides = is_array($GLOBALS['yikai_config_runtime_overrides'] ?? null)
            ? $GLOBALS['yikai_config_runtime_overrides']
            : [];
        $this->previousHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $this->previousTestConfig = $GLOBALS['_test_config'] ?? null;
        $GLOBALS['yikai_config_runtime_overrides']['license_key'] = 'service-key-123';
        $_SERVER['HTTP_HOST'] = 'licensed.example.test';
    }

    protected function tearDown(): void
    {
        $GLOBALS['yikai_config_runtime_overrides'] = $this->previousOverrides;
        $_SERVER['HTTP_HOST'] = $this->previousHost;
        if ($this->previousTestConfig === null) {
            unset($GLOBALS['_test_config']);
        } else {
            $GLOBALS['_test_config'] = $this->previousTestConfig;
        }
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
                catalog_origin TEXT NOT NULL DEFAULT '',
                installed_version TEXT NOT NULL DEFAULT '',
                backup_version TEXT NOT NULL DEFAULT '',
                backup_draft TEXT,
                backup_requirements TEXT,
                backup_metadata TEXT,
                backup_created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_import_reviews (
                id TEXT PRIMARY KEY,
                catalog_origin TEXT NOT NULL DEFAULT '',
                admin_id INTEGER NOT NULL DEFAULT 0,
                operation TEXT NOT NULL,
                source_key TEXT NOT NULL,
                source_type TEXT NOT NULL DEFAULT '',
                template_type TEXT NOT NULL DEFAULT '',
                package_sha256 TEXT NOT NULL,
                package_json TEXT NOT NULL,
                package_version TEXT NOT NULL DEFAULT '',
                target_id INTEGER NOT NULL DEFAULT 0,
                target_revision TEXT NOT NULL DEFAULT '',
                result_ref TEXT NOT NULL DEFAULT '',
                design_revision INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                expires_at INTEGER NOT NULL DEFAULT 0,
                consumed_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testPrepareInstallWritesNoTemplateThenConfirmCreatesDraftWithMapping(): void
    {
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $prepared = $installer->prepareInstall('pricing-3col', 9);

        $this->assertSame('install', $prepared['operation']);
        $this->assertSame('1.0.0', $prepared['version']);
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $review = BloxImportReview::find($prepared['review_id']);
        $this->assertNotNull($review);
        $this->assertSame(0, (int) $review['consumed_at']);
        $this->assertSame('official', $review['catalog_origin']);
        $this->assertSame(['remote'], $prepared['design_diagnostics']['missing_tokens']);

        $result = $installer->confirmInstall($prepared['review_id'], ['tokens' => ['remote' => 'primary']], 9);

        $this->assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $row = db()->fetchOne('SELECT * FROM blox_templates WHERE id = ?', [$result['id']]);
        $this->assertSame('remote', $row['source']);
        $this->assertStringContainsString('var(--yk-color-primary)', (string) $row['draft_data']);
        $state = db()->fetchOne('SELECT * FROM blox_remote_template_states WHERE template_id = ?', [$result['id']]);
        $this->assertSame('1.0.0', $state['installed_version']);
        $this->assertSame('official', $state['catalog_origin']);
        $consumed = BloxImportReview::find($prepared['review_id']);
        $this->assertGreaterThan(0, (int) $consumed['consumed_at']);
        $this->assertSame('template:' . $result['id'], $consumed['result_ref']);
    }

    public function testConfirmReplayReturnsFirstResultWithoutDuplicate(): void
    {
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $reviewId = $installer->prepareInstall('pricing-3col', 9)['review_id'];
        $first = $installer->confirmInstall($reviewId, [], 9);
        $second = $installer->confirmInstall($reviewId, [], 9);

        $this->assertSame($first['id'], $second['id']);
        $this->assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
    }

    public function testReviewIsBoundToIssuingAdminAndOperation(): void
    {
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $reviewId = $installer->prepareInstall('pricing-3col', 9)['review_id'];

        try {
            $installer->confirmInstall($reviewId, [], 8);
            $this->fail('Another administrator must not consume a foreign review.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_import_review_owner', $e->getMessage());
        }
        try {
            $installer->confirmCopy($reviewId, [], 9);
            $this->fail('A review must only be consumed by its own operation.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_import_review_invalid', $e->getMessage());
        }
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
    }

    public function testDesignChangeBetweenCheckAndConfirmRequiresRecheck(): void
    {
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $reviewId = $installer->prepareInstall('pricing-3col', 9)['review_id'];

        $GLOBALS['_test_config']['blox_design_system'] = json_encode(
            ['revision' => 7, 'tokens' => [], 'styles' => []],
            JSON_THROW_ON_ERROR
        );

        try {
            $installer->confirmInstall($reviewId, [], 9);
            $this->fail('A changed design revision must force a fresh check.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_import_design_changed', $e->getMessage());
        }
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
        $review = BloxImportReview::find($reviewId);
        $this->assertSame(0, (int) $review['consumed_at'], '未领取的评审在拒绝后应保持待确认');
    }

    public function testTamperedStoredPackageIsRejected(): void
    {
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $reviewId = $installer->prepareInstall('pricing-3col', 9)['review_id'];

        $tampered = json_decode((string) BloxImportReview::find($reviewId)['package_json'], true);
        $tampered['name'] = 'Swapped name';
        db()->update(
            'blox_import_reviews',
            ['package_json' => json_encode($tampered, JSON_THROW_ON_ERROR)],
            'id = ?',
            [$reviewId]
        );

        try {
            $installer->confirmInstall($reviewId, [], 9);
            $this->fail('A modified stored package must not be installed.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_import_review_invalid', $e->getMessage());
        }
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
    }

    public function testUpdateConfirmKeepsLockedSemanticsAndStaleTargetIsRejected(): void
    {
        $v1 = $this->provider($this->package($this->templateJson('Original draft')));
        $installed = (new BloxRemoteTemplateInstaller($v1))->install('pricing-3col', 9);
        bloxTemplateModel()->publishDraft($installed['id']);

        $v2Installer = new BloxRemoteTemplateInstaller($this->provider(
            $this->package($this->templateJson('Updated remote draft'))
        ));
        $baseRevision = BloxRemoteTemplateInstaller::revision(bloxTemplateModel()->findForExport($installed['id']));
        $reviewId = $v2Installer->prepareUpdate($installed['id'], $baseRevision, 9)['review_id'];

        // 检查窗口内目标被他人保存：确认必须拒绝且不产生备份。
        bloxTemplateModel()->saveMetadata($installed['id'], ['purpose' => 'cta']);
        try {
            $v2Installer->confirmUpdate($reviewId, [], 9);
            $this->fail('A target edited after the check must not be overwritten.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_save_conflict', $e->getMessage());
        }
        $this->assertNull(bloxRemoteTemplateStateModel()->forTemplate($installed['id'])['backup_draft']);

        $freshRevision = BloxRemoteTemplateInstaller::revision(bloxTemplateModel()->findForExport($installed['id']));
        $freshReview = $v2Installer->prepareUpdate($installed['id'], $freshRevision, 9)['review_id'];
        $updated = $v2Installer->confirmUpdate($freshReview, [], 9);

        $this->assertTrue($updated['updated']);
        $row = db()->fetchOne('SELECT * FROM blox_templates WHERE id = ?', [$installed['id']]);
        $this->assertStringContainsString('Updated remote draft', (string) $row['draft_data']);
        $this->assertStringContainsString('Original draft', (string) $row['published_data']);
        $state = db()->fetchOne('SELECT * FROM blox_remote_template_states WHERE template_id = ?', [$installed['id']]);
        $this->assertStringContainsString('Original draft', (string) $state['backup_draft']);
        $this->assertSame($updated, $v2Installer->confirmUpdate($freshReview, [], 9));
        $this->assertSame($state, bloxRemoteTemplateStateModel()->forTemplate($installed['id']));
    }

    public function testConfirmRejectsOriginChangeAfterPrepareWithoutConsumingReview(): void
    {
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $id = $installer->install('pricing-3col', 9)['id'];
        bloxTemplateModel()->publishDraft($id);
        $before = bloxTemplateModel()->findForExport($id);
        $reviewId = $installer->prepareUpdate($id, BloxRemoteTemplateInstaller::revision($before), 9)['review_id'];
        db()->update('blox_remote_template_states', ['catalog_origin' => 'community'], 'template_id = ?', [$id]);
        try {
            $installer->confirmUpdate($reviewId, [], 9);
            $this->fail('Origin must be checked again at confirmation.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_tpl_remote_origin_changed', $e->getMessage());
        }
        $this->assertSame($before, bloxTemplateModel()->findForExport($id));
        $this->assertNull(bloxRemoteTemplateStateModel()->forTemplate($id)['backup_draft']);
        $this->assertSame(0, (int) BloxImportReview::find($reviewId)['consumed_at']);
    }

    public function testLegacyReviewWithoutOriginCannotCreateManagedInstall(): void
    {
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $reviewId = $installer->prepareInstall('pricing-3col', 9)['review_id'];
        db()->update('blox_import_reviews', ['catalog_origin' => ''], 'id = ?', [$reviewId]);
        try {
            $installer->confirmInstall($reviewId, [], 9);
            $this->fail('Legacy reviews need a fresh prepare.');
        } catch (RuntimeException $e) {
            $this->assertSame('blox_import_review_invalid', $e->getMessage());
        }
        $this->assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates'));
    }

    public function testCopyConfirmCreatesEditableDraftWithoutManagedState(): void
    {
        $installer = new BloxRemoteTemplateInstaller($this->provider());
        $reviewId = $installer->prepareCopy('pricing-3col', 9)['review_id'];
        $result = $installer->confirmCopy($reviewId, [], 9);

        $row = bloxTemplateModel()->findForExport($result['id']);
        $this->assertSame('import', $row['source']);
        $this->assertSame('pricing-3col', $row['source_ref']);
        $this->assertNull(bloxRemoteTemplateStateModel()->forTemplate($result['id']));
    }

    private function provider(?string $packageOverride = null): BloxRemoteTemplateProvider
    {
        $package = $packageOverride ?? $this->package($this->templateJson());
        $item = $this->catalogItem(['hash' => 'sha256:' . hash('sha256', $package)]);
        $catalog = $this->catalogResponse($item);
        return new BloxRemoteTemplateProvider(
            static fn (string $url): string => str_contains($url, '/api/templates/download.php') ? $package : $catalog,
            static fn (): bool => true
        );
    }

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
                'settings' => ['bg_color' => 'var(--yk-color-remote)'],
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
        $path = tempnam(sys_get_temp_dir(), 'blox-review-test-');
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
