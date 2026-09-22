<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SiteTemplateOfflineValidator;

require_once ROOT_PATH . '/includes/SiteTemplateOfflineValidator.php';
require_once ROOT_PATH . '/tools/prepare-site-template-market.php';

final class SiteTemplateOfflineValidatorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yk-site-offline-' . getmypid() . '-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($this->root, 0775, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testInspectVerifiesAllDigestsRequiredThemeFilesAndMediaReferencesWithoutDatabase(): void
    {
        $path = $this->root . '/valid.zip';
        $this->writePackage($path);

        $result = SiteTemplateOfflineValidator::inspect($path);

        self::assertSame('offline-demo', $result['manifest']['theme']);
        self::assertSame('1.2.3', $result['theme_meta']['version']);
        self::assertSame(hash_file('sha256', $path), $result['sha256']);
        self::assertSame(filesize($path), $result['size']);
    }

    public function testInspectRejectsTamperedDigestAndMissingReferencedMedia(): void
    {
        $tampered = $this->root . '/tampered.zip';
        $this->writePackage($tampered, ['digest' => str_repeat('0', 64)]);
        $message = '';
        try {
            SiteTemplateOfflineValidator::inspect($tampered);
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        }
        self::assertStringContainsString('Digest mismatch', $message);

        $missing = $this->root . '/missing.zip';
        $this->writePackage($missing, ['omit_media' => true]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('st_missing_media');
        SiteTemplateOfflineValidator::inspect($missing);
    }

    public function testInspectRejectsExecutableDisguisedAsMedia(): void
    {
        $path = $this->root . '/unsafe.zip';
        $this->writePackage($path, ['extra_name' => 'media/payload.php.jpg']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsafe ZIP file type');
        SiteTemplateOfflineValidator::inspect($path);
    }

    public function testInspectIncludesPluginPayloadsInMediaReferenceValidation(): void
    {
        $path = $this->root . '/plugin-missing-media.zip';
        $this->writePackage($path, ['plugin_missing_media' => true]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('st_missing_media');
        SiteTemplateOfflineValidator::inspect($path);
    }

    public function testInspectRejectsSchemaThatDiffersFromPortableContract(): void
    {
        $path = $this->root . '/reduced-schema.zip';
        $this->writePackage($path, ['reduced_schema' => true]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid portable schema contract');
        SiteTemplateOfflineValidator::inspect($path);
    }

    public function testInspectRejectsPackageForDifferentCmsVersion(): void
    {
        $path = $this->root . '/future-cms.zip';
        $this->writePackage($path, ['cms' => '9.9.9']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid site manifest');
        SiteTemplateOfflineValidator::inspect($path);
    }

    public function testPublicStagingAndImportReportAreExactAllowlists(): void
    {
        $staging = $this->root . '/public';
        self::assertTrue(mkdir($staging . '/packages', 0775, true));
        file_put_contents($staging . '/packages/offline-demo-site-v1.2.3.zip', 'zip');
        $allowed = ['packages/offline-demo-site-v1.2.3.zip', 'catalog.json'];
        SiteTemplateOfflineValidator::assertPublicStaging($staging, $allowed, [$allowed[0]]);
        SiteTemplateOfflineValidator::assertPackageDigests($staging, [
            $allowed[0] => ['size' => 3, 'sha256' => hash('sha256', 'zip')],
        ]);
        file_put_contents($staging . '/packages/offline-demo-site-v1.2.3.zip', 'bad');
        $message = '';
        try {
            SiteTemplateOfflineValidator::assertPackageDigests($staging, [
                $allowed[0] => ['size' => 3, 'sha256' => hash('sha256', 'zip')],
            ]);
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        }
        self::assertStringContainsString('bytes changed', $message);
        file_put_contents($staging . '/packages/offline-demo-site-v1.2.3.zip', 'zip');
        file_put_contents($staging . '/secret.env', 'secret');
        $message = '';
        try {
            SiteTemplateOfflineValidator::assertPublicStaging($staging, $allowed, [$allowed[0]]);
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        }
        self::assertStringContainsString('Unknown or unsafe', $message);
        unlink($staging . '/secret.env');
        file_put_contents($staging . '/CATALOG.JSON', '{}');
        $message = '';
        try {
            SiteTemplateOfflineValidator::assertPublicStaging($staging, $allowed, [$allowed[0]]);
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        }
        self::assertStringContainsString('Unknown or unsafe', $message);
        unlink($staging . '/CATALOG.JSON');

        $report = $this->root . '/report.json';
        file_put_contents($report, json_encode(['sites' => [[
            'theme' => 'offline-demo', 'version' => '1.2.3', 'status' => 'passed',
            'package_sha256' => str_repeat('a', 64),
        ]]], JSON_THROW_ON_ERROR));
        SiteTemplateOfflineValidator::validateImportReport($report, [
            'offline-demo' => ['version' => '1.2.3', 'sha256' => str_repeat('a', 64)],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not match verified package');
        SiteTemplateOfflineValidator::validateImportReport($report, [
            'offline-demo' => ['version' => '1.2.3', 'sha256' => str_repeat('b', 64)],
        ]);
    }

    public function testPublicStagingRejectsWindowsDirectoryJunctionOutsideRoot(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\' || !function_exists('exec')) {
            throw new \PHPUnit\Framework\SkippedWithMessageException('Windows junction support is required');
        }
        $staging = $this->root . '/junction-public';
        $outside = $this->root . '/junction-outside';
        self::assertTrue(mkdir($staging . '/assets/site-templates/offline-demo', 0775, true));
        self::assertTrue(mkdir($outside, 0775, true));
        file_put_contents($outside . '/preview.webp', 'outside');
        $junction = $staging . '/assets/site-templates/offline-demo/1.2.3';
        $output = [];
        $status = -1;
        exec('cmd /d /c mklink /J "' . str_replace('/', '\\', $junction) . '" "' . str_replace('/', '\\', $outside) . '"', $output, $status);
        if ($status !== 0 || !is_dir($junction)) {
            throw new \PHPUnit\Framework\SkippedWithMessageException('Cannot create a Windows junction');
        }
        $message = '';
        try {
            SiteTemplateOfflineValidator::assertPublicStaging($staging,
                ['assets/site-templates/offline-demo/1.2.3/preview.webp'],
                ['assets/site-templates/offline-demo/1.2.3/preview.webp']);
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        } finally {
            rmdir($junction);
        }
        self::assertStringContainsString('Unknown or unsafe', $message);
        self::assertSame('outside', file_get_contents($outside . '/preview.webp'));
    }

    public function testPreparationToolProducesOnlyAllowlistedPublicDraftAfterImportHashMatch(): void
    {
        [$staging, $covers, $inventory, $catalog, $report, $package] = $this->createPreparationFixture();

        [$status, $stdout, $stderr] = $this->runPrepare($inventory, $staging, $covers, $catalog, $report);
        self::assertSame(0, $status, $stderr);
        self::assertSame('index.php', json_decode($stdout, true, 8, JSON_THROW_ON_ERROR)['review']);
        $publicCatalog = (string) file_get_contents($staging . '/catalog.json');
        self::assertStringNotContainsString('local_source_path', $publicCatalog);
        self::assertStringNotContainsString(str_replace('\\', '/', $this->root), str_replace('\\', '/', $publicCatalog));
        self::assertFileExists($staging . '/assets/site-templates/offline-demo/1.2.3/preview.webp');

        $tracked = [$catalog, $staging . '/catalog.json', $staging . '/index.php',
            $staging . '/review-router.php', $staging . '/assets/site-templates/offline-demo/1.2.3/preview.webp'];
        $before = [];
        foreach ($tracked as $file) $before[$file] = (string) file_get_contents($file);
        $message = '';
        try {
            prepareSiteTemplateMarket([
                'inventory' => $inventory, 'delivery' => $staging, 'covers' => $covers,
                'catalog' => $catalog, 'import-report' => $report,
            ], static function () use ($package): void {
                $bytes = (string) file_get_contents($package);
                $offset = max(0, strlen($bytes) - 12);
                $bytes[$offset] = $bytes[$offset] === 'X' ? 'Y' : 'X';
                file_put_contents($package, $bytes);
            });
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        }
        self::assertStringContainsString('bytes changed', $message);
        foreach ($before as $file => $bytes) self::assertSame($bytes, file_get_contents($file), $file . ' was not rolled back');
    }

    /** @psalm-suppress UnusedVariable Windows-only body is unreachable to non-Windows static analysis. */
    public function testPreparationRechecksParentAndDoesNotWriteThroughJunction(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\' || !function_exists('exec')) {
            throw new \PHPUnit\Framework\SkippedWithMessageException('Windows junction support is required');
        }
        [$staging, $covers, $inventory, $catalog, $report] = $this->createPreparationFixture();
        $options = ['inventory' => $inventory, 'delivery' => $staging, 'covers' => $covers,
            'catalog' => $catalog, 'import-report' => $report];
        $outside = $this->root . '/replacement-outside';
        self::assertTrue(mkdir($outside, 0775, true));
        file_put_contents($outside . '/sentinel.txt', 'outside');
        $junction = $staging . '/assets/site-templates/offline-demo/1.2.3';
        $message = '';
        try {
            prepareSiteTemplateMarket($options, null, static function () use ($junction, $outside): void {
                if (is_dir($junction) && !rmdir($junction)) throw new \RuntimeException('Cannot prepare junction test');
                $output = [];
                $status = -1;
                exec('cmd /d /c mklink /J "' . str_replace('/', '\\', $junction) . '" "' . str_replace('/', '\\', $outside) . '"', $output, $status);
                if ($status !== 0 || !is_dir($junction)) throw new \RuntimeException('Cannot create test junction');
            });
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        } finally {
            if (is_dir($junction)) rmdir($junction);
        }
        self::assertStringContainsString('Unsafe staging target directory', $message);
        self::assertFileDoesNotExist($outside . '/preview.webp');
        self::assertSame('outside', file_get_contents($outside . '/sentinel.txt'));
    }

    /** @psalm-suppress UnusedVariable Windows-only body is unreachable to non-Windows static analysis. */
    public function testPreparationPinsDeliveryAcrossAncestorJunctionSwap(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\' || !function_exists('exec')) {
            throw new \PHPUnit\Framework\SkippedWithMessageException('Windows junction support is required');
        }
        [$staging, $covers, $inventory, $catalog, $report, $package] = $this->createPreparationFixture();
        $treeA = $this->root . '/delivery-tree-a';
        $treeB = $this->root . '/delivery-tree-b';
        $deliveryA = $treeA . '/public';
        $deliveryB = $treeB . '/public';
        self::assertTrue(mkdir($treeA, 0775, true));
        self::assertTrue(rename($staging, $deliveryA));
        self::assertTrue(mkdir($deliveryB . '/packages', 0775, true));
        self::assertTrue(copy($deliveryA . '/packages/' . basename($package), $deliveryB . '/packages/' . basename($package)));

        $junction = $this->root . '/delivery-current';
        $createJunction = static function (string $target) use ($junction): void {
            $output = [];
            $status = -1;
            exec('cmd /d /c mklink /J "' . str_replace('/', '\\', $junction) . '" "' . str_replace('/', '\\', $target) . '"', $output, $status);
            if ($status !== 0 || !is_dir($junction)) throw new \RuntimeException('Cannot create ancestor junction test');
        };
        $createJunction($treeA);
        $deliveryInput = $junction . '/public';
        $message = '';
        try {
            prepareSiteTemplateMarket([
                'inventory' => $inventory, 'delivery' => $deliveryInput, 'covers' => $covers,
                'catalog' => $catalog, 'import-report' => $report,
            ], null, static function () use ($junction, $treeB, $createJunction): void {
                if (!rmdir($junction)) throw new \RuntimeException('Cannot remove ancestor junction test');
                $createJunction($treeB);
            });
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        } finally {
            if (is_dir($junction) && !rmdir($junction)) throw new \RuntimeException('Cannot clean ancestor junction test');
        }

        self::assertStringContainsString('Delivery root changed', $message);
        foreach ([$deliveryA, $deliveryB] as $delivery) {
            self::assertFileDoesNotExist($delivery . '/catalog.json');
            self::assertFileDoesNotExist($delivery . '/index.php');
            self::assertFileDoesNotExist($delivery . '/review-router.php');
            self::assertFileDoesNotExist($delivery . '/assets/site-templates/offline-demo/1.2.3/preview.webp');
        }
    }

    public function testPreparationFailureRemovesNewEmptyLiveDirectories(): void
    {
        [$staging, $covers, $inventory, $catalog, $report] = $this->createPreparationFixture();
        $message = '';
        try {
            prepareSiteTemplateMarket([
                'inventory' => $inventory, 'delivery' => $staging, 'covers' => $covers,
                'catalog' => $catalog, 'import-report' => $report,
            ], null, static function (): void {
                throw new \RuntimeException('forced pre-replacement failure');
            });
        } catch (\RuntimeException $error) {
            $message = $error->getMessage();
        }
        self::assertSame('forced pre-replacement failure', $message);
        self::assertDirectoryDoesNotExist($staging . '/assets');
        self::assertFileDoesNotExist($staging . '/catalog.json');
        self::assertFileDoesNotExist($staging . '/index.php');
        self::assertFileDoesNotExist($staging . '/review-router.php');
    }

    /** @param array{digest?:string,omit_media?:bool,extra_name?:string,plugin_missing_media?:bool,reduced_schema?:bool,cms?:string} $changes */
    private function writePackage(string $path, array $changes = []): void
    {
        $files = [
            'theme/theme.json' => json_encode(['schema_version' => 1, 'name' => 'Offline demo', 'version' => '1.2.3', 'author' => 'Yikai'], JSON_THROW_ON_ERROR),
            'theme/layouts/header.php' => '<header style="background:url(/uploads/images/hero.webp)"></header>',
            'theme/layouts/footer.php' => '<footer></footer>',
        ];
        if (!($changes['omit_media'] ?? false)) $files['media/images/hero.webp'] = 'webp-fixture';
        if (isset($changes['extra_name'])) $files[$changes['extra_name']] = 'unsafe-fixture';
        if ($changes['plugin_missing_media'] ?? false) {
            $files['plugin-data/sample.json'] = json_encode([
                'format' => 'yikaicms-site-template-plugin-data', 'version' => 1, 'slug' => 'sample',
                'schema' => ['id' => 'sample/catalog', 'version' => 1, 'sha256' => 'sha256:' . hash('sha256', 'sample')],
                'payload' => ['image' => '/uploads/plugin-only.webp'],
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $digests = [];
        foreach ($files as $name => $bytes) $digests[$name] = hash('sha256', $bytes);
        if (isset($changes['digest'])) $digests['theme/layouts/footer.php'] = $changes['digest'];
        $schema = \SiteTemplateData::contractSchema();
        if ($changes['reduced_schema'] ?? false) $schema['contents'] = ['id', 'content'];
        $tables = [];
        foreach (\SiteTemplateData::TABLES as $table) $tables[$table] = [];
        $withPlugin = (bool) ($changes['plugin_missing_media'] ?? false);
        $manifest = [
            'format' => 'yikaicms-site-template', 'version' => $withPlugin ? 2 : 1,
            'cms' => $changes['cms'] ?? CMS_VERSION, 'theme' => 'offline-demo',
            'schema' => $schema, 'plugins' => $withPlugin ? [['slug' => 'sample', 'version' => '1.0.0']] : [],
            'plugin_data' => $withPlugin ? ['sample' => 'plugin-data/sample.json'] : [],
            'data' => ['tables' => $tables, 'settings' => []],
            'files' => $digests,
        ];
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('site.json', json_encode($manifest, JSON_THROW_ON_ERROR)));
        foreach ($files as $name => $bytes) self::assertTrue($zip->addFromString($name, $bytes));
        self::assertTrue($zip->close());
    }

    /** @return array{string,string,string,string,string,string} */
    private function createPreparationFixture(): array
    {
        if (!function_exists('imagewebp')) {
            throw new \PHPUnit\Framework\SkippedWithMessageException('GD WebP support is not available');
        }
        $staging = $this->root . '/public-' . bin2hex(random_bytes(3));
        $covers = $this->root . '/covers-' . bin2hex(random_bytes(3));
        self::assertTrue(mkdir($staging . '/packages', 0775, true));
        self::assertTrue(mkdir($covers, 0775, true));
        $package = $staging . '/packages/offline-demo-site-v1.2.3.zip';
        $this->writePackage($package);
        $image = imagecreatetruecolor(640, 320);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, imagecolorallocate($image, 12, 44, 88));
        self::assertTrue(imagewebp($image, $covers . '/site-01.webp', 80));
        imagedestroy($image);
        $suffix = bin2hex(random_bytes(3));
        $inventory = $this->root . '/inventory-' . $suffix . '.json';
        $catalog = $this->root . '/catalog-source-' . $suffix . '.json';
        $report = $this->root . '/report-' . $suffix . '.json';
        file_put_contents($inventory, json_encode([['id' => 'site-01', 'theme' => 'offline-demo', 'new_version' => '1.2.3']], JSON_THROW_ON_ERROR));
        file_put_contents($catalog, json_encode(['templates' => [[
            'slug' => 'offline-demo', 'name' => 'Offline demo', 'category' => 'trade', 'requires_php' => '>=8.0.0',
            'tier' => 'free', 'status' => 'draft', 'sig' => '', 'local_source_path' => 'must-not-escape',
        ]]], JSON_THROW_ON_ERROR));
        file_put_contents($report, json_encode(['sites' => [[
            'theme' => 'offline-demo', 'version' => '1.2.3', 'status' => 'passed',
            'package_sha256' => hash_file('sha256', $package),
        ]]], JSON_THROW_ON_ERROR));
        return [$staging, $covers, $inventory, $catalog, $report, $package];
    }

    /** @return array{int,string,string} */
    private function runPrepare(string $inventory, string $staging, string $covers, string $catalog, string $report): array
    {
        $command = [PHP_BINARY, ROOT_PATH . '/tools/prepare-site-template-market.php', '--inventory=' . $inventory,
            '--delivery=' . $staging, '--covers=' . $covers, '--catalog=' . $catalog, '--import-report=' . $report];
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT_PATH);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), (string) $stdout, (string) $stderr];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname());
        }
        rmdir($path);
    }
}
