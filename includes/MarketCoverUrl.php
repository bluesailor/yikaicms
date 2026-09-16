<?php

declare(strict_types=1);

final class MarketCoverUrl
{
    public static function accept(mixed $url, string $kind, string $slug, string $version): string
    {
        if (!is_string($url) || strlen($url) > 500 || !in_array($kind, ['theme', 'plugin', 'template'], true)
            || preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $slug) !== 1
            || preg_match('/^\d{1,3}\.\d{1,3}\.\d{1,4}$/D', $version) !== 1) return '';
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query)) return '';
        parse_str($query, $params);
        $hash = $params['hash'] ?? null;
        if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) return '';
        $expected = 'https://update.yikaicms.com/api/market/cover.php?' . http_build_query([
            'kind' => $kind, 'slug' => $slug, 'version' => $version, 'hash' => $hash,
        ], '', '&', PHP_QUERY_RFC3986);
        return $url === $expected ? $url : '';
    }
}
