<?php
declare(strict_types=1);

/**
 * 后台登录不了时的诊断（2.0.2）。
 *
 * 两处用它：
 *   1. 登录页：提交时会话里没有令牌（login_session_lost）→ classify() 判断是哪一端丢的会话，
 *      给访客一句对症的提示和诊断码；带服务器路径的细节只写站点日志，不回显给未登录的人。
 *   2. CLI `site:login-check`：像浏览器一样取两次登录页，analyze() 判断会话 Cookie 有没有下发、
 *      是否被缓存、Secure 标记与网址协议是否冲突、服务器有没有真正保存会话。不登录、不触发登录限流。
 *
 * 纯函数为主（classify / analyze / parseSetCookie / cacheHit / tokenFrom），便于单测；只有 storeWritable() 读文件系统。
 */
final class LoginDiagnostics
{
    /** 浏览器没有带回会话 Cookie：登录页被缓存、Secure 与协议冲突、网址前后不一致、浏览器拦 Cookie */
    public const NO_COOKIE = 'L1';
    /** Cookie 带回了，服务器却找不到这份会话：会话被清理、多台服务器不共享会话 */
    public const SESSION_GONE = 'L2';
    /** 服务器会话目录不可写，登录状态存不下 */
    public const STORE_UNWRITABLE = 'L3';
    /** 会话在、却从没拿到过登录令牌：浏览器看到的登录页不是本站为这个会话生成的（多为 CDN / 页面缓存） */
    public const PAGE_NOT_FROM_SESSION = 'L4';

    /**
     * @param array<string,mixed> $cookies
     * @param bool $sessionHadData 登录页脚本改动会话之前，会话里是否已有数据（有 = 服务器认得这个会话）
     */
    public static function classify(array $cookies, string $sessionName, bool $sessionHadData, ?bool $storeWritable): string
    {
        $sent = isset($cookies[$sessionName]) && is_string($cookies[$sessionName]) && $cookies[$sessionName] !== '';
        if (!$sent) return self::NO_COOKIE;
        if ($sessionHadData) return self::PAGE_NOT_FROM_SESSION;
        return $storeWritable === false ? self::STORE_UNWRITABLE : self::SESSION_GONE;
    }

    /** 每个诊断码对应的提示 key（三语都有） */
    public static function hintKey(string $code): string
    {
        return match ($code) {
            self::NO_COOKIE => 'login_diag_no_cookie',
            self::STORE_UNWRITABLE => 'login_diag_store_unwritable',
            self::PAGE_NOT_FROM_SESSION => 'login_diag_page_cached',
            default => 'login_diag_session_gone',
        };
    }

    /**
     * files 处理器的会话目录。save_path 可能是 "N;/path" 或 "N;MODE;/path"，取最后一段；为空时 PHP 用系统临时目录。
     * 其它处理器（redis、memcached…）返回 null：目录可写与否无从判断。
     */
    public static function storeDir(string $handler, string $savePath, string $tempDir): ?string
    {
        if ($handler !== 'files') return null;
        $parts = explode(';', $savePath);
        $dir = trim((string) end($parts));
        return $dir !== '' ? $dir : $tempDir;
    }

    /** 当前请求实际用的会话目录是否可写；判断不了时返回 null */
    public static function storeWritable(): ?bool
    {
        $dir = self::storeDir((string) ini_get('session.save_handler'), (string) session_save_path(), sys_get_temp_dir());
        if ($dir === null) return null;
        return is_dir($dir) && is_writable($dir);
    }

    /** 写进站点日志的一行细节（给服务器管理员；不含 Cookie 值与令牌） */
    public static function logLine(string $code, array $server, string $siteUrl): string
    {
        $handler = (string) ini_get('session.save_handler');
        $dir = self::storeDir($handler, (string) session_save_path(), sys_get_temp_dir());
        return sprintf(
            'Admin login session lost [%s]: handler=%s dir=%s writable=%s strict=%s https_detected=%s host=%s site_url=%s',
            $code,
            $handler,
            $dir ?? '-',
            $dir === null ? '-' : json_encode(is_dir($dir) && is_writable($dir)),
            (string) ini_get('session.use_strict_mode'),
            json_encode(SessionStorage::isHttps($server)),
            (string) ($server['HTTP_HOST'] ?? '-'),
            $siteUrl
        );
    }

    // ── CLI 探测 ────────────────────────────────────────────────────────────

    /**
     * 解析一条 Set-Cookie。
     * @return array{name:string,value:string,secure:bool,httponly:bool,domain:string,path:string,samesite:string}
     */
    public static function parseSetCookie(string $header): array
    {
        $parts = array_map('trim', explode(';', $header));
        [$name, $value] = array_pad(explode('=', (string) array_shift($parts), 2), 2, '');
        $cookie = ['name' => trim($name), 'value' => trim($value), 'secure' => false, 'httponly' => false, 'domain' => '', 'path' => '', 'samesite' => ''];
        foreach ($parts as $part) {
            [$key, $val] = array_pad(explode('=', $part, 2), 2, '');
            $key = strtolower(trim($key));
            if ($key === 'secure') $cookie['secure'] = true;
            elseif ($key === 'httponly') $cookie['httponly'] = true;
            elseif (in_array($key, ['domain', 'path', 'samesite'], true)) $cookie[$key] = trim($val);
        }
        return $cookie;
    }

    /**
     * 响应头显示命中了某层缓存（CDN、反代、面板页面缓存）时返回那条头，否则空串。
     * @param array<string,list<string>> $headers 头名小写
     */
    public static function cacheHit(array $headers): string
    {
        foreach (['x-cache', 'cf-cache-status', 'x-proxy-cache', 'x-cache-status', 'x-fastcgi-cache', 'x-sg-cache', 'x-litespeed-cache'] as $name) {
            foreach ($headers[$name] ?? [] as $value) {
                if (preg_match('/\bHIT\b/i', $value)) return $name . ': ' . $value;
            }
        }
        foreach ($headers['age'] ?? [] as $value) {
            if ((int) $value > 0) return 'age: ' . $value;
        }
        return '';
    }

    /** 登录表单里的 CSRF 令牌 */
    public static function tokenFrom(string $html, string $field): string
    {
        return preg_match('/name="' . preg_quote($field, '/') . '"\s+value="([0-9a-f]{16,128})"/i', $html, $m) === 1 ? $m[1] : '';
    }

    /**
     * 两次取登录页的结果 → 逐项结论。第二次带着第一次拿到的会话 Cookie：
     * 令牌相同 = 服务器保存并读回了会话；令牌变了或又发新会话号 = 会话没存下（或被清理）。
     *
     * @param array{status:int,headers:array<string,list<string>>,body:string,url:string} $first
     * @param null|array{status:int,headers:array<string,list<string>>,body:string,url:string} $second
     * @return list<array{code:string,ok:bool,key:string,detail:string}>
     */
    public static function analyze(array $first, ?array $second, string $sessionName, string $tokenField): array
    {
        $checks = [];
        $checks[] = ['code' => 'http', 'ok' => $first['status'] === 200, 'key' => 'login_check_http', 'detail' => $first['url'] . ' → HTTP ' . $first['status']];
        if ($first['status'] !== 200) return $checks;

        $hit = self::cacheHit($first['headers']);
        $checks[] = ['code' => 'cache', 'ok' => $hit === '', 'key' => $hit === '' ? 'login_check_cache_ok' : 'login_check_cache_hit', 'detail' => $hit];

        $cookie = null;
        foreach ($first['headers']['set-cookie'] ?? [] as $line) {
            $parsed = self::parseSetCookie($line);
            if ($parsed['name'] === $sessionName) $cookie = $parsed;
        }
        $checks[] = ['code' => 'cookie', 'ok' => $cookie !== null, 'key' => $cookie !== null ? 'login_check_cookie_ok' : 'login_check_cookie_missing', 'detail' => $sessionName];
        if ($cookie === null) return $checks;

        $https = str_starts_with(strtolower($first['url']), 'https://');
        $secureClash = $cookie['secure'] && !$https;
        $checks[] = ['code' => 'secure', 'ok' => !$secureClash, 'key' => $secureClash ? 'login_check_secure_clash' : 'login_check_secure_ok',
            'detail' => ($cookie['secure'] ? 'Secure' : 'no Secure') . ' / ' . ($https ? 'https' : 'http')];

        $host = strtolower((string) parse_url($first['url'], PHP_URL_HOST));
        $domain = ltrim(strtolower($cookie['domain']), '.');
        $domainClash = $domain !== '' && $host !== $domain && !str_ends_with($host, '.' . $domain);
        $checks[] = ['code' => 'domain', 'ok' => !$domainClash, 'key' => $domainClash ? 'login_check_domain_clash' : 'login_check_domain_ok',
            'detail' => ($cookie['domain'] !== '' ? $cookie['domain'] : '(host only)') . ' / ' . $host];

        if ($second === null) return $checks;
        $renewed = false;
        foreach ($second['headers']['set-cookie'] ?? [] as $line) {
            $parsed = self::parseSetCookie($line);
            if ($parsed['name'] === $sessionName && $parsed['value'] !== '' && $parsed['value'] !== $cookie['value']) $renewed = true;
        }
        $token1 = self::tokenFrom($first['body'], $tokenField);
        $token2 = self::tokenFrom($second['body'], $tokenField);
        $kept = $second['status'] === 200 && !$renewed && $token1 !== '' && $token1 === $token2;
        $checks[] = ['code' => 'persist', 'ok' => $kept, 'key' => $kept ? 'login_check_persist_ok' : 'login_check_persist_lost',
            'detail' => $token1 === '' ? 'no token in form' : ($renewed ? 'new session id issued' : ($token1 === $token2 ? 'same token' : 'token changed'))];
        return $checks;
    }
}
