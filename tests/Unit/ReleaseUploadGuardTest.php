<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/tools/ReleaseUploadGuard.php';

final class ReleaseUploadGuardTest extends TestCase
{
    private string $tempRoot;
    private string $projectRoot;
    private string $releaseDir;
    private string $updateRoot;
    /** @var array<string,mixed> */
    private array $catalog;
    /** @var array<string,mixed> */
    private array $delta;

    protected function setUp(): void
    {
        $this->tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'yikai-release-upload-' . bin2hex(random_bytes(5));
        $this->projectRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'project';
        $this->releaseDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'releases';
        $this->updateRoot = $this->tempRoot . DIRECTORY_SEPARATOR . 'update';
        foreach ([$this->releaseDir, $this->updateRoot . '/data', $this->updateRoot . '/packages'] as $dir) {
            self::assertTrue(mkdir($dir, 0700, true));
        }
        $this->writeValidFixture();
    }

    protected function tearDown(): void
    {
        if (!isset($this->tempRoot) || !is_dir($this->tempRoot)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tempRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($this->tempRoot);
    }

    public function testValidManifestProducesPackagesFirstAndCatalogLast(): void
    {
        $plan = ReleaseUploadGuard::inspect(
            $this->updateRoot,
            $this->releaseDir,
            '1.18.4'
        );

        self::assertSame('stable', $plan['channel']);
        self::assertSame(
            ['yikaicms-v1.18.4.zip', 'delta-1.18.3-to-1.18.4.zip'],
            array_map('basename', $plan['packages'])
        );
        self::assertSame(
            ['release-registry.json', 'releases.json'],
            array_map('basename', $plan['data'])
        );
    }

    public function testMissingChannelInRealCatalogBlocksUpload(): void
    {
        unset($this->catalog['releases'][0]['channel']);
        $this->writeJson($this->updateRoot . '/data/releases.json', $this->catalog);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('missing or invalid channel');
        $this->inspect();
    }

    public function testFullOnlyReleaseIsAllowedWhenManifestAndCatalogDeltasAreBothAbsent(): void
    {
        unlink($this->releaseDir . '/delta-1.18.3-to-1.18.4.zip');
        unlink($this->releaseDir . '/deltas-v1.18.4.json');
        unlink($this->updateRoot . '/packages/delta-1.18.3-to-1.18.4.zip');
        $this->catalog['releases'][0]['deltas'] = [];
        $this->writeJson($this->updateRoot . '/data/releases.json', $this->catalog);

        $plan = $this->inspect();

        self::assertSame(['yikaicms-v1.18.4.zip'], array_map('basename', $plan['packages']));
    }

    public function testUnlistedDeltaForSameTargetBlocksWildcardUpload(): void
    {
        file_put_contents(
            $this->releaseDir . '/delta-1.17.2-to-1.18.4.zip',
            'stale-gray-package'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unlisted delta artifacts');
        $this->inspect();
    }

    public function testDeltaOlderThanCurrentFullBuildIsRejected(): void
    {
        $deltaPath = $this->releaseDir . '/delta-1.18.3-to-1.18.4.zip';
        $fullPath = $this->releaseDir . '/yikaicms-v1.18.4.zip';
        $metadataPath = $this->releaseDir . '/deltas-v1.18.4.json';
        self::assertTrue(touch($deltaPath, 1700000000));
        self::assertTrue(touch($fullPath, 1700001000));
        self::assertTrue(touch($metadataPath, 1700001010));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('mtime mismatch');
        $this->inspect();
    }

    public function testCatalogDeltasMustExactlyMatchGeneratedMetadata(): void
    {
        $this->catalog['releases'][0]['deltas'][0]['size'] = '999KB';
        $this->writeJson($this->updateRoot . '/data/releases.json', $this->catalog);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('do not exactly match');
        $this->inspect();
    }

    public function testCopiedUpdatePackageMustMatchManifestHash(): void
    {
        file_put_contents(
            $this->updateRoot . '/packages/delta-1.18.3-to-1.18.4.zip',
            'wrong-copy'
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SHA256 mismatch');
        $this->inspect();
    }

    public function testExternalReleaseChecksPropagateFailureAndOutput(): void
    {
        $pass = $this->tempRoot . '/pass.php';
        $fail = $this->tempRoot . '/fail.php';
        file_put_contents($pass, "<?php fwrite(STDOUT, 'CHECK OK');\n");
        file_put_contents($fail, "<?php fwrite(STDERR, 'channel invalid'); exit(3);\n");

        self::assertSame('CHECK OK', ReleaseUploadGuard::runPhpScript($pass));
        try {
            ReleaseUploadGuard::runPhpScript($fail);
            self::fail('Expected release-check failure');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('channel invalid', $e->getMessage());
        }
    }

    public function testBuildAndCliKeepTheReleaseSafetyContractsVisible(): void
    {
        $build = file_get_contents(ROOT_PATH . '/build.sh');
        $cli = file_get_contents(ROOT_PATH . '/tools/release-upload-guard.php');
        self::assertIsString($build);
        self::assertIsString($cli);

        self::assertStringContainsString('delta-*-to-"$VERSION".zip', $build);
        self::assertStringContainsString('/tests/update-check-channel.php', $cli);
        self::assertStringContainsString('/bin/verify-release-signatures.php', $cli);
        self::assertStringContainsString("['--required']", $cli);
        self::assertStringContainsString('PACKAGES FIRST', $cli);
        self::assertStringContainsString('DATA LAST', $cli);
    }

    private function inspect(): array
    {
        return ReleaseUploadGuard::inspect(
            $this->updateRoot,
            $this->releaseDir,
            '1.18.4'
        );
    }

    private function writeValidFixture(): void
    {
        $fullBytes = 'full-package-1.18.4';
        $deltaBytes = 'delta-package-1.18.3-to-1.18.4';
        $fullHash = 'sha256:' . hash('sha256', $fullBytes);
        $deltaHash = 'sha256:' . hash('sha256', $deltaBytes);
        $fullName = 'yikaicms-v1.18.4.zip';
        $deltaName = 'delta-1.18.3-to-1.18.4.zip';

        file_put_contents($this->releaseDir . '/' . $fullName, $fullBytes);
        file_put_contents($this->releaseDir . '/' . $deltaName, $deltaBytes);
        file_put_contents($this->updateRoot . '/packages/' . $fullName, $fullBytes);
        file_put_contents($this->updateRoot . '/packages/' . $deltaName, $deltaBytes);

        $this->delta = [
            'from' => '1.18.3',
            'package' => $deltaName,
            'hash' => $deltaHash,
            'size' => '1KB',
        ];
        file_put_contents(
            $this->releaseDir . '/deltas-v1.18.4.json',
            '"deltas": [' . json_encode($this->delta, JSON_UNESCAPED_SLASHES) . "]\n"
        );

        $this->catalog = [
            'latest' => '1.18.4',
            'releases' => [[
                'version' => '1.18.4',
                'channel' => 'stable',
                'package' => $fullName,
                'hash' => $fullHash,
                'deltas' => [$this->delta + ['sig' => 'delta-signature']],
            ]],
        ];
        $this->writeJson($this->updateRoot . '/data/releases.json', $this->catalog);
        $this->writeJson($this->updateRoot . '/data/release-registry.json', [
            'schema' => 1,
            'versions' => ['1.18.4' => ['channel' => 'stable']],
        ]);

        $baseTime = time() - 60;
        touch($this->releaseDir . '/' . $fullName, $baseTime);
        touch($this->releaseDir . '/' . $deltaName, $baseTime + 10);
        touch($this->releaseDir . '/deltas-v1.18.4.json', $baseTime + 20);
    }

    /** @param array<string,mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
