<?php

declare(strict_types=1);

/** Shared catalog restrictions; never infer a paid tier from a download limit. */
final class MarketDownloadStatus
{
    public static function reason(array $item): string
    {
        $value = $item['locked_reason'] ?? '';
        if (!is_string($value)) return 'download_unavailable';
        $value = trim($value);
        if ($value !== '') return $value;
        return !empty($item['paid']) && empty($item['download_url']) ? 'module_missing' : '';
    }

    public static function message(string $reason, string $version = ''): string
    {
        return match ($reason) {
            '' => '',
            'rate_limited' => __('market_download_rate_limited'),
            'token_expired', 'invalid_token' => __('market_download_refresh'),
            'not_published', 'gone' => __('market_download_removed'),
            'origin_unknown' => __('market_origin_unknown'),
            'origin_changed' => __('market_origin_changed'),
            'cms_version_required' => __('plugin_locked_cms_version', ['version' => $version]),
            'php_version_required' => __('market_php_version_required', ['version' => $version]),
            'expired', 'license_expired' => __('plugin_locked_expired'),
            'domain_mismatch', 'site_mismatch' => __('plugin_locked_domain'),
            'module_missing', 'license_required' => __('plugin_locked_need_license'),
            default => __('market_download_unavailable'),
        };
    }

    public static function decorate(array $item): array
    {
        $reason = self::reason($item);
        $item['download_blocked'] = $reason !== '';
        $item['download_message'] = self::message($reason, (string) ($item[$reason === 'php_version_required' ? 'requires_php' : 'requires_cms'] ?? ''));
        if ($reason !== '') {
            $item['locked_reason'] = $reason;
            $item['download_url'] = '';
        }
        return $item;
    }

    public static function httpMessage(int $status): string
    {
        return match ($status) {
            401, 403 => __('market_download_refresh'),
            404, 410 => __('market_download_removed'),
            429 => __('market_download_rate_limited'),
            default => __('market_download_unavailable'),
        };
    }
}
