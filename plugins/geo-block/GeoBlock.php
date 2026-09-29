<?php
declare(strict_types=1);

require_once __DIR__ . '/GeoBlockRanges.php';

/**
 * 地区访问限制：授权状态、设置读取、是否拦截的判定与拦截响应。
 *
 * 判定（decide）是纯函数：输入请求快照与设置，输出拦截与否及原因，便于测试与
 * 后台「当前访问会怎样」的自检。执行（enforce）由 main.php 挂在 init 钩子上——
 * 前台、API、表单提交等入口都会经过 init，且早于整页缓存命中（HtmlCache::start）；
 * 后台页面不触发 init，站长在大陆也能正常登录管理。
 */
final class GeoBlock
{
    public const MODULE = 'geo-block';
    public const SLUG = 'geo-block';
    /** 永远放行的入口：计划任务由服务器或外部定时服务调用，不是访客。 */
    private const ALWAYS_EXEMPT = ['/cron.php'];

    // ── 授权 ────────────────────────────────────────────────────────

    public static function supports(string $version): bool
    {
        $meta = json_decode((string) file_get_contents(__DIR__ . '/plugin.json'), true);
        $minimum = (string) ($meta['requires_cms'] ?? '');
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?$/D', $version) === 1
            && preg_match('/^\d+\.\d+\.\d+$/D', $minimum) === 1
            && version_compare($version, $minimum, '>=');
    }

    /** unsupported / inactive / unlicensed / ready（同 yikai-builder 的 BloxProAccess 口径）。 */
    public static function status(): string
    {
        if (!defined('CMS_VERSION') || !self::supports((string) CMS_VERSION)) {
            return 'unsupported';
        }
        if (!function_exists('isPluginAvailable') || !isPluginAvailable(self::SLUG)) {
            return 'inactive';
        }
        // 模块所有权在服务期结束后仍保留；只注册不购买不授予
        if (!function_exists('license_has_module') || !license_has_module(self::MODULE)) {
            return 'unlicensed';
        }
        return 'ready';
    }

    // ── 设置 ────────────────────────────────────────────────────────

    /** @return array{enabled:bool,action:string,redirect_url:string,messages:array<string,string>,allow_ips:string,exempt_paths:string,allow_admins:bool,cdn_header:bool} */
    public static function settings(): array
    {
        $get = static fn (string $key, string $default = ''): string => function_exists('config') ? (string) config($key, $default) : $default;
        $messages = json_decode($get('gb_messages', '{}'), true);
        return [
            'enabled' => $get('gb_enabled', '0') === '1',
            'action' => $get('gb_action', 'block') === 'redirect' ? 'redirect' : 'block',
            'redirect_url' => $get('gb_redirect_url'),
            'messages' => is_array($messages) ? array_filter($messages, 'is_string') : [],
            'allow_ips' => $get('gb_allow_ips'),
            'exempt_paths' => $get('gb_exempt_paths'),
            'allow_admins' => $get('gb_allow_admins', '1') === '1',
            'cdn_header' => $get('gb_cdn_header', '0') === '1',
        ];
    }

    /** 放行路径：每行一个，以 / 开头，按前缀匹配（最多 50 条）。 @return list<string> */
    public static function parsePaths(string $text): array
    {
        $paths = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '/' && strlen($line) <= 200 && !preg_match('/[\s<>"\']/', $line) && !in_array($line, $paths, true)) {
                $paths[] = $line;
            }
        }
        return array_slice($paths, 0, 50);
    }

    /** 跳转地址：只接受外站 http(s) 绝对地址（跳回本站会形成循环）。 */
    public static function validRedirect(string $url, string $currentHost): ?string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        return $host === '' || $host === strtolower(preg_replace('/:\d+$/', '', $currentHost) ?? '') ? null : $url;
    }

    // ── 判定 ────────────────────────────────────────────────────────

    /**
     * @param array{ip:string,path:string,cli?:bool,admin?:bool,server_addr?:string,remote_trusted?:bool,cdn_country?:string} $request
     * @param array<string,mixed> $settings self::settings() 的结构
     * @return array{block:bool,reason:string}
     */
    public static function decide(array $request, array $settings, bool $licensed, GeoBlockRanges $ranges): array
    {
        $allow = static fn (string $reason): array => ['block' => false, 'reason' => $reason];
        if (empty($settings['enabled'])) {
            return $allow('disabled');
        }
        if (!$licensed) {
            return $allow('unlicensed');
        }
        if (!empty($request['cli'])) {
            return $allow('cli');
        }
        $ip = trim((string) ($request['ip'] ?? ''));
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $allow('unknown_ip');
        }
        // 内网/回环/本机：服务器自身的请求（静态页生成器、计划任务）绝不能吃到 403
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || ($request['server_addr'] ?? '') === $ip) {
            return $allow('local');
        }
        if (!empty($settings['allow_admins']) && !empty($request['admin'])) {
            return $allow('admin');
        }
        $path = '/' . ltrim((string) ($request['path'] ?? '/'), '/');
        foreach (array_merge(self::ALWAYS_EXEMPT, self::parsePaths((string) ($settings['exempt_paths'] ?? ''))) as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $allow('path');
            }
        }
        $allowIps = trim((string) ($settings['allow_ips'] ?? ''));
        if ($allowIps !== '' && class_exists('ClientIpResolver')
            && ClientIpResolver::matchesAny($ip, ClientIpResolver::parseRules($allowIps)['rules'] ?? [])) {
            return $allow('allowlist');
        }
        // CDN 国家头只在请求确实来自可信代理时采信（否则任何人都能伪造）
        $country = strtoupper(trim((string) ($request['cdn_country'] ?? '')));
        if (!empty($settings['cdn_header']) && !empty($request['remote_trusted']) && preg_match('/^[A-Z]{2}$/', $country)) {
            return $country === 'CN' ? ['block' => true, 'reason' => 'cdn_country'] : $allow('cdn_country');
        }
        return $ranges->contains($ip) ? ['block' => true, 'reason' => 'mainland_ip'] : $allow('not_mainland');
    }

    /** 当前请求的快照（只读服务器变量与会话，不做任何输出）。 @return array<string,mixed> */
    public static function currentRequest(): array
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $trusted = false;
        if ($remote !== '' && class_exists('ClientIpResolver') && function_exists('config')) {
            $trusted = ClientIpResolver::matchesAny($remote, ClientIpResolver::parseRules((string) config('trusted_proxies', ''))['rules'] ?? []);
        }
        return [
            'ip' => function_exists('getClientIp') ? getClientIp() : $remote,
            'path' => (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/'),
            'cli' => PHP_SAPI === 'cli',
            'admin' => !empty($_SESSION['admin_id']),
            'server_addr' => (string) ($_SERVER['SERVER_ADDR'] ?? ''),
            'remote_trusted' => $trusted,
            'cdn_country' => (string) ($_SERVER['HTTP_CF_IPCOUNTRY'] ?? ''),
        ];
    }

    // ── 拦截响应 ─────────────────────────────────────────────────────

    public static function message(array $settings, string $lang): string
    {
        $custom = trim((string) ($settings['messages'][$lang] ?? ''));
        if ($custom !== '') {
            return mb_substr($custom, 0, 500);
        }
        return function_exists('__') ? __('gb_default_message') : 'This website is not available in mainland China.';
    }

    /** 403 提示页（独立页面，不依赖主题——主题渲染本身可能触发更多取数）。 */
    public static function blockedPage(string $siteName, string $message, string $lang, string $ip): string
    {
        $h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $ipLabel = function_exists('__') ? __('gb_page_your_ip') : 'Your IP';
        return '<!doctype html><html lang="' . $h($lang) . '"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
            . '<title>403 · ' . $h($siteName) . '</title><style>'
            . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f8fafc;color:#1f2937;'
            . 'font-family:system-ui,-apple-system,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}'
            . 'main{max-width:34rem;margin:1.5rem;padding:2.5rem 2rem;background:#fff;border-radius:1rem;box-shadow:0 10px 30px rgba(15,23,42,.08);text-align:center}'
            . 'h1{margin:0 0 .75rem;font-size:1.25rem}p{margin:0;line-height:1.7;color:#4b5563;white-space:pre-line}'
            . 'small{display:block;margin-top:1.5rem;color:#9ca3af;font-size:.75rem}</style></head><body><main>'
            . '<h1>' . $h($siteName) . '</h1><p>' . $h($message) . '</p>'
            . '<small>403 · ' . $h($ipLabel) . ' ' . $h($ip) . '</small></main></body></html>';
    }

    /**
     * main.php 的 init 回调：命中即输出并结束请求。
     * 参数只供测试注入（设置与授权），运行时一律读站点设置与真实授权。
     *
     * @param array<string,mixed>|null $settings
     */
    public static function enforce(?array $settings = null, ?bool $licensed = null): void
    {
        $settings ??= self::settings();
        if (empty($settings['enabled'])) {
            return;
        }
        $request = self::currentRequest();
        $decision = self::decide($request, $settings, $licensed ?? self::status() === 'ready', GeoBlockRanges::bundled());
        if (!$decision['block']) {
            return;
        }
        $lang = defined('SITE_LANG') ? (string) SITE_LANG : 'zh-CN';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $redirect = ($settings['action'] ?? 'block') === 'redirect' ? self::validRedirect((string) ($settings['redirect_url'] ?? ''), $host) : null;
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store, private');
            header('X-Robots-Tag: noindex');
            if ($redirect !== null) {
                header('Location: ' . $redirect, true, 302);
                exit;
            }
            http_response_code(403);
        }
        $message = self::message($settings, $lang);
        if (str_starts_with((string) $request['path'], '/api/')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['code' => 403, 'msg' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        // 站点名按当前语言取（多语言站点各语言可不同）
        $siteName = function_exists('configRawLang') ? trim((string) configRawLang('site_name', ''))
            : (function_exists('config') ? (string) config('site_name', '') : '');
        echo self::blockedPage($siteName !== '' ? $siteName : $host, $message, $lang, (string) $request['ip']);
        exit;
    }
}
