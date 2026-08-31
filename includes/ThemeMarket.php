<?php

declare(strict_types=1);

/** 官方模板市场的只读目录与本地版本比较。 */
final class ThemeMarket
{
    public const API = 'https://update.yikaicms.com/api/themes/list.php';

    /**
     * @param null|callable(string):(?string) $transport 测试注入；生产环境固定请求官方地址
     * @return null|array{code:int,msg?:string,data:array{updated_at:string,themes:list<array<string,mixed>>}}
     */
    public static function request(string $query = '', ?callable $transport = null): ?array
    {
        $url = self::API . ($query !== '' ? '?q=' . rawurlencode($query) : '');
        $body = $transport !== null ? $transport($url) : self::httpGet($url);
        if (!is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || (int) ($decoded['code'] ?? 1) !== 0
            || !is_array($decoded['data'] ?? null) || !is_array($decoded['data']['themes'] ?? null)) {
            return null;
        }

        $themes = [];
        $seen = [];
        foreach ($decoded['data']['themes'] as $theme) {
            if (!is_array($theme)) {
                continue;
            }
            $slug = trim((string) ($theme['slug'] ?? ''));
            $version = trim((string) ($theme['version'] ?? ''));
            if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', $slug) !== 1
                || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1
                || trim((string) ($theme['name'] ?? '')) === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $theme['slug'] = $slug;
            $theme['version'] = $version;
            $themes[] = $theme;
        }

        return [
            'code' => 0,
            'data' => [
                'updated_at' => (string) ($decoded['data']['updated_at'] ?? ''),
                'themes' => $themes,
            ],
        ];
    }

    public static function downloadPackage(string $url, int $timeout = 120): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'update.yikaicms.com'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || preg_match('#^/packages/themes/[a-z0-9][a-z0-9.\-]*\.zip$#D', (string) ($parts['path'] ?? '')) !== 1) {
            return null;
        }
        return self::httpGet($url, $timeout);
    }

    /** @return array<string,string> slug => version */
    public static function localVersions(string $themesRoot): array
    {
        $versions = [];
        if (!is_dir($themesRoot)) {
            return $versions;
        }
        foreach ((array) scandir($themesRoot) as $slug) {
            if (!is_string($slug) || preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', $slug) !== 1) {
                continue;
            }
            $path = rtrim($themesRoot, '/\\') . '/' . $slug . '/theme.json';
            $meta = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
            $version = is_array($meta) ? trim((string) ($meta['version'] ?? '')) : '';
            if (preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1) {
                $versions[$slug] = $version;
            }
        }
        ksort($versions);
        return $versions;
    }

    /**
     * @param array<string,string> $localVersions
     * @param list<array<string,mixed>> $marketThemes
     * @return list<array{slug:string,name:string,name_en:string,name_ja:string,current_version:string,latest_version:string}>
     */
    public static function availableUpdates(array $localVersions, array $marketThemes): array
    {
        $updates = [];
        foreach ($marketThemes as $theme) {
            $slug = (string) ($theme['slug'] ?? '');
            $latest = (string) ($theme['version'] ?? '');
            $current = $localVersions[$slug] ?? '';
            if ($current === '' || preg_match('/^\d+\.\d+\.\d+$/D', $latest) !== 1
                || !version_compare($latest, $current, '>')) {
                continue;
            }
            $updates[] = [
                'slug' => $slug,
                'name' => (string) ($theme['name'] ?? $slug),
                'name_en' => (string) ($theme['name_en'] ?? $theme['name'] ?? $slug),
                'name_ja' => (string) ($theme['name_ja'] ?? $theme['name'] ?? $slug),
                'current_version' => $current,
                'latest_version' => $latest,
            ];
        }
        return $updates;
    }

    private static function httpGet(string $url, int $timeout = 15): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return is_string($response) && $response !== '' && $status >= 200 && $status < 300 ? $response : null;
        }
        if (!(bool) ini_get('allow_url_fopen')) {
            return null;
        }
        $context = stream_context_create([
            'http' => ['timeout' => $timeout, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = is_array($http_response_header ?? null) ? (string) ($http_response_header[0] ?? '') : '';
        return is_string($response) && $response !== '' && preg_match('#\s2\d\d\s#', $statusLine) === 1 ? $response : null;
    }
}
