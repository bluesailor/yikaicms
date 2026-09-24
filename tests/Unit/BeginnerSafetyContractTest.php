<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.0 新手第一批：安装与删除路径上会让小白卡死或丢数据的几处，钉住不回退。
 */
final class BeginnerSafetyContractTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $path);
    }

    /** PHP 7 主机：版本门必须在任何可能调用 PHP 8 函数的 require 之前 */
    public function testPhpGuardRunsBeforeEverythingElseInEachEntry(): void
    {
        $entries = [
            'index.php' => "/includes/php_guard.php'",
            'install/index.php' => "/includes/php_guard.php'",
            'includes/init.php' => "/php_guard.php'",
        ];
        foreach ($entries as $file => $needle) {
            $src = $this->source($file);
            $guard = strpos($src, $needle);
            self::assertNotFalse($guard, "{$file} 必须引入 php_guard.php");
            // 版本门之前不能有任何其它 require（BasePath 等会调 PHP 8 函数）
            self::assertSame(
                substr_count($src, 'require_once', 0, $guard + strlen($needle)),
                1,
                "{$file} 的版本门必须是第一个 require"
            );
        }
        self::assertContains('includes/php_guard.php', (require ROOT_PATH . '/config/release-runtime.php')['required_files']);
    }

    /** 守卫文件本身要在老 PHP 上能解析、能执行 */
    public function testPhpGuardAvoidsModernSyntaxAndFunctions(): void
    {
        // 只看代码，不看注释（注释里会提到这些函数名来解释为什么不用）
        $src = '';
        foreach (token_get_all($this->source('includes/php_guard.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $src .= is_array($token) ? $token[1] : $token;
        }
        self::assertStringNotContainsString('declare(strict_types', $src);
        self::assertStringNotContainsString('http_response_code', $src, 'PHP 5.4+ 才有');
        self::assertStringNotContainsString('fn(', str_replace(' ', '', $src));
        self::assertStringNotContainsString('str_starts_with', $src);
        self::assertStringNotContainsString('str_contains', $src);
        self::assertStringContainsString('PHP_VERSION_ID < 80000', $src);
    }

    /** 根目录不可写时不能动数据库；锁写失败不能报成功 */
    public function testInstallerRefusesUnwritableRootAndChecksLockWrite(): void
    {
        $src = $this->source('install/index.php');
        self::assertStringContainsString("\$checks['dir_root']", $src);
        $installAction = (int) strpos($src, "if (\$action === 'install') {");
        $rootGuard = strpos($src, "'code' => 'root_not_writable'", $installAction);
        $firstDbConnect = strpos($src, 'new PDO', $installAction);
        self::assertNotFalse($rootGuard);
        self::assertLessThan((int) $firstDbConnect, $rootGuard, '根目录检查必须在连接数据库之前');
        self::assertStringContainsString(
            "if (file_put_contents(ROOT_PATH . '/installed.lock', date('Y-m-d H:i:s')) === false) {",
            $src
        );
        foreach (['zh', 'en', 'ja'] as $lang) {
            $L = require ROOT_PATH . "/install/lang/{$lang}.php";
            foreach (['error_root_not_writable', 'error_lock_write', 'db_exposed_warn'] as $key) {
                self::assertArrayHasKey($key, $L, "install/lang/{$lang}.php 缺 {$key}");
            }
        }
    }

    /** nginx 指引：不能再把只有 try_files 的 wordpress 预设当成推荐 */
    public function testInstallerNginxAdviceLeadsWithTheBundledRules(): void
    {
        foreach (['zh', 'en', 'ja'] as $lang) {
            $L = require ROOT_PATH . "/install/lang/{$lang}.php";
            self::assertStringContainsString('deploy/nginx-baota.conf', $L['rewrite_nginx_wp'], $lang);
            self::assertStringContainsString('storage/database.sqlite', $L['rewrite_nginx_wp'], $lang);
        }
        $src = $this->source('install/index.php');
        $snippet = substr($src, (int) strpos($src, 'id="nginxCode"'), 400);
        self::assertStringContainsString('storage', $snippet, '手写 server 块的片段必须先拦敏感目录');
        self::assertStringContainsString('window.ykWarnIfDbExposed', $src);
        self::assertStringContainsString("'SQLite format 3'", $src, '只凭文件头判定，避免路由回 200 HTML 误报');
    }

    /** 删栏目 / 删单页：内容进回收站，不再物理删除 */
    public function testChannelAndPageDeletionUseTheRecycleBin(): void
    {
        foreach (['admin/channel.php', 'admin/page.php'] as $file) {
            $src = $this->source($file);
            self::assertStringContainsString('contentModel()->trashChannelContents($id)', $src, $file);
            self::assertDoesNotMatchRegularExpression('/DELETE FROM[^;]*contents[^;]*channel_id/i', $src, $file);
        }
        self::assertStringContainsString("\$action === 'delete_preview'", $this->source('admin/channel.php'));
    }

    /** 构建器：已发布的首页不再挂「旧首页仍在线」；断开轮播同步要当场告诉用户 */
    public function testBuilderHomeStatusAndBannerForkAreHonest(): void
    {
        self::assertStringContainsString(
            'x-show="!homePublished" x-cloak data-testid="blox-legacy-home-badge"',
            $this->source('admin/blox_editor/partials/header.php')
        );
        self::assertStringContainsString('this.homeDynamicText.forkedNotice', $this->source('assets/js/blox-banner-panel.js'));
        self::assertStringContainsString("'forkedNotice' => __('blox_home_banner_forked_notice')", $this->source('admin/blox_editor.php'));
    }
}
