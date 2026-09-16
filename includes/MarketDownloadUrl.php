<?php

declare(strict_types=1);

/** URL boundary only; the server validates grants and clients still verify package signatures. */
final class MarketDownloadUrl
{
    public const ENDPOINT = 'https://update.yikaicms.com/api/market/download.php';

    public static function isTokenUrl(string $url): bool
    {
        $prefix = self::ENDPOINT . '?token=';
        if (!str_starts_with($url, $prefix)) {
            return false;
        }
        $token = substr($url, strlen($prefix));
        return strlen($token) <= 4096
            && preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token) === 1;
    }
}
