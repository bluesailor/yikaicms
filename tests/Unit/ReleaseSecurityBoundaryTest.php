<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseSecurityBoundaryTest extends TestCase
{
    public function testLegacyUnauthenticatedUpgradeEntrypointsAreAbsent(): void
    {
        self::assertFileDoesNotExist(ROOT_PATH . '/install/upgrade.php');
        self::assertFileDoesNotExist(ROOT_PATH . '/install/run_upgrade.php');

        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        self::assertStringContainsString('"install/upgrade.php"', $build);
        self::assertStringContainsString('"install/run_upgrade.php"', $build);
        self::assertStringContainsString('install/upgrade.php|install/run_upgrade.php', $build);

        $runner = (string) file_get_contents(ROOT_PATH . '/includes/UpgradeRunner.php');
        self::assertStringContainsString('uo_is_legacy_install_upgrade($rel)', $runner);
        self::assertStringContainsString('不可因 install/ 的普通升级保护而跳过', $runner);

        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString('removeLegacyInstallUpgradeEntrypoints();', $functions);
    }

    public function testDeploymentSecurityRulesShipWithRelease(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        self::assertStringNotContainsString("\n    \"deploy\"\n", $build);
        self::assertStringContainsString('"deploy/nginx-server.conf"', $build);
        self::assertStringContainsString('"deploy/nginx-baota.conf"', $build);

        $full = (string) file_get_contents(ROOT_PATH . '/deploy/nginx-server.conf');
        self::assertStringContainsString('location ^~ /storage/', $full);
        self::assertStringContainsString('location ^~ /install/', $full);
        self::assertStringContainsString('installed.lock', $full);

        foreach (['aliyun-nginx-minimal.txt', 'aliyun-nginx.htaccess'] as $name) {
            $config = (string) file_get_contents(ROOT_PATH . '/deploy/' . $name);
            self::assertStringContainsString('location = /install/', $config);
            self::assertStringContainsString('location ~ ^/install/(?!index\\.php$)', $config);
            self::assertStringContainsString('location ~ ^/(config|storage|vendor|includes|bin|migrations|recipes)/', $config);
        }
    }
}
