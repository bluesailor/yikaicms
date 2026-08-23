<?php

declare(strict_types=1);

final class FormSubmissionToken
{
    public static function sign(string $slug, int $timestamp, string $secret): string
    {
        if ($slug === '' || $timestamp <= 0 || $secret === '') return '';
        return substr(hash_hmac('sha256', $slug . '|' . $timestamp, $secret), 0, 32);
    }

    public static function legacySign(int $timestamp, string $secret): string
    {
        if ($timestamp <= 0 || $secret === '') return '';
        return substr(hash_hmac('sha256', (string) $timestamp, $secret), 0, 16);
    }

    /** @psalm-suppress PossiblyUnusedMethod Public form endpoint loads this method through includes/functions.php. */
    public static function verify(
        string $slug,
        int $timestamp,
        string $signature,
        string $secret,
        bool $allowLegacy,
        int $maxAge = 0,
        ?int $now = null
    ): bool {
        if ($slug === '' || $timestamp <= 0 || $signature === '' || $secret === '') return false;
        $now ??= time();
        $age = $now - $timestamp;
        if ($age < 0 || ($maxAge > 0 && $age > $maxAge)) return false;
        if (hash_equals(self::sign($slug, $timestamp, $secret), $signature)) return true;
        return $allowLegacy && hash_equals(self::legacySign($timestamp, $secret), $signature);
    }
}
