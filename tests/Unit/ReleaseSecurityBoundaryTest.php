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
        self::assertStringContainsString('LegacyInstallCleanup::run(ROOT_PATH)', $runner);

        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringNotContainsString('removeLegacyInstallUpgradeEntrypoints();', $functions);

        $header = (string) file_get_contents(ROOT_PATH . '/admin/includes/header.php');
        self::assertStringContainsString('LegacyInstallCleanup::runThrottled(', $header);
        self::assertStringContainsString('legacy-install-cleanup-at.txt', $header);
    }

    public function testDeploymentSecurityRulesShipWithRelease(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        self::assertStringNotContainsString("\n    \"deploy\"\n", $build);
        self::assertStringContainsString('"deploy/nginx-server.conf"', $build);
        self::assertStringContainsString('"deploy/nginx-baota.conf"', $build);

        $full = (string) file_get_contents(ROOT_PATH . '/deploy/nginx-server.conf');
        self::assertStringContainsString('location ^~ /storage/', $full);

        // R05（2026-09-18 复审，真实 Nginx 实测）：/en/news.html 缺静态文件时，
        // 语言规则去掉前缀后会重新匹配通用 .html 规则，进而直出默认语言的静态页。
        // 带语言前缀的规则必须排在通用规则之前，且未命中直接交给 PHP。
        $langLocation = strpos($full, 'location ~ ^/(?:ja|en|zh-CN|zh-TW)/.+\.html$');
        $htmlLocation = strpos($full, "location ~ \.html\$ {");
        self::assertIsInt($langLocation, '缺少多语言静态回退规则');
        self::assertIsInt($htmlLocation);
        self::assertLessThan($htmlLocation, $langLocation, '多语言规则必须排在通用 .html 规则之前');
        self::assertStringContainsString('try_files /html$uri /index.php?$args;', $full);
        self::assertStringContainsString('location ^~ /install/', $full);
        self::assertStringContainsString('installed.lock', $full);

        foreach (['aliyun-nginx-minimal.txt', 'aliyun-nginx.htaccess'] as $name) {
            $config = (string) file_get_contents(ROOT_PATH . '/deploy/' . $name);
            self::assertStringContainsString('location = /install/', $config);
            self::assertStringContainsString('location ~ ^/install/(?!index\\.php$)', $config);
            self::assertStringContainsString('location ~ ^/(config|storage|vendor|includes|bin|migrations|recipes)/', $config);
        }

        // F03（2026-09-17 审计）：^~ /api/v1/ 会把外层 `location ~ \.php$` 排除在匹配之外，
        // 块内没有 FastCGI 时，try_files 命中的分发器会被当静态文件输出。
        $apiBlock = substr($full, (int) strpos($full, 'location ^~ /api/v1/'));
        $apiBlock = substr($apiBlock, 0, (int) strpos($apiBlock, "\n}\n") + 3);
        self::assertStringContainsString('location ~ ^/api/v1/index\\.php$', $apiBlock);
        self::assertStringContainsString('fastcgi_pass', $apiBlock);
        self::assertStringContainsString('deny all;', $apiBlock);
        foreach (['config/site-health-probe.php', 'includes/site-health-probe.php'] as $probe) {
            self::assertFileExists(ROOT_PATH . '/' . $probe);
            $source = (string) file_get_contents(ROOT_PATH . '/' . $probe);
            self::assertStringContainsString('YIKAI_SITE_HEALTH_PHP_PROBE', $source);
            self::assertStringNotContainsString('config/config.php', $source);
            self::assertStringNotContainsString('DB_PASS', $source);
        }
    }

    /**
     * F04（2026-09-17 审计）：宝塔一节原本只让用户选 wordpress 伪静态预设，
     * 那条 catch-all 会先放行真实文件，站点目录内的 storage/database.sqlite
     * 因此可被直接下载。指引必须要求引入随包的拒绝规则。
     */
    public function testBaotaInstructionsRequireTheBundledDenyRules(): void
    {
        $readme = str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/README.md'));
        $start = (int) strpos($readme, '#### 宝塔面板（Nginx）');
        self::assertGreaterThan(0, $start, 'README 缺少宝塔部署一节');
        $section = substr($readme, $start, (int) strpos($readme, '#### 阿里云') - $start);

        self::assertStringContainsString('deploy/nginx-baota.conf', $section);
        self::assertStringContainsString('storage/database.sqlite', $section);
        self::assertStringContainsString('不要只选 wordpress 预设', $section);

        // 被指向的文件必须真的封住敏感目录
        $baota = (string) file_get_contents(ROOT_PATH . '/deploy/nginx-baota.conf');
        self::assertStringContainsString('storage', $baota);
        self::assertStringContainsString('return 403;', $baota);
    }
}
