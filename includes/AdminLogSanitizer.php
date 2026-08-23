<?php

declare(strict_types=1);

final class AdminLogSanitizer
{
    private const REDACTED = '[REDACTED]';
    private const MAX_REQUEST_BYTES = 16384;

    /** @param array<string|int,mixed> $data */
    public static function requestData(array $data): string
    {
        $sanitized = self::sanitize($data);
        $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return '{}';
        }
        if (strlen($json) <= self::MAX_REQUEST_BYTES) {
            return $json;
        }
        return (string) json_encode([
            '_truncated' => true,
            'size' => strlen($json),
            'sha256' => hash('sha256', $json),
        ], JSON_UNESCAPED_SLASHES);
    }

    public static function url(string $uri): string
    {
        if ($uri === '' || preg_match('/[\x00-\x1F\x7F]/', $uri)) {
            return '';
        }
        $parts = parse_url($uri);
        if (!is_array($parts)) {
            return (string) (strstr($uri, '?', true) ?: '');
        }
        $result = '';
        if (isset($parts['scheme'])) {
            $result .= $parts['scheme'] . '://';
            if (isset($parts['user'])) {
                $result .= self::REDACTED . '@';
            }
            $result .= $parts['host'] ?? '';
            if (isset($parts['port'])) {
                $result .= ':' . $parts['port'];
            }
        }
        $result .= $parts['path'] ?? '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);
            $query = self::sanitize($query);
            $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            if ($encoded !== '') {
                $result .= '?' . $encoded;
            }
        }
        return $result;
    }

    private static function isSecretKey(string $key): bool
    {
        $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $key) ?? $key);
        if (preg_match('/(?:^|_)(?:password|passwd|passphrase|pass)(?:_|$)/', $normalized) === 1) {
            return true;
        }
        return preg_match(
            '/(?:^|_)(?:token|authorization|api_?key|license_?key|private_?key|secret(?:_key)?)$/',
            $normalized
        ) === 1;
    }

    private static function sanitize(mixed $value, string $key = ''): mixed
    {
        if ($key !== '' && self::isSecretKey($key)) {
            return self::REDACTED;
        }
        if (!is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $childKey => $childValue) {
            $result[$childKey] = self::sanitize($childValue, (string) $childKey);
        }
        return $result;
    }
}
