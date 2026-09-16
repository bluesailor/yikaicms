<?php

declare(strict_types=1);

/** Site-owned receipt, moved with its resource so rollback restores provenance too. */
final class MarketInstallOrigin
{
    public const FILE = '.yikai-market-origin.json';

    public static function assertAllowed(string $root, string $kind, string $slug, string $origin): void
    {
        self::validate($kind, $slug, $origin);
        $target = rtrim($root, '/\\') . '/' . $slug;
        clearstatcache(true, $target);
        if (is_link($target) || (file_exists($target) && !is_dir($target))) {
            throw new RuntimeException('unsafe');
        }
        if (!is_dir($target) || $origin === 'local') return;
        $file = $target . '/' . self::FILE;
        clearstatcache(true, $file);
        $size = @filesize($file);
        $receipt = !is_link($file) && is_int($size) && $size > 0 && $size <= 4096
            ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($receipt) || ($receipt['kind'] ?? '') !== $kind
            || ($receipt['provider'] ?? '') !== 'update.yikaicms.com'
            || ($receipt['slug'] ?? '') !== $slug
            || !in_array($receipt['origin'] ?? '', ['official', 'community'], true)) {
            throw new RuntimeException('origin_unknown');
        }
        if ($receipt['origin'] !== $origin) throw new RuntimeException('origin_changed');
    }

    public static function write(string $directory, string $kind, string $slug, string $version, string $origin): bool
    {
        self::validate($kind, $slug, $origin);
        $file = $directory . '/' . self::FILE;
        if (!is_dir($directory) || is_link($directory) || is_link($file)) return false;
        $receipt = json_encode([
            'kind' => $kind, 'slug' => $slug, 'origin' => $origin,
            'provider' => $origin === 'local' ? '' : 'update.yikaicms.com', 'version' => $version,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return @file_put_contents($file, $receipt, LOCK_EX) === strlen($receipt);
    }

    public static function isReceiptPath(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if (strtolower(rtrim($part, '. ')) === self::FILE) return true;
        }
        return false;
    }

    private static function validate(string $kind, string $slug, string $origin): void
    {
        if (!in_array($kind, ['theme', 'plugin'], true)
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $slug) !== 1
            || !in_array($origin, ['official', 'community', 'local'], true)) {
            throw new RuntimeException('invalid');
        }
    }
}
