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
    // 2.1.3 起也查 .src = '/…'（脚本换图、刷新验证码）与根目录入口文件（/captcha.php 等）
    private const PATTERN = '#(?:fetch\(\s*|location(?:\.href)?\s*=\s*|\.open\(\s*[\'"][A-Z]+[\'"]\s*,\s*|window\.open\(\s*|\.src\s*=\s*)[\'"`]/(?:(?:admin|api|plugins|member|install|uploads|assets)/|[a-z_]+\.php)#';

    public function testScriptsNeverRequestRootRelativeSiteUrls(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        $paths = glob($root . '/*.php') ?: [];   // 前台入口（product.php、detail.php…）也有内联脚本
        foreach (['admin', 'assets/js', 'includes', 'plugins', 'member', 'themes', 'views'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $paths[] = $file->getPathname();
            }
        }
        foreach ($paths as $path) {
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

        self::assertSame([], $offenders, "脚本里的站内地址要加 (window.YK_BASE || '') 前缀");
    }

    /**
     * 输出改写只碰 HTML 属性；交给脚本的地址数据要在生成处补前缀（2.1.3 子目录审计逐一找到的出处）。
     * 链接目录、区块数据等会写回内容的地址保持站内相对口径，不在此列。
     */
    public function testDataHandedToScriptsCarriesTheMountPrefix(): void
    {
        $root = dirname(__DIR__, 2);
        $expect = [
            'admin/includes/header.php' => ["'url' => BasePath::url((string) (\$_it['url'] ?? ''))", "'url' => BasePath::url((string) \$__r['url'])"],
            'admin/api_search.php' => ["'url'   => BasePath::url((string) \$p['url'])"],
            'admin/blox_editor.php' => ['json_encode(BasePath::urlsIn($bloxPageSwitcher)', 'json_encode(BasePath::urlsIn($bloxSourceLinks)', 'json_encode(BasePath::urlsIn($contactManageActions)'],
            'product.php' => ["array_map([BasePath::class, 'url'], array_values(\$productImages))", "BasePath::imageData(\$v['main'])", "BasePath::url('/captcha.php')"],
            'detail.php' => ["array_map([BasePath::class, 'url'], array_values(\$galleryImages))"],
            'includes/partials/album-lightbox.php' => ["BasePath::url((string) \$p['image'])"],
            'assets/js/yikay-page-settings.js' => ['(root.YK_BASE || "") + url.pathname'],
            'admin/blox_editor/partials/overlays.php' => [':href="pageHref()"'],
        ];
        foreach ($expect as $file => $needles) {
            $source = (string) file_get_contents($root . '/' . $file);
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $source, $file);
            }
        }
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
        self::assertStringContainsString("var themeKey = 'yk_theme_chk_' + (window.YK_BASE || '');", $index);
    }
}
