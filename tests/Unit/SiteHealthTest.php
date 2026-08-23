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
}
