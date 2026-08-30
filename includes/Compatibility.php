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

require_once __DIR__ . '/ClientIpResolver.php';

class Compatibility
{
    private static bool $bootstrapped = false;

    /** @var array<string, list<string>> 检测到的问题，便于诊断页展示 */
    private static array $diagnostics = [];

    public static function bootstrap(): void
    {
        if (self::$bootstrapped) return;
        self::$bootstrapped = true;

        self::initDemoMode();
        self::fixReverseProxyHttps();
        self::fixClientIp();
        self::checkRequiredExtensions();
        self::checkSafeWriteDirs();

        // 让插件 / 主题自定义 quirks
        do_action('yikaicms/compat/bootstrap');
    }

    // ─────────────────────────────────────────────────────
    // 演示模式：从 yikai_settings.demo_mode 读取并定义 DEMO_MODE 常量。
    // 老路径仍兼容在 config.php 中 define('DEMO_MODE', true)。
    // ─────────────────────────────────────────────────────
    private static function initDemoMode(): void
    {
        // demo_mode：0 关 / 1 只读（拦所有写）/ 2 沙盒（可写，按快照定时重置，见 DemoSandbox）
        $mode = '0';
        try {
            $mode = (string)config('demo_mode', '0');
        } catch (\Throwable $e) {
        }
        if (!defined('DEMO_MODE')) {
            define('DEMO_MODE', $mode === '1');
        }
        if (!defined('DEMO_SANDBOX')) {
            define('DEMO_SANDBOX', $mode === '2');
        }
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
        try {
            $trustedProxies = config('trusted_proxies', '');
        } catch (\Throwable $e) {
            $trustedProxies = '';
        }
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $resolved = ClientIpResolver::resolve($_SERVER, $trustedProxies);
        unset($_SERVER['_REAL_REMOTE_ADDR']);
        if ($resolved !== $remote) {
            $_SERVER['_REAL_REMOTE_ADDR'] = $resolved;
            self::$diagnostics['client_ip_source'] = ['trusted_proxy'];
        }
    }

    /**
     * 业务代码统一用此函数，免去散落的 X-Forwarded-For 解析。
     */
    public static function clientIp(): string
    {
        return (string) ($_SERVER['_REAL_REMOTE_ADDR'] ?? ClientIpResolver::resolve($_SERVER, []));
    }

    // ─────────────────────────────────────────────────────
    // 关键扩展检测
    // ─────────────────────────────────────────────────────
    private static function checkRequiredExtensions(): void
    {
        // 清单统一取自 RuntimeRequirements。此前这里写的是 curl/openssl 必需、
        // 却不查 fileinfo/dom——与安装器的必需项正好错开，两边谁也说不了算。
        require_once ROOT_PATH . '/includes/RuntimeRequirements.php';
        $missing = RuntimeRequirements::missingRequired();
        if ($missing !== []) {
            self::$diagnostics['missing_extensions'] = $missing;
        }
        $degraded = RuntimeRequirements::missingRecommended();
        if ($degraded !== []) {
            self::$diagnostics['degraded_extensions'] = $degraded;
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
                error(__('auth_demo_readonly'));
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
