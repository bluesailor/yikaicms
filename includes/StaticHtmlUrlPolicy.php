<?php

declare(strict_types=1);

/** Static generation may only fetch the configured site's origin. */
final class StaticHtmlUrlPolicy
{
    public static function baseUrl(string $candidate, string $siteUrl): string
    {
        $site = self::parse($siteUrl);
        $target = self::parse(trim($candidate) === '' ? $siteUrl : $candidate);
        if ($site === null || $target === null
            || self::origin($site) !== self::origin($target)
            || rtrim($target['path'] ?? '', '/') !== rtrim($site['path'] ?? '', '/')) {
            throw new InvalidArgumentException('Static HTML base URL must match the configured site origin and path.');
        }
        return self::origin($target) . rtrim($target['path'] ?? '', '/');
    }

    public static function allowsRequest(string $url, string $baseUrl): bool
    {
        $target = self::parse($url);
        $base = self::parse($baseUrl);
        if ($target === null || $base === null || self::origin($target) !== self::origin($base)) {
            return false;
        }
        $prefix = rtrim($base['path'] ?? '', '/') . '/';
        return str_starts_with($target['path'] ?? '/', $prefix);
    }

    /** @return array{scheme:string,host:string,port?:int,path:string}|null */
    private static function parse(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || preg_match('/[\\x00-\\x20\\x7f]/', $url) || str_contains($url, '\\')) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || str_contains($parts['host'], '%')) {
            return null;
        }
        $path = $parts['path'] ?? '';
        $decoded = rawurldecode($path);
        // Keep encoded multilingual slugs, but reject encoded traversal and double decoding.
        if (preg_match('/%(?![a-f0-9]{2})/i', $path) || preg_match('/[\\x00-\\x20\\x7f%]/', $decoded)
            || str_contains($decoded, '\\') || str_contains($decoded, '//')
            || preg_match('~(?:^|/)\\.{1,2}(?:/|$)~', $decoded)) {
            return null;
        }
        $result = ['scheme' => strtolower($parts['scheme']), 'host' => strtolower($parts['host']), 'path' => $path];
        if (isset($parts['port'])) $result['port'] = $parts['port'];
        return $result;
    }

    /** @param array{scheme:string,host:string,port?:int,path:string} $parts */
    private static function origin(array $parts): string
    {
        $defaultPort = $parts['scheme'] === 'https' ? 443 : 80;
        $port = $parts['port'] ?? $defaultPort;
        return $parts['scheme'] . '://' . $parts['host'] . ($port === $defaultPort ? '' : ':' . $port);
    }
}
