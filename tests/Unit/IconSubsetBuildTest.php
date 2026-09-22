<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IconSubsetBuildTest extends TestCase
{
    public function testGeneratedSubsetAndAuditAreCurrent(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg(ROOT_PATH . '/tools/build-icon-subsets.php') . ' --check 2>&1';
        $output = [];
        $exitCode = 0;
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    public function testPublicThemeHeadersUseSubsetWhileAdminKeepsFullFont(): void
    {
        $headers = [
            ROOT_PATH . '/themes/default/layouts/header.php',
            ROOT_PATH . '/marketplace/themes/trade/layouts/header.php',
            ROOT_PATH . '/marketplace/themes/minimal/layouts/header.php',
            ROOT_PATH . '/marketplace/themes/business/layouts/header.php',
            ROOT_PATH . '/marketplace/themes/aurora/layouts/header.php',
        ];
        foreach ($headers as $header) {
            $source = (string) file_get_contents($header);
            self::assertStringContainsString('/assets/icons/site-icons.min.css', $source, $header);
            self::assertStringContainsString("assetVer('/assets/icons/site-icons.min.css')", $source, $header);
            self::assertStringNotContainsString('/assets/tabler/tabler-icons.min.css', $source, $header);
            self::assertStringNotContainsString('/assets/bootstrap-icons/bootstrap-icons.min.css', $source, $header);
        }

        $admin = (string) file_get_contents(ROOT_PATH . '/admin/includes/header.php');
        self::assertStringContainsString('/assets/tabler/tabler-icons.min.css', $admin);
    }

    public function testAuditRecordsExpectedReductionAndNoMissingIcons(): void
    {
        $audit = json_decode(
            (string) file_get_contents(ROOT_PATH . '/assets/icons/site-icon-audit.json'),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertSame(1, $audit['schema']);
        self::assertNotEmpty($audit['icons']['tabler']);
        self::assertSame([], $audit['icons']['bootstrap']);
        self::assertSame(
            ['assets/icons/site-icons.min.css', 'assets/icons/fonts/tabler-icons-site.woff2'],
            array_keys($audit['outputs'])
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $audit['sources']['tabler']['font_sha256']);
        self::assertStringContainsString('?v=' . CMS_VERSION, (string) file_get_contents(ROOT_PATH . '/assets/icons/site-icons.min.css'));
        self::assertLessThan(
            (int) $audit['sources']['tabler']['css_bytes'] + (int) $audit['sources']['bootstrap']['css_bytes'],
            (int) $audit['outputs']['assets/icons/site-icons.min.css']['bytes']
        );
        self::assertFileExists(ROOT_PATH . '/assets/icons/fonts/tabler-icons-site.woff2');
    }

    public function testRuntimePackageRequiresEverySubsetLookupAndRenderAsset(): void
    {
        $runtime = require ROOT_PATH . '/config/release-runtime.php';
        foreach ([
            'assets/icons/site-icons.min.css',
            'assets/icons/site-icon-audit.json',
            'assets/icons/fonts/tabler-icons-site.woff2',
        ] as $path) {
            self::assertContains($path, $runtime['required_files']);
        }
    }
}
