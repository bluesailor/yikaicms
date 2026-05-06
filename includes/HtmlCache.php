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
        return md5($uri . '|' . $lang . '|' . $isMobile);
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

// 挂载失效钩子：保存内容/产品/设置后清缓存
add_action('after_save_content', function (): void { HtmlCache::invalidate(); });
add_action('after_save_product', function (): void { HtmlCache::invalidate(); });
add_action('after_delete_content', function (): void { HtmlCache::invalidate(); });
add_action('after_delete_product', function (): void { HtmlCache::invalidate(); });
add_action('setting_saved', function (): void { HtmlCache::invalidate(); });
