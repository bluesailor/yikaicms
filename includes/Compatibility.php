<?php
/**
 * Yikai CMS - 兼容性兜底层（参考 Bricks Builder 的 compatibility.php 模式）
 *
 * 把跨环境/跨插件 quirks 集中一处：
 *   - 反向代理 / CDN：纠正 HTTPS 与客户端 IP 检测
 *   - 输出层：避免 BOM、output buffering 与 JSON 端点冲突
 *   - 环境探测：缺关键扩展时给清晰提示
 *   - 插件协作：apply_filters('yikaicms/compat/{plugin}') 让扩展自注册修正
 *
 * 由 init.php / admin auth.php 在钩子加载后调用 Compatibility::bootstrap() 一次。
 */

declare(strict_types=1);

class Compatibility
{
    private static bool $bootstrapped = false;

    /** @var array<string, list<string>> 检测到的问题，便于诊断页展示 */
    private static array $diagnostics = [];

    public static function bootstrap(): void
    {
        if (self::$bootstrapped) return;
        self::$bootstrapped = true;

        self::fixReverseProxyHttps();
        self::fixClientIp();
        self::checkRequiredExtensions();
        self::checkSafeWriteDirs();

        // 让插件 / 主题自定义 quirks
        do_action('yikaicms/compat/bootstrap');
    }

    // ─────────────────────────────────────────────────────
    // 反向代理 HTTPS 检测
    // 当站点被 Cloudflare/nginx 反代时，PHP 看到的是 http，
    // 导致 SITE_URL、cookie secure 标志、重定向链接都错位。
    // ─────────────────────────────────────────────────────
    private static function fixReverseProxyHttps(): void
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return;

        $hints = [
            ['HTTP_X_FORWARDED_PROTO',     'https'],
            ['HTTP_X_FORWARDED_SSL',       'on'],
            ['HTTP_CF_VISITOR',            '"scheme":"https"'], // Cloudflare
            ['HTTP_FRONT_END_HTTPS',       'on'],
            ['HTTP_X_FORWARDED_SCHEME',    'https'],
        ];
        foreach ($hints as [$key, $needle]) {
            if (isset($_SERVER[$key]) && stripos((string)$_SERVER[$key], $needle) !== false) {
                $_SERVER['HTTPS'] = 'on';
                $_SERVER['SERVER_PORT'] = '443';
                self::$diagnostics['reverse_proxy_https'][] = "Detected via {$key}";
                return;
            }
        }
    }

    // ─────────────────────────────────────────────────────
    // 真实客户端 IP（用于限流、日志）
    // CF / nginx 后 REMOTE_ADDR 是代理 IP，需要从 header 取。
    // ─────────────────────────────────────────────────────
    private static function fixClientIp(): void
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
        ];
        foreach ($candidates as $h) {
            if (empty($_SERVER[$h])) continue;
            $ip = trim(explode(',', (string)$_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                // 保留原值，业务代码用 Compatibility::clientIp() 拿到统一结果
                self::$diagnostics['client_ip_source'] = [$h];
                $_SERVER['_REAL_REMOTE_ADDR'] = $ip;
                return;
            }
        }
    }

    /**
     * 业务代码统一用此函数，免去散落的 X-Forwarded-For 解析。
     */
    public static function clientIp(): string
    {
        return (string)($_SERVER['_REAL_REMOTE_ADDR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    // ─────────────────────────────────────────────────────
    // 关键扩展检测
    // ─────────────────────────────────────────────────────
    private static function checkRequiredExtensions(): void
    {
        $required = ['curl', 'mbstring', 'openssl', 'json', 'pdo'];
        $missing = [];
        foreach ($required as $ext) {
            if (!extension_loaded($ext)) $missing[] = $ext;
        }
        if ($missing !== []) {
            self::$diagnostics['missing_extensions'] = $missing;
        }
    }

    // ─────────────────────────────────────────────────────
    // 关键可写目录检测
    // ─────────────────────────────────────────────────────
    private static function checkSafeWriteDirs(): void
    {
        if (!defined('ROOT_PATH')) return;
        $dirs = [
            ROOT_PATH . '/storage/cache',
            ROOT_PATH . '/storage/logs',
            ROOT_PATH . '/uploads',
        ];
        $unwritable = [];
        foreach ($dirs as $d) {
            if (is_dir($d) && !is_writable($d)) $unwritable[] = $d;
        }
        if ($unwritable !== []) {
            self::$diagnostics['unwritable_dirs'] = $unwritable;
        }
    }

    // ─────────────────────────────────────────────────────
    // JSON 端点保护：返回前清空非预期输出（BOM、PHP warning、空白）。
    // 由 admin/api_*.php 在 echo json 前调用。
    // ─────────────────────────────────────────────────────
    public static function flushBeforeJson(): void
    {
        while (ob_get_level() > 0) {
            $buf = ob_get_clean();
            // 保留诊断方便调试，但不混进 JSON
            if ($buf !== false && trim($buf) !== '') {
                self::$diagnostics['flushed_json_noise'][] = mb_substr($buf, 0, 200);
            }
        }
    }

    // ─────────────────────────────────────────────────────
    // 演示模式判定（统一入口；老代码散落在多个文件里）。
    // ─────────────────────────────────────────────────────
    public static function isDemoMode(): bool
    {
        return defined('DEMO_MODE') && DEMO_MODE;
    }

    /**
     * 拦截写操作的统一钩子；在控制器 POST 入口处调用。
     * @param array<string> $allowList 不被拦截的脚本名，默认空
     */
    public static function blockWriteIfDemo(array $allowList = []): void
    {
        if (!self::isDemoMode()) return;
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (in_array($script, $allowList, true)) return;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            if (function_exists('error')) {
                error('演示模式下不允许修改操作');
            }
            http_response_code(403);
            exit('Demo mode: writes blocked');
        }
    }

    public static function diagnostics(): array
    {
        return self::$diagnostics;
    }
}
