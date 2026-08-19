<?php

declare(strict_types=1);

/** 在线升级包的 RSA-SHA256 签名契约。 */
final class UpdatePackageSignature
{
    public static function canonical(string $version, string $hash): string
    {
        $version = trim($version);
        $hash = strtolower(trim($hash));
        if (preg_match('/^\d+\.\d+(?:\.\d+){0,2}$/', $version) !== 1) {
            throw new InvalidArgumentException('Invalid update version');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $hash) !== 1) {
            throw new InvalidArgumentException('Invalid update hash');
        }
        return $version . '|' . $hash;
    }

    public static function verify(string $version, string $hash, string $signature, string $publicKey): bool
    {
        if (!function_exists('openssl_verify') || trim($signature) === '' || trim($publicKey) === '') {
            return false;
        }
        $decoded = base64_decode($signature, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }
        try {
            $canonical = self::canonical($version, $hash);
        } catch (InvalidArgumentException) {
            return false;
        }
        return openssl_verify($canonical, $decoded, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
