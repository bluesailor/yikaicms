<?php

declare(strict_types=1);

/**
 * 插件市场下载前的本地边界：地址只认官方包路径或市场令牌、包体积有上限、不装旧版本。
 * 包字节仍由 sha256 + RSA 签名把关；这里拦的是签名之前的网络请求与降级。
 */
final class PluginMarketPackage
{
    public const MAX_PACKAGE_BYTES = 20 * 1024 * 1024;

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
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'update.yikaicms.com'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && (string) ($parts['path'] ?? '') === '/packages/plugins/' . $slug . '-v' . $version . '.zip';
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
