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

    /** 会话里记本站身份的键（值是 fingerprint()）。 */
    public const OWNER_KEY = '_yk_site';

    /**
     * 会话只属于本站（2.1.3）。
     *
     * 会话 cookie 名取自 config.php 的 SESSION_NAME，按站点随机生成；可整站复制出来的站点用的是
     * 同一份 config.php，名字相同。复制站放在同一域名的不同子目录、同一台服务器上时，path=/ 的
     * 会话 cookie 会发给每一个站，会话文件又在同一个目录——登录 A 站后台，打开 B 站后台就是已登录
     * （演示站几十个子站正是这种形态）。这里两道防线：
     *   ① 子目录站把会话 cookie 限在本站目录（config.php 用 path=/ 开的会话，同一会话号换参数重开）；
     *   ② 会话里记下属于哪个站（安装目录的指纹），不是本站的会话不认：留给原站，本站换新会话号重来。
     * 没记过站点的旧会话（升级前开的）直接认作本站并补记：在线升级是分批的多次请求，
     * 中途把旧会话判作别站会让管理员半路掉线、升级停在一半。子目录站认领时旧会话号随即作废，
     * 其他复制站拿着同一个根路径 cookie 只会得到空会话；会话一旦记上站点，之后就只属于那个站。
     */
    public static function isolate(string $root, string $base): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE || headers_sent()) {
            return;
        }
        $path = $base === '' ? '/' : $base . '/';
        $params = session_get_cookie_params();
        $rescoped = false;
        if ($base !== '' && $params['path'] !== $path) {
            session_write_close();
            session_set_cookie_params([
                'lifetime' => $params['lifetime'], 'path' => $path, 'domain' => $params['domain'],
                'secure' => $params['secure'], 'httponly' => $params['httponly'], 'samesite' => $params['samesite'],
            ]);
            if (@session_start() === false) {
                return;
            }
            $rescoped = true;
        }
        $mine = self::fingerprint($root);
        $owner = $_SESSION[self::OWNER_KEY] ?? null;
        if ($owner === $mine) {
            return;
        }
        $foreign = $owner !== null;
        if ($foreign) {
            // 别站的会话：先换号（不删旧号，原样留给它的站点），再清空本站的新会话——顺序反了会把空数据写回旧号
            @session_regenerate_id(false);
            $_SESSION = [];
        } elseif ($rescoped) {
            // 子目录站认领旧的根路径会话：数据搬到按本站目录发的新号，旧号删掉，别的复制站就认领不到了
            @session_regenerate_id(true);
        }
        $_SESSION[self::OWNER_KEY] = $mine;
    }

    /** 站点指纹：安装目录的真实路径。复制出来的站目录不同，指纹就不同；不含任何可猜的秘密。 */
    public static function fingerprint(string $root): string
    {
        $real = realpath($root);
        return substr(hash('sha256', 'yikaicms-session|' . ($real !== false ? $real : $root)), 0, 24);
    }

    /** 与 config.php 的判定一致：直连 HTTPS / 443 端口 / 反代 X-Forwarded-Proto=https。 */
    public static function isHttps(array $server): bool
    {
        return (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off')
            || ((int) ($server['SERVER_PORT'] ?? 0) === 443)
            || (strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
    }
}
