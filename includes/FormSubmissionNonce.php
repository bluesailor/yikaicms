<?php

declare(strict_types=1);

/**
 * Session-backed, one-time form nonce.
 *
 * The token itself never appears in cacheable page HTML. Issuing and consuming both
 * run while PHP owns the session lock, so two processes cannot consume one nonce.
 */
final class FormSubmissionNonce
{
    private const SESSION_KEY = 'form_submission_nonces_v2';
    private const TTL = 900;
    private const MAX_ACTIVE = 64;

    /** @psalm-suppress PossiblyUnusedMethod Public nonce endpoint calls this method outside Psalm's source set. */
    public static function issue(string $slug, string $secret, ?int $now = null): string
    {
        if (!self::validSlug($slug) || $secret === '') return '';
        $now ??= time();
        self::prune($now);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $_SESSION[self::SESSION_KEY][self::key($token, $secret)] = [
            'slug' => $slug,
            'expires' => $now + self::TTL,
        ];
        while (count($_SESSION[self::SESSION_KEY]) > self::MAX_ACTIVE) {
            array_shift($_SESSION[self::SESSION_KEY]);
        }
        return $token;
    }

    /** A found token is always burned, including a token presented for another form. */
    public static function consume(string $slug, string $token, string $secret, ?int $now = null): bool
    {
        if (!self::validSlug($slug) || $token === '' || $secret === '') return false;
        $now ??= time();
        self::prune($now);
        $key = self::key($token, $secret);
        $record = $_SESSION[self::SESSION_KEY][$key] ?? null;
        if (!is_array($record)) return false;
        unset($_SESSION[self::SESSION_KEY][$key]);
        return ($record['slug'] ?? '') === $slug && (int) ($record['expires'] ?? 0) >= $now;
    }

    private static function prune(int $now): void
    {
        $records = $_SESSION[self::SESSION_KEY] ?? [];
        if (!is_array($records)) $records = [];
        foreach ($records as $key => $record) {
            if (!is_array($record) || (int) ($record['expires'] ?? 0) < $now) unset($records[$key]);
        }
        $_SESSION[self::SESSION_KEY] = $records;
    }

    private static function key(string $token, string $secret): string
    {
        return hash_hmac('sha256', 'form-nonce|' . $token, $secret);
    }

    private static function validSlug(string $slug): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]{1,100}$/D', $slug) === 1;
    }
}
