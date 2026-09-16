<?php

declare(strict_types=1);

/** Envelope negotiation for the fixed official theme and plugin APIs only. */
final class MarketCatalogRequest
{
    public static function query(string $search = ''): string
    {
        return http_build_query([
            'protocol_version' => 2,
            'q' => $search,
            'cms_version' => defined('CMS_VERSION') ? (string) CMS_VERSION : '',
            'php_version' => PHP_VERSION,
            'key' => function_exists('license_key') ? license_key() : '',
            'domain' => function_exists('license_domain') ? license_domain() : '',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function decode(?string $body, string $list): ?array
    {
        if ($body === null || !in_array($list, ['themes', 'plugins'], true)) return null;
        $data = json_decode($body, true);
        if (!is_array($data) || ($data['code'] ?? null) !== 0
            || !is_array($data['data'] ?? null)
            || !is_array($data['data'][$list] ?? null)) return null;
        if (!array_key_exists('protocol_version', $data['data'])) {
            return self::legacyOfficial($data, $list);
        }
        if ($data['data']['protocol_version'] !== 2) return null;
        return $data;
    }

    /**
     * Pre-v2 official endpoints omit protocol_version altogether. Adapt that one
     * response, never retry another endpoint after a v2 denial or download error.
     * Package integrity/signature and installed-origin checks still run at install.
     */
    private static function legacyOfficial(array $data, string $list): array
    {
        $items = [];
        foreach ($data['data'][$list] as $item) {
            if (!is_array($item) || ($item['source'] ?? 'official') !== 'official') continue;
            $slug = $item['slug'] ?? null;
            $version = $item['version'] ?? null;
            if (!is_string($slug) || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $slug) !== 1
                || !is_string($version) || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1) continue;
            $item['source'] = 'official';
            $reason = $item['locked_reason'] ?? '';
            if (!is_string($reason)) continue;
            if ($reason !== '' || (!empty($item['paid']) && empty($item['download_url']))) {
                $item['locked_reason'] = $reason !== '' ? $reason : 'module_missing';
                $item['download_url'] = '';
                unset($item['hash'], $item['sig'], $item['size_kb']);
            } else {
                $package = $slug . '-v' . $version . '.zip';
                if (($item['package'] ?? '') !== $package
                    || ($item['download_url'] ?? '') !== 'https://update.yikaicms.com/packages/' . $list . '/' . $package
                    || !is_string($item['hash'] ?? null)
                    || preg_match('/^sha256:[a-f0-9]{64}$/Di', $item['hash']) !== 1
                    || !is_string($item['sig'] ?? null)
                    || ($signature = base64_decode($item['sig'], true)) === false || $signature === '') continue;
            }
            $items[] = $item;
        }
        $data['data'][$list] = $items;
        $data['data']['protocol_version'] = 1;
        // Old APIs do not implement the new quota ledger; do not fabricate v2 status.
        unset($data['data']['market']);
        return $data;
    }
}
