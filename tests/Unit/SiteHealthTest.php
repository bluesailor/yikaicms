<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/SiteHealth.php';

final class SiteHealthTest extends TestCase
{
    public function testNormalizationRejectsUnsafeActionsAndDuplicateIds(): void
    {
        $checks = SiteHealth::normalizeResults([
            ['id' => 'A Check', 'status' => 'bad', 'title' => 'One', 'description' => 'First', 'action_url' => 'https://example.com'],
            ['id' => 'a-check', 'status' => SiteHealth::GOOD, 'title' => 'Duplicate'],
            ['id' => 'valid', 'status' => SiteHealth::CRITICAL, 'title' => 'Two', 'action_url' => '/admin/system.php?tab=log'],
        ]);

        self::assertCount(2, $checks);
        self::assertSame(SiteHealth::UNKNOWN, $checks[0]['status']);
        self::assertSame('', $checks[0]['action_url']);
        self::assertSame('/admin/system.php?tab=log', $checks[1]['action_url']);
    }

    public function testSummaryCountsKnownStatuses(): void
    {
        $summary = SiteHealth::summary([
            ['id' => 'one', 'status' => SiteHealth::CRITICAL],
            ['id' => 'two', 'status' => SiteHealth::GOOD],
            ['id' => 'three', 'status' => SiteHealth::GOOD],
        ]);

        self::assertSame(['critical' => 1, 'recommended' => 0, 'good' => 2, 'unknown' => 0, 'total' => 3], $summary);
    }

    public function testMediaOptimizationResultRequiresCompleteScanAndLinksToRepair(): void
    {
        $failed = SiteHealth::mediaOptimizationResult(['failed' => true]);
        self::assertSame(SiteHealth::UNKNOWN, $failed['status']);

        $incomplete = SiteHealth::mediaOptimizationResult(['total' => 30, 'scanned' => 24]);
        self::assertSame(SiteHealth::UNKNOWN, $incomplete['status']);

        $attention = SiteHealth::mediaOptimizationResult([
            'total' => 30,
            'scanned' => 30,
            'healthy' => 20,
            'pending' => 8,
            'missing' => 2,
            'unsupported' => 0,
        ]);
        self::assertSame(SiteHealth::RECOMMENDED, $attention['status']);
        self::assertSame('/admin/media.php?health=attention', $attention['action_url']);

        $good = SiteHealth::mediaOptimizationResult([
            'total' => 4,
            'scanned' => 4,
            'healthy' => 3,
            'unsupported' => 1,
        ]);
        self::assertSame(SiteHealth::GOOD, $good['status']);
        self::assertSame('/admin/media.php', $good['action_url']);
    }

    public function testBrandAssetHealthReportsMissingLocalFilesWithoutRejectingRemoteAssets(): void
    {
        $good = SiteHealth::brandAssetsResult([
            ['label' => 'Logo', 'url' => '/images/logo.png'],
            ['label' => 'Icon', 'url' => 'https://cdn.example.test/favicon.png'],
            ['label' => 'Optional', 'url' => ''],
        ], ROOT_PATH);
        self::assertSame(SiteHealth::GOOD, $good['status']);

        $bad = SiteHealth::brandAssetsResult([
            ['label' => 'Logo', 'url' => '/uploads/brand/missing.png'],
            ['label' => 'Icon', 'url' => 'javascript:alert(1)'],
        ], ROOT_PATH);
        self::assertSame(SiteHealth::RECOMMENDED, $bad['status']);
        self::assertSame('/admin/setting.php?tab=basic', $bad['action_url']);
        self::assertStringContainsString('Logo', $bad['description']);
        self::assertStringContainsString('Icon', $bad['description']);
    }

    public function testBrowserProbesDetectSourceAndStorageExposure(): void
    {
        $checks = SiteHealth::evaluateBrowserProbes([
            ['id' => 'config_php_web', 'status' => 200, 'body' => 'YIKAI_SITE_HEALTH_PHP_PROBE', 'error' => false],
            ['id' => 'includes_php_web', 'status' => 404, 'body' => 'Not Found', 'error' => false],
            ['id' => 'storage_web', 'status' => 200, 'body' => 'YIKAI_STORAGE_CANARY:secret-token', 'error' => false],
            ['id' => 'loopback', 'status' => 200, 'body' => '', 'error' => false],
        ], 'secret-token');
        $byId = array_column($checks, null, 'id');

        self::assertSame(SiteHealth::CRITICAL, $byId['config_php_web']['status']);
        self::assertSame(SiteHealth::GOOD, $byId['includes_php_web']['status']);
        self::assertSame(SiteHealth::CRITICAL, $byId['storage_web']['status']);
        self::assertSame(SiteHealth::GOOD, $byId['loopback']['status']);
    }

    public function testTemporaryStorageProbeCanBeCleanedUp(): void
    {
        $directory = sys_get_temp_dir() . '/yikai-health-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        try {
            $probe = SiteHealth::createBrowserProbes($directory);
            self::assertNotSame('', $probe['storage_file']);
            self::assertFileExists($probe['storage_file']);
            self::assertStringNotContainsString($probe['storage_token'], $probe['probes'][0]['url']);

            SiteHealth::cleanupBrowserProbe($probe['storage_file'], $directory);
            self::assertFileDoesNotExist($probe['storage_file']);
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    /**
     * 诊断信息是给技术支持看的，不是给机器解析的：扩展那两行必须列出「哪些缺了」，
     * 不能再吐 {"pdo":true,...}；字节数要换算成人读得懂的单位。
     */
    public function testDiagnosticValuesRenderForHumans(): void
    {
        $extensions = SiteHealth::formatDiagnosticValue(
            'required_extensions',
            ['pdo' => true, 'json' => true, 'dom' => false]
        );
        self::assertStringNotContainsString('{', $extensions);
        self::assertStringNotContainsString('true', $extensions);
        self::assertStringContainsString('pdo', $extensions);
        self::assertStringContainsString('dom', $extensions);

        self::assertSame('179 GB', SiteHealth::formatDiagnosticValue('disk_free_bytes', 192331071488));
        self::assertSame('512 B', SiteHealth::formatDiagnosticValue('disk_free_bytes', 512));

        // 空值不能渲染成空白格子，要明确说「取不到」
        self::assertNotSame('', SiteHealth::formatDiagnosticValue('server', ''));
        self::assertNotSame('', SiteHealth::formatDiagnosticValue('disk_free_bytes', null));
    }

    /**
     * 上传目录探针的判定方向与其它探针相反：这里「被执行」才是最坏结果。
     * 由来 2026-08-23：某客户主机把 config/、includes/ 的 PHP 当静态文件吐出（看着吓人），
     * 却让 uploads/ 里的 PHP 正常执行（真正的 RCE 面）。防护方向反了，而且没人看得见。
     */
    public function testUploadProbeTreatsExecutionAsCritical(): void
    {
        $token = str_repeat('a', 32);
        $verdict = static function (array $observation) use ($token): array {
            $all = SiteHealth::evaluateBrowserProbes([$observation], '', $token);
            foreach ($all as $result) {
                if ($result['id'] === 'upload_php_exec') {
                    return $result;
                }
            }
            self::fail('缺少 upload_php_exec 结果');
        };

        // 被执行 → 严重
        $executed = $verdict(['id' => 'upload_php_exec', 'status' => 200, 'body' => 'YIKAI_UPLOAD_PROBE_EXEC:' . $token]);
        self::assertSame(SiteHealth::CRITICAL, $executed['status']);

        // 被当静态文件原样返回 → 建议（不是 RCE，但内容泄露）
        $source = $verdict(['id' => 'upload_php_exec', 'status' => 200, 'body' => '<?php /* YIKAI_UPLOAD_PROBE_SRC:' . $token . ' */']);
        self::assertSame(SiteHealth::RECOMMENDED, $source['status']);

        // 被服务器挡住 → 良好
        foreach ([403, 404] as $code) {
            $blocked = $verdict(['id' => 'upload_php_exec', 'status' => $code, 'body' => '']);
            self::assertSame(SiteHealth::GOOD, $blocked['status'], "HTTP $code 应判良好");
        }

        // 请求本身失败 → 未知，不能报成良好
        $failed = $verdict(['id' => 'upload_php_exec', 'status' => 0, 'body' => '', 'error' => true]);
        self::assertSame(SiteHealth::UNKNOWN, $failed['status']);
    }

    /** 探针文件必须落在 uploads 里、并且能被清理干净——留一个可执行 .php 在上传目录本身就是风险。 */
    public function testUploadProbeIsCreatedAndCleanedUp(): void
    {
        $storage = sys_get_temp_dir() . '/yk-health-storage-' . bin2hex(random_bytes(6));
        $uploads = sys_get_temp_dir() . '/yk-health-uploads-' . bin2hex(random_bytes(6));
        mkdir($storage);
        mkdir($uploads);
        try {
            $probe = SiteHealth::createBrowserProbes($storage, $uploads);
            self::assertNotSame('', $probe['upload_file']);
            self::assertFileExists($probe['upload_file']);
            self::assertMatchesRegularExpression('/^site-health-probe-[a-f0-9]{16}\.php$/', basename($probe['upload_file']));
            self::assertStringContainsString($probe['upload_token'], (string) file_get_contents($probe['upload_file']));

            $urls = array_column($probe['probes'], 'url', 'id');
            self::assertArrayHasKey('upload_php_exec', $urls);
            self::assertStringStartsWith('/uploads/', $urls['upload_php_exec']);

            SiteHealth::cleanupUploadProbe($probe['upload_file'], $uploads);
            self::assertFileDoesNotExist($probe['upload_file']);

            // 目录不符时不得删除——防止被构造成任意文件删除
            $outside = $uploads . '/keep.php';
            file_put_contents($outside, 'x');
            SiteHealth::cleanupUploadProbe($outside, $storage);
            self::assertFileExists($outside);
        } finally {
            foreach ([$storage, $uploads] as $dir) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($dir);
            }
        }
    }
}
