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

require_once __DIR__ . '/ProductCatalogRequest.php';

if (!defined('ROOT_PATH')) exit('Access Denied');

final class HtmlCache
{
    private static string $currentKey = '';
    private static string $currentGeneration = '';
    private static bool $buffering = false;
    private static int $ttl = 300;
    private static ?string $dirOverride = null;
    private static ?string $dateBucketForTests = null;

    public static function dir(): string
    {
        return self::$dirOverride ?? ROOT_PATH . '/storage/cache/html';
    }

    /**
     * 固定缓存键的日期桶（测试跨午夜场景用），传 null 恢复真实时钟。
     * @psalm-suppress PossiblyUnusedMethod 调用方在 tests/（不在 Psalm projectFiles 内）
     */
    public static function setDateBucketForTests(?string $bucket): void
    {
        self::$dateBucketForTests = $bucket;
    }

    /**
     * 重定向缓存目录（测试/维护脚本用），传 null 恢复默认。
     * @psalm-suppress PossiblyUnusedMethod 调用方在 tests/（不在 Psalm projectFiles 内）
     */
    public static function setDir(?string $dir): void
    {
        self::$dirOverride = $dir;
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

        try {
            self::$currentKey = self::buildKey();
            if (self::$currentGeneration !== settingModel()->htmlCacheGeneration()) return;
        } catch (Throwable $e) {
            // An unknown generation must not be treated as permission to serve an old cache.
            error_log('[HtmlCache] Cannot read cache generation: ' . $e->getMessage());
            return;
        }
        $file = self::pathForKey(self::$currentKey);

        $modified = is_file($file) ? @filemtime($file) : false;
        if ($modified !== false && (time() - $modified) < self::$ttl) {
            $cached = @file_get_contents($file);
            // Missing, unreadable or empty cache files must not replace a live response.
            if ($cached !== false && $cached !== '') {
                header('X-Cache: HIT');
                echo $cached;
                exit;
            }
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

        // v1.27 缓存安全分级：页面文档含缓存不安全的显示条件（如精确时间）时不落盘——
        // 同一 URL 的求值结果会随请求侧状态漂移，落盘即把错误分支冻结给所有访客。
        // class_exists 不触发自动加载：本文件被独立端点（blox_cache_api 等）单独 require 时
        // 构建器未加载，也就不可能出现过条件求值。
        if (class_exists('BloxDisplayConditions', false) && BloxDisplayConditions::pageCacheMustSkip()) {
            return;
        }

        try {
            if (self::$currentGeneration !== settingModel()->htmlCacheGeneration()) return;
        } catch (Throwable $e) {
            error_log('[HtmlCache] Cannot verify cache generation: ' . $e->getMessage());
            return;
        }

        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) return;
        $file = self::pathForKey(self::$currentKey);
        // Readers see the previous complete response or the new one, never a partial write.
        $temporary = @tempnam($dir, '.html-');
        if ($temporary === false) return;
        try {
            if (realpath(dirname($temporary)) !== realpath($dir)) return;
            if (@file_put_contents($temporary, $html, LOCK_EX) === strlen($html)) {
                @rename($temporary, $file);
            }
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }

        // 顺手小批量清理过期文件（1% 概率），避免专设 cron 也能让目录收敛
        if (mt_rand(1, 100) === 1) {
            self::pruneExpired(self::$ttl);
        }
    }

    private static bool $pendingInvalidation = false;

    /**
     * 数据变更后的整页缓存失效：事务中推迟到提交之后（同一事务只清一次），回滚则不清。
     * 提交前清缓存会让并发的匿名请求在提交前把旧页面重新写回缓存。
     */
    public static function invalidateAfterCommit(): void
    {
        if (!function_exists('db')) {
            self::invalidate();
            return;
        }
        if (self::$pendingInvalidation) {
            return;
        }
        self::$pendingInvalidation = true;
        db()->afterCommit(
            static function (): void {
                self::$pendingInvalidation = false;
                self::invalidate();
            },
            static function (): void {
                self::$pendingInvalidation = false;
            }
        );
    }

    /**
     * 清除缓存（全部或按 key 前缀）
     */
    public static function invalidate(?string $prefix = null): int
    {
        // Rotate before cleanup, even with no directory: an in-flight old render may write later.
        // Prefix limits file cleanup only; namespace invalidation is deliberately site-wide.
        // Settings do not yet exist during installation. Normal runtime uses the persistent stamp.
        if (function_exists('settingModel') && db()->tableExists('settings')) {
            settingModel()->rotateHtmlCacheGeneration();
        }
        $dir = self::dir();
        if (!is_dir($dir)) return 0;
        $count = 0;
        foreach (self::htmlFiles($dir) as $file) {
            if ($prefix !== null && strpos($file->getBasename(), $prefix) !== 0) continue;
            if (@unlink($file->getPathname())) $count++;
        }
        return $count;
    }

    /**
     * 小批量清理过期缓存文件。目录只写不删是缓存目录膨胀的另一半原因：
     * TTL 过期的文件永远不会被 start() 复用，却一直占着磁盘。
     * @psalm-suppress PossiblyUnusedReturnValue 删除计数供测试与维护脚本断言
     */
    public static function pruneExpired(?int $ttl = null, int $limit = 500): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) return 0;

        $ttl = ($ttl !== null && $ttl > 0) ? $ttl : self::$ttl;
        $cutoff = time() - $ttl;
        $count = 0;
        foreach (self::htmlFiles($dir) as $file) {
            if ($file->getMTime() >= $cutoff) continue;
            if (@unlink($file->getPathname())) $count++;
            if ($count >= $limit) break;
        }
        return $count;
    }

    /**
     * 惰性遍历缓存目录里的 *.html。glob() 会一次性分配整个文件名数组，
     * 目录里堆到几十万文件时既慢又吃内存。
     *
     * @return Generator<SplFileInfo>
     */
    private static function htmlFiles(string $dir): Generator
    {
        try {
            foreach (new DirectoryIterator($dir) as $file) {
                if (!$file->isFile()) continue;
                if (strtolower($file->getExtension()) !== 'html') continue;
                yield $file->getFileInfo();
            }
        } catch (Throwable $e) {
            return;
        }
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

        // 搜索类请求不缓存：关键词组合无限多，每个都会落一个缓存文件
        if (isset($_GET['keyword']) || isset($_GET['q']) || isset($_GET['s'])) return false;

        // 查询参数白名单：缓存 key 含完整 REQUEST_URI，utm_* / 爬虫随机参数 /
        // 恶意构造的查询串每个变体都会生成一个新文件，目录会无限增长
        // （cile.cn 生产站曾因此写满 30GB）。只放行前台真实使用的分页/筛选参数。
        static $allowedQueryKeys = ['slug', 'parent', 'cat', 'sort', 'page'];
        foreach (array_keys($_GET) as $key) {
            if (!in_array((string) $key, $allowedQueryKeys, true)) return false;
        }

        if (self::normalizedQuery() === null) return false;

        return true;
    }

    /**
     * 缓存键使用的规范化请求视图（路径 + 白名单参数排序/归一后的 query）。
     * query 为 null 表示参数不在白名单内——这类请求 isCacheable() 恒 false、永不落缓存。
     * 显示条件的 url/param 求值必须与本视图同源（外审 P1-1）：否则参数顺序互换、
     * page=001 与 page=1 会命中同一缓存文件却得出不同条件结果。
     *
     * @return array{path: string, query: array<string,string>|null}
     */
    public static function canonicalRequest(): array
    {
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        return ['path' => $path, 'query' => self::normalizedQuery()];
    }

    private static function buildKey(): string
    {
        $canonical = self::canonicalRequest();
        $query = $canonical['query'] ?? [];
        $uri = $canonical['path'];
        if ($query !== []) {
            $uri .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $lang = defined('SITE_LANG') ? SITE_LANG : (string)config('site_lang', 'zh-CN');
        $isMobile = self::isMobile() ? 'm' : 'd';
        // Match the settings snapshot used by this request, not a newer concurrent publication.
        self::$currentGeneration = (string) settingModel()->get('html_cache_generation', '');
        // 日期桶（外审 P1-2）：date 条件按天求值且属缓存安全级，键里不含日期时
        // 大 TTL 会让午夜前的渲染跨天继续命中；按天分桶保证天粒度条件永不陈旧。
        $dateBucket = self::$dateBucketForTests ?? date('Y-m-d');
        return md5(self::releaseNamespace() . '|' . self::$currentGeneration . '|' . $uri . '|' . $lang . '|' . $isMobile . '|' . $dateBucket);
    }

    /** @return array<string,string>|null null 表示参数值不应进入缓存 */
    private static function normalizedQuery(): ?array
    {
        return ProductCatalogRequest::normalizeCacheQuery($_GET);
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
        return self::isMobileClient();
    }

    /**
     * 缓存键的移动端判定，公开给显示条件的 device 键（v1.27）共用——
     * 两处判定必须同源，否则 device 条件的分支会被冻结进错误的缓存桶。
     */
    public static function isMobileClient(): bool
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return (bool)preg_match('/Mobile|Android|iPhone|iPad|iPod/i', $ua);
    }
}

// 便捷辅助
function htmlCacheStart(int $ttl = 300): void { HtmlCache::start($ttl); }
function htmlCacheEnd(): void { HtmlCache::end(); }
function htmlCacheInvalidate(?string $prefix = null): int { return HtmlCache::invalidate($prefix); }

// 失效钩子。独立 admin 端点（如 blox_cache_api.php）只 require 本文件而不加载
// 钩子系统——没有 add_action 时跳过注册即可：钩子只服务前台自动失效，
// 端点自己显式调 invalidate()。（无守卫时曾令清缓存端点 500）
/** @psalm-suppress ParadoxicalCondition 运行时按端点上下文判定：独立 admin 端点不加载 hooks.php，Psalm 的全项目视角看不见这种加载差异 */
if (!function_exists('add_action')) {
    return;
}
// 1) Model 基类的 create/update/delete 都会触发通用 data_changed
add_action('data_changed', function (string $table = '', $id = null, array $settings = []): void {
    // 黑名单：这些表写入不影响前台缓存，避免无谓清理
    static $skipTables = ['admin_logs', 'ai_logs', 'login_throttle', 'form_throttle'];
    if (in_array($table, $skipTables, true)) return;
    if ($table === 'settings' && !SettingModel::affectsPageCache($settings)) return;
    HtmlCache::invalidateAfterCommit();
});

// 2) 兼容老钩子（如果有插件还在用）
add_action('after_save_content', function (): void { HtmlCache::invalidate(); });
add_action('after_save_product', function (): void { HtmlCache::invalidate(); });
add_action('after_delete_content', function (): void { HtmlCache::invalidate(); });
add_action('after_delete_product', function (): void { HtmlCache::invalidate(); });
add_action('setting_saved', function (array $settings = []): void {
    if (SettingModel::affectsPageCache($settings)) HtmlCache::invalidateAfterCommit();
});
