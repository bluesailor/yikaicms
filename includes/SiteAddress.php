<?php
/**
 * 站点地址（后台「站点URL」设置）与实际访问地址的比对。
 *
 * 设置页提示与站点体检共用这一份判定，不各写一份。
 *
 * 分工要说清楚：「站点URL」只决定**绝对地址**——canonical、sitemap、分享卡片、
 * 邮件里的链接，这些需要完整的「协议 + 域名」，放在反代或 CDN 后面时自动推导拿不准，
 * 必须由站长明确指定。站内链接与路由的**子目录前缀**则由 BasePath 自动推导、不看这个设置：
 * 否则设置一填错，后台地址跟着错，连后台都进不去改。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/BasePath.php';

final class SiteAddress
{
    /** 没填：绝对地址按当前访问地址自动生成，通常就是对的。 */
    public const EMPTY = 'empty';
    public const MATCH = 'match';
    /** 与当前访问地址不同：可能换了域名或目录，也可能经反代访问。只是提醒。 */
    public const MISMATCH = 'mismatch';
    /**
     * 填的是本机或内网地址，而当前从公网访问——制作时的地址跟着站点被带上了线。
     * 后果是 canonical、sitemap、分享卡片全部指向一个外人打不开的地址。
     */
    public const LOCAL_LEAK = 'local_leak';
    /** 取不到当前访问地址（命令行等）。 */
    public const UNKNOWN = 'unknown';

    /** 当前请求的「协议://主机[:端口] + 挂载前缀」；命令行下为空串。 */
    public static function current(): string
    {
        $host = is_string($_SERVER['HTTP_HOST'] ?? null) ? trim($_SERVER['HTTP_HOST']) : '';
        if ($host === '' || preg_match('/^[A-Za-z0-9.:\[\]-]+$/D', $host) !== 1) {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        return ($https ? 'https' : 'http') . '://' . strtolower($host) . BasePath::get();
    }

    /**
     * 本机或开发用地址：localhost、.test/.local/.yikai 后缀、IP。
     * 与站点体检里「开发地址不要求 HTTPS」的口径是同一个定义。
     */
    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));
        return $host === 'localhost'
            || str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.yikai')
            || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * 只比「主机[:端口] + 路径」，不比协议——经反代访问时请求常显示为 http，
     * 协议问题由站点体检的 HTTPS 项单独负责。
     *
     * @return array{status:string,configured:string,current:string}
     */
    public static function inspect(string $configured, string $current): array
    {
        $configured = rtrim(trim($configured), '/');
        $current = rtrim(trim($current), '/');
        $result = static fn(string $status): array => ['status' => $status, 'configured' => $configured, 'current' => $current];

        if ($configured === '') {
            return $result(self::EMPTY);
        }
        if ($current === '') {
            return $result(self::UNKNOWN);
        }
        $want = self::hostAndPath($configured);
        $have = self::hostAndPath($current);
        if ($want === null || $have === null) {
            return $result(self::MISMATCH);
        }
        if ($want === $have) {
            return $result(self::MATCH);
        }
        $wantHost = (string) parse_url('http://' . $want, PHP_URL_HOST);
        $haveHost = (string) parse_url('http://' . $have, PHP_URL_HOST);
        if (self::isLocalHost($wantHost) && !self::isLocalHost($haveHost)) {
            return $result(self::LOCAL_LEAK);
        }
        return $result(self::MISMATCH);
    }

    /** "https://Ex.com:8443/sub/" → "ex.com:8443/sub"；解析不了返回 null。 */
    private static function hostAndPath(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $host . $port . rtrim((string) ($parts['path'] ?? ''), '/');
    }
}
