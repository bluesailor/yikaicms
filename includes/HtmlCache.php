<?php
/**
 * Yikai CMS - 模板路径级 HTML 缓存
 *
 * 用法 (前台入口页顶部):
 *   HtmlCache::start(300);
 *   ... 业务代码 ...
 *   HtmlCache::end();
 *
 * 失效:
 *   HtmlCache::invalidate();            // 清空全部
 *   HtmlCache::invalidate('product');   // 按前缀清理
 *
 * 已登录会员/管理员不走缓存。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');

final class HtmlCache
{
    private static string $currentKey = '';
    private static bool $buffering = false;
    private static int $ttl = 300;

    public static function dir(): string
    {
        return ROOT_PATH . '/storage/cache/html';
    }

    /**
     * 尝试命中缓存。若命中则直接输出 + exit；否则开启 OB 等待 end() 写入。
     */
    public static function start(int $ttl = 300): void
    {
        if (self::$buffering) return;

        // 总开关
        if ((string)config('html_cache_enabled', '0') !== '1') {
            return;
        }
        if (!self::isCacheable()) {
            return;
        }

        $ttlConfig = (int)config('html_cache_ttl', 0);
        self::$ttl = $ttlConfig > 0 ? $ttlConfig : $ttl;

        self::$currentKey = self::buildKey();
        $file = self::pathForKey(self::$currentKey);

        if (is_file($file) && (time() - filemtime($file)) < self::$ttl) {
            header('X-Cache: HIT');
            readfile($file);
            exit;
        }

        header('X-Cache: MISS');
        self::$buffering = true;
        ob_start();

        // 自动 end()：脚本结束时把 OB 内容写入缓存
        register_shutdown_function(function () {
            if (self::$buffering) self::end();
        });
    }

    /**
     * 关闭缓冲并写入缓存
     */
    public static function end(): void
    {
        if (!self::$buffering) return;

        $html = ob_get_clean();
        self::$buffering = false;
        echo $html;

        if (self::$currentKey === '' || $html === '' || $html === false) return;

        // 只缓存正常响应。500/404/302 的页面体一旦落盘，一次瞬时故障就会被
        // 冻结成静态文件反复吐给所有访客，直到 TTL 到期——排查时还看不到
        // 新的错误日志（请求根本没进 PHP 业务），极难定位。
        $code = http_response_code();
        if ($code !== false && $code !== 200) return;

        // 致命错误走 shutdown 时 http_response_code() 可能仍是 200，
        // 补一道：本次请求已产生 E_ERROR 级错误则不落盘。
        $last = error_get_last();
        if ($last !== null && ((int) $last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) !== 0) {
            return;
        }

        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = self::pathForKey(self::$currentKey);
        @file_put_contents($file, (string)$html, LOCK_EX);
    }

    /**
     * 清除缓存（全部或按 key 前缀）
     */
    public static function invalidate(?string $prefix = null): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) return 0;
        $count = 0;
        foreach (glob($dir . '/*.html') ?: [] as $file) {
            if ($prefix !== null && strpos(basename($file), $prefix) !== 0) continue;
            if (@unlink($file)) $count++;
        }
        return $count;
    }

    private static function isCacheable(): bool
    {
        // 仅缓存 GET 请求
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;

        // 静态生成器自爬请求：始终实时渲染，且不写入 TTL 缓存（与 StaticHtml 互不污染）
        if (!empty($_SERVER['HTTP_X_STATIC_GEN'])) return false;

        // 管理员/会员登录态不缓存
        if (!empty($_SESSION['admin_id'])) return false;
        if (!empty($_SESSION['member_id'])) return false;
        if (!empty($_COOKIE['PHPSESSID']) && session_status() === PHP_SESSION_ACTIVE) {
            if (!empty($_SESSION['admin_id']) || !empty($_SESSION['member_id'])) return false;
        }

        // 含动态 token 的页面不缓存（表单页）
        if (isset($_GET['token']) || isset($_GET['csrf'])) return false;

        return true;
    }

    private static function buildKey(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $lang = defined('SITE_LANG') ? SITE_LANG : (string)config('site_lang', 'zh-CN');
        $isMobile = self::isMobile() ? 'm' : 'd';
        return md5(self::releaseNamespace() . '|' . $uri . '|' . $lang . '|' . $isMobile);
    }

    private static function releaseNamespace(): string
    {
        static $namespace = null;
        if (is_string($namespace)) {
            return $namespace;
        }

        $buildFile = ROOT_PATH . '/config/build.php';
        if (is_file($buildFile)) {
            $buildId = require $buildFile;
            if (is_string($buildId) && $buildId !== '') {
                return $namespace = $buildId;
            }
        }

        $versionFile = ROOT_PATH . '/config/version.php';
        $version = defined('CMS_VERSION') ? (string) CMS_VERSION : 'dev';
        return $namespace = $version . ':' . (string) (@filemtime($versionFile) ?: 0);
    }

    private static function pathForKey(string $key): string
    {
        return self::dir() . '/' . $key . '.html';
    }

    private static function isMobile(): bool
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return (bool)preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $ua);
    }
}

// 便捷辅助
function htmlCacheStart(int $ttl = 300): void { HtmlCache::start($ttl); }
function htmlCacheEnd(): void { HtmlCache::end(); }
function htmlCacheInvalidate(?string $prefix = null): int { return HtmlCache::invalidate($prefix); }

// 失效钩子
// 1) Model 基类的 create/update/delete 都会触发通用 data_changed
add_action('data_changed', function (string $table = '', $id = null): void {
    // 黑名单：这些表写入不影响前台缓存，避免无谓清理
    static $skipTables = ['admin_logs', 'ai_logs', 'login_throttle', 'form_throttle'];
    if (in_array($table, $skipTables, true)) return;
    HtmlCache::invalidate();
});

// 2) 兼容老钩子（如果有插件还在用）
add_action('after_save_content', function (): void { HtmlCache::invalidate(); });
add_action('after_save_product', function (): void { HtmlCache::invalidate(); });
add_action('after_delete_content', function (): void { HtmlCache::invalidate(); });
add_action('after_delete_product', function (): void { HtmlCache::invalidate(); });
add_action('setting_saved', function (): void { HtmlCache::invalidate(); });
