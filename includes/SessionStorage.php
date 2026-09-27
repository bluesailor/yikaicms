<?php
/**
 * 会话存不下时的兜底。
 *
 * config.php 对每个请求都 session_start()，用的是 PHP 默认的会话目录。有些主机（常见于面板
 * 默认配置、换过 PHP 版本、权限收紧的共享主机）这个目录不可写：session_start() 失败，
 * 每个请求都拿到一个空会话。安装向导把表单暂存在浏览器里，照样能装完；可到了后台登录，
 * 登录页的 CSRF 令牌存不下来，提交时必然是 403「非法请求」——刚装好就登不进后台。
 *
 * 这里在 functions.php 最开头检查：config 里的 session_start() 没成功时，改用站点自己的
 * storage/sessions（storage/ 整个目录禁止网页访问）再开一次。不改 config.php，已装好的站也生效。
 */

declare(strict_types=1);

final class SessionStorage
{
    public static function recover(string $root): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_NONE || headers_sent()
            || !defined('SESSION_NAME') || (string) ini_get('session.save_handler') !== 'files') {
            return;
        }
        $fallback = $root . '/storage/sessions';
        if (!is_dir($fallback) && !@mkdir($fallback, 0700, true) && !is_dir($fallback)) {
            return;
        }
        if (!is_writable($fallback)) {
            return;
        }
        if (session_save_path($fallback) === false) {
            return;
        }
        // 有的发行版把 gc_probability 设为 0、靠系统计划任务只清理默认目录；自己的目录要自己回收
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        session_name(SESSION_NAME);
        @session_start([
            'cookie_httponly' => true,
            'cookie_secure' => self::isHttps($_SERVER),
            'cookie_samesite' => 'Lax',
        ]);
    }

    /** 与 config.php 的判定一致：直连 HTTPS / 443 端口 / 反代 X-Forwarded-Proto=https。 */
    public static function isHttps(array $server): bool
    {
        return (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
            || ((int) ($server['SERVER_PORT'] ?? 0) === 443)
            || (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    }
}
