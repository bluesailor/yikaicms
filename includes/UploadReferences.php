<?php
declare(strict_types=1);

/** Shared uploads reference recognition for site-template export and maintenance tools. */
final class UploadReferences
{
    private const SLASH_ENTITY = '&(?:#0*47|#x0*2f|sol);';
    private const BACKSLASH_ENTITY = '&(?:#0*92|#x0*5c|bsol);';
    private const PATH_SEPARATOR = '(?:/|\\\\|' . self::SLASH_ENTITY . '|' . self::BACKSLASH_ENTITY . ')';
    private const PATTERN = '~(?<![a-zA-Z0-9/:.])' . self::PATH_SEPARATOR . '?uploads' . self::PATH_SEPARATOR . '([^\s"\'<>?#)]+)~iu';
    private const ORIGIN_URL_PATTERN = '~(?<![a-zA-Z0-9])((?:https?:)?(?://|(?:' . self::SLASH_ENTITY . '){2})[^\s"\'<>)]*)~iu';

    /** @return array<string,int> relative path => occurrence count */
    public static function collect(mixed $value, string $uploadsRoot = '', string $siteOrigin = ''): array
    {
        $refs = [];
        self::collectInto($value, $refs, self::normalizedRoot($uploadsRoot), self::normalizedOrigin($siteOrigin));
        return $refs;
    }

    /**
     * Rewrites only references recognized by collect(). JSON documents are decoded first so escaped
     * slashes and nested Blox values follow exactly the same recognition path as site-template export.
     *
     * @param array<string,string> $map relative source path => relative target path
     */
    public static function rewrite(mixed $value, array $map, int &$changes = 0, string $uploadsRoot = '', string $siteOrigin = ''): mixed
    {
        $uploadsRoot = self::normalizedRoot($uploadsRoot);
        $origin = self::normalizedOrigin($siteOrigin);
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::rewrite($item, $map, $changes, $uploadsRoot, $siteOrigin);
            }
            return $value;
        }
        if (!is_string($value) || $value === '') return $value;

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $rewritten = self::rewrite($decoded, $map, $changes, $uploadsRoot, $siteOrigin);
            if ($rewritten === $decoded) return $value;
            return json_encode($rewritten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $absolute = self::absoluteRelative($value, $uploadsRoot);
        if ($absolute !== null && isset($map[$absolute])) {
            $separator = str_contains($value, '\\') ? '\\' : '/';
            $target = $uploadsRoot . '/' . $map[$absolute];
            $changes++;
            return $separator === '\\' ? str_replace('/', '\\', $target) : $target;
        }

        if ($origin !== null) {
            $value = preg_replace_callback(self::ORIGIN_URL_PATTERN, static function (array $match) use ($map, &$changes, $origin): string {
                $url = self::sameOriginUrl((string) $match[1], $origin);
                if ($url === null || !str_starts_with($url['local_path'], '/uploads/')) return (string) $match[0];
                $relative = self::validRelative(substr($url['local_path'], 9));
                if ($relative === null || !isset($map[$relative])) return (string) $match[0];
                $target = '/uploads/' . self::encodeLike($relative, $map[$relative]);
                $changes++;
                return $url['prefix'] . $origin['base_path'] . $target . $url['suffix'];
            }, $value) ?? $value;
        }

        return self::transformOutsideUrls($value, static function (string $fragment) use ($map, &$changes): string {
            return preg_replace_callback(self::PATTERN, static function (array $match) use ($map, &$changes): string {
                $relative = self::validRelative((string) $match[1]);
                if ($relative === null || !isset($map[$relative])) return (string) $match[0];
                $prefixLength = strlen((string) $match[0]) - strlen((string) $match[1]);
                $prefix = substr((string) $match[0], 0, $prefixLength);
                $changes++;
                return $prefix . self::encodeLike((string) $match[1], $map[$relative]);
            }, $fragment) ?? $fragment;
        });
    }

    /** Convert the author's same-origin URLs to portable root-relative references. */
    public static function localize(mixed $value, string $siteOrigin, int &$changes = 0): mixed
    {
        $origin = self::normalizedOrigin($siteOrigin);
        if (is_array($value)) {
            foreach ($value as $key => $item) $value[$key] = self::localize($item, $siteOrigin, $changes);
            return $value;
        }
        if (!is_string($value) || $value === '' || $origin === null) return $value;

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $rewritten = self::localize($decoded, $siteOrigin, $changes);
            return $rewritten === $decoded ? $value
                : json_encode($rewritten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return preg_replace_callback(self::ORIGIN_URL_PATTERN, static function (array $match) use ($origin, &$changes): string {
            $url = self::sameOriginUrl((string) $match[1], $origin);
            if ($url === null) return (string) $match[0];
            $changes++;
            return $url['local_path'] . $url['suffix'];
        }, $value) ?? $value;
    }

    /** @param array<string,int> $refs @param array{scheme:string,host:string,port:int,base_path:string}|null $origin */
    private static function collectInto(mixed $value, array &$refs, string $uploadsRoot, ?array $origin): void
    {
        if (is_array($value)) {
            foreach ($value as $item) self::collectInto($item, $refs, $uploadsRoot, $origin);
            return;
        }
        if (!is_string($value) || $value === '') return;

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            self::collectInto($decoded, $refs, $uploadsRoot, $origin);
            return;
        }
        $absolute = self::absoluteRelative($value, $uploadsRoot);
        if ($absolute !== null) {
            $refs[$absolute] = ($refs[$absolute] ?? 0) + 1;
            return;
        }
        if ($origin !== null) {
            preg_match_all(self::ORIGIN_URL_PATTERN, $value, $originMatches, PREG_SET_ORDER);
            foreach ($originMatches as $match) {
                $url = self::sameOriginUrl((string) $match[1], $origin);
                if ($url === null || !str_starts_with($url['local_path'], '/uploads/')) continue;
                $relative = self::validRelative(substr($url['local_path'], 9));
                if ($relative !== null) $refs[$relative] = ($refs[$relative] ?? 0) + 1;
            }
        }
        foreach (self::outsideUrlFragments($value) as $fragment) {
            preg_match_all(self::PATTERN, $fragment, $matches);
            foreach ($matches[1] as $path) {
                $relative = self::validRelative((string) $path);
                if ($relative !== null) $refs[$relative] = ($refs[$relative] ?? 0) + 1;
            }
        }
    }

    /** Apply relative-path recognition only outside complete absolute/protocol-relative URL tokens. */
    private static function transformOutsideUrls(string $value, callable $transform): string
    {
        $matches = [];
        preg_match_all(self::ORIGIN_URL_PATTERN, $value, $matches, PREG_OFFSET_CAPTURE);
        $urls = $matches[1] ?? [];
        if ($urls === []) return $transform($value);
        $result = '';
        $offset = 0;
        foreach ($urls as $url) {
            $token = (string) $url[0];
            $start = (int) $url[1];
            $result .= $transform(substr($value, $offset, $start - $offset)) . $token;
            $offset = $start + strlen($token);
        }
        return $result . $transform(substr($value, $offset));
    }

    /** @return list<string> */
    private static function outsideUrlFragments(string $value): array
    {
        $fragments = [];
        self::transformOutsideUrls($value, static function (string $fragment) use (&$fragments): string {
            $fragments[] = $fragment;
            return $fragment;
        });
        return $fragments;
    }

    private static function encodeLike(string $source, string $target): string
    {
        $encoded = str_contains($source, '%') ? str_replace('%2F', '/', rawurlencode($target)) : $target;
        if (preg_match('~(?:/|\\\\|' . self::SLASH_ENTITY . '|' . self::BACKSLASH_ENTITY . ')~iu', $source, $match) !== 1) return $encoded;
        $separator = (string) $match[0];
        return $separator === '/' ? $encoded : str_replace('/', $separator, $encoded);
    }

    private static function normalize(string $path): string
    {
        $path = preg_replace('~' . self::SLASH_ENTITY . '~iu', '/', $path) ?? $path;
        $path = preg_replace('~' . self::BACKSLASH_ENTITY . '~iu', '\\', $path) ?? $path;
        return str_replace('\\', '/', rawurldecode($path));
    }

    private static function validRelative(string $path): ?string
    {
        $relative = self::normalize($path);
        if ($relative === '' || str_starts_with($relative, '/') || str_contains($relative, "\0")) return null;
        foreach (explode('/', $relative) as $segment) if ($segment === '' || $segment === '.' || $segment === '..') return null;
        return $relative;
    }

    private static function normalizedRoot(string $root): string
    {
        return rtrim(str_replace('\\', '/', $root), '/');
    }

    /** @return array{scheme:string,host:string,port:int,base_path:string}|null */
    private static function normalizedOrigin(string $origin): ?array
    {
        if ($origin === '') return null;
        $parts = parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return null;
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) return null;
        $basePath = isset($parts['path']) ? rtrim((string) $parts['path'], '/') : '';
        if ($basePath !== '' && (!str_starts_with($basePath, '/') || self::safeUrlPath($basePath) === null)) return null;
        return [
            'scheme' => $scheme,
            'host' => strtolower((string) $parts['host']),
            'port' => isset($parts['port']) ? (int) $parts['port'] : self::defaultPort($scheme),
            'base_path' => $basePath,
        ];
    }

    /**
     * @param array{scheme:string,host:string,port:int,base_path:string} $origin
     * @return array{prefix:string,local_path:string,suffix:string}|null
     */
    private static function sameOriginUrl(string $candidate, array $origin): ?array
    {
        $suffixOffset = self::urlSuffixOffset($candidate);
        $core = substr($candidate, 0, $suffixOffset);
        $suffix = substr($candidate, $suffixOffset);
        $decodedCore = preg_replace('~' . self::SLASH_ENTITY . '~iu', '/', $core) ?? $core;
        if (preg_match('~^((?:https?:)?//[^/]*)(/.*)?$~iD', $decodedCore, $structure) !== 1) return null;
        $absolute = str_starts_with($decodedCore, '//') ? $origin['scheme'] . ':' . $decodedCore : $decodedCore;
        $parts = parse_url($absolute);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) return null;
        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) return null;
        $port = isset($parts['port']) ? (int) $parts['port'] : self::defaultPort($scheme);
        if ($scheme !== $origin['scheme'] || strtolower((string) $parts['host']) !== $origin['host'] || $port !== $origin['port']) return null;
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';
        $safePath = self::safeUrlPath($path);
        if ($safePath === null) return null;
        $base = $origin['base_path'];
        if ($base !== '' && $safePath !== $base && !str_starts_with($safePath, $base . '/')) return null;
        $localPath = $base === '' ? $safePath : substr($safePath, strlen($base));
        if ($localPath === '') $localPath = '/';
        return ['prefix' => (string) $structure[1], 'local_path' => $localPath, 'suffix' => $suffix];
    }

    /** Find a URL query/fragment delimiter without mistaking the # inside a numeric HTML entity. */
    private static function urlSuffixOffset(string $candidate): int
    {
        $length = strlen($candidate);
        for ($offset = 0; $offset < $length; $offset++) {
            if ($candidate[$offset] === '&'
                && preg_match('~^&(?:#0*47|#x0*2f|sol);~i', substr($candidate, $offset), $entity) === 1) {
                $offset += strlen((string) $entity[0]) - 1;
                continue;
            }
            if ($candidate[$offset] === '?' || $candidate[$offset] === '#') return $offset;
        }
        return $length;
    }

    private static function safeUrlPath(string $path): ?string
    {
        if ($path === '' || !str_starts_with($path, '/') || str_contains($path, '\\') || str_contains($path, "\0")) return null;
        foreach (explode('/', rawurldecode($path)) as $segment) if ($segment === '.' || $segment === '..') return null;
        return $path;
    }

    private static function defaultPort(string $scheme): int
    {
        return $scheme === 'https' ? 443 : 80;
    }

    private static function absoluteRelative(string $value, string $uploadsRoot): ?string
    {
        if ($uploadsRoot === '') return null;
        $normalized = self::normalize($value);
        $prefix = $uploadsRoot . '/';
        if (!str_starts_with($normalized, $prefix)) return null;
        return self::validRelative(substr($normalized, strlen($prefix)));
    }
}
