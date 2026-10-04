<?php

declare(strict_types=1);

/**
 * 插件市场下载前的本地边界：地址只认官方包路径或市场令牌、包体积有上限、不装旧版本。
 * 包字节仍由 sha256 + RSA 签名把关；这里拦的是签名之前的网络请求与降级。
 *
 * 官方包路径（2.0.4 起含 OSS）：
 * - https://update.yikaicms.com/packages/plugins/<slug>-v<版本>.zip（老渠道，长期保留）
 * - https://down.yikai.cn/soft/yikaicms/plugins/<slug>-v<版本>.zip（OSS 公开对象，不带查询）
 * - https://down.yikai.cn/soft/yikaicms/plugins-pro/<slug>-v<版本>.zip?<OSS 签名参数>（私有对象的限时签名地址，
 *   只接受完整的 V1 或 V4 签名参数集，不接受其它查询）
 */
final class PluginMarketPackage
{
    public const MAX_PACKAGE_BYTES = 20 * 1024 * 1024;
    public const OSS_HOST = 'down.yikai.cn';
    public const OSS_PUBLIC_DIR = '/soft/yikaicms/plugins/';
    public const OSS_PRIVATE_DIR = '/soft/yikaicms/plugins-pro/';

    /** OSS 签名地址的参数集：必需键 => 可选键。 */
    private const OSS_SIGNATURES = [
        [['Expires', 'OSSAccessKeyId', 'Signature'], ['security-token']],
        [['x-oss-signature-version', 'x-oss-credential', 'x-oss-date', 'x-oss-expires', 'x-oss-signature'],
            ['x-oss-additional-headers', 'x-oss-security-token']],
    ];

    public static function isOfficialUrl(string $url, string $slug, string $version): bool
    {
        if (MarketDownloadUrl::isTokenUrl($url)) {
            return true;
        }
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $slug) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return false;
        }
        $host = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '');
        $file = $slug . '-v' . $version . '.zip';
        if ($host === 'update.yikaicms.com') {
            return !isset($parts['query']) && $path === '/packages/plugins/' . $file;
        }
        if ($host !== self::OSS_HOST) {
            return false;
        }
        if ($path === self::OSS_PUBLIC_DIR . $file) {
            return !isset($parts['query']);
        }
        return $path === self::OSS_PRIVATE_DIR . $file && self::isOssSignature((string) ($parts['query'] ?? ''));
    }

    /** 查询串必须恰好是一套 OSS 签名参数（V1 或 V4），每个键只出现一次、值为签名常见字符。 */
    private static function isOssSignature(string $query): bool
    {
        if ($query === '' || strlen($query) > 4096) {
            return false;
        }
        $keys = [];
        foreach (explode('&', $query) as $pair) {
            $kv = explode('=', $pair, 2);
            if (count($kv) !== 2 || isset($keys[$kv[0]])
                || preg_match('/^[A-Za-z0-9._~%+\/=-]{1,2048}$/D', $kv[1]) !== 1) {
                return false;
            }
            $keys[$kv[0]] = true;
        }
        foreach (self::OSS_SIGNATURES as [$required, $optional]) {
            $missing = array_diff($required, array_keys($keys));
            $extra = array_diff(array_keys($keys), $required, $optional);
            if ($missing === [] && $extra === []) {
                return true;
            }
        }
        return false;
    }

    /** 已安装且市场版本更旧 → 拒绝（同版本允许重装修复）。 */
    public static function isDowngrade(string $installedVersion, string $marketVersion): bool
    {
        return $installedVersion !== ''
            && preg_match('/^\d+(\.\d+)*$/D', $installedVersion) === 1
            && version_compare($marketVersion, $installedVersion, '<');
    }

    public static function installedVersion(string $pluginsRoot, string $slug): string
    {
        $manifest = rtrim($pluginsRoot, '/\\') . '/' . $slug . '/plugin.json';
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $slug) !== 1 || !is_file($manifest)) {
            return '';
        }
        $data = json_decode((string) @file_get_contents($manifest), true);
        return is_array($data) && is_string($data['version'] ?? null) ? trim($data['version']) : '';
    }
}
