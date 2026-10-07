<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 子目录部署时 BasePath 只改写 HTML 属性，不碰 <script> 里的字符串（见 BasePathTest）。
 * 所以脚本里请求站内地址必须写成 (window.YK_BASE || '') + '/admin/...'，
 * 写死 '/admin/...' 会打到域名根目录——2026-10-06 演示站子站控制台查不到新版本就是这类问题。
 */
final class ScriptBasePathContractTest extends TestCase
{
    private const PATTERN = '#(?:fetch\(\s*|location(?:\.href)?\s*=\s*|\.open\(\s*[\'"][A-Z]+[\'"]\s*,\s*|window\.open\(\s*)[\'"`]/(?:admin|api|plugins|member|install|uploads|assets)/#';

    public function testScriptsNeverRequestRootRelativeSiteUrls(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['admin', 'assets/js', 'includes', 'plugins', 'member'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = $file->getPathname();
                if (!preg_match('/\.(php|js)$/', $path) || str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                foreach (file($path) ?: [] as $i => $line) {
                    if (preg_match('#^\s*(?:\*|//)#', $line) === 1) {
                        continue;
                    }
                    if (preg_match(self::PATTERN, $line) === 1) {
                        $offenders[] = substr($path, strlen($root) + 1) . ':' . ($i + 1);
                    }
                }
            }
        }

        self::assertSame([], $offenders, "脚本里的站内地址要加 (window.YK_BASE || '') 前缀");
    }

    public function testDashboardUpdateCheckPostsWithCsrfBecauseTheEndpointIgnoresGet(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');
        $endpoint = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/upgrade_online.php');

        // 端点：GET 不认动作（防 CSRF）；控制台：必须 POST 带令牌，否则拿回整页 HTML、状态静默空白（2.0.4–2.0.5 的回归）
        self::assertStringContainsString("\$action = (\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (string) (\$_POST['action'] ?? '') : '';", $endpoint);
        self::assertStringNotContainsString('upgrade_online.php?action=', $index);
        self::assertStringContainsString("body: new URLSearchParams({ action: 'check', _token:", $index);
    }

    public function testDashboardUpdateCachesAreScopedToTheSubdirectory(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/index.php');

        self::assertStringContainsString("var key = 'yk_upd_' + (window.YK_BASE || '') + '_'", $index);
        self::assertStringContainsString("var themeKey = 'yk_theme_upd_' + (window.YK_BASE || '') + '_'", $index);
    }
}
