<?php
declare(strict_types=1);

/** Shared uploads reference recognition for site-template export and maintenance tools. */
final class UploadReferences
{
    private const PATTERN = '~(?<![a-zA-Z0-9/:.])[/\\\\]?uploads[/\\\\]([^\s"\'<>?#)]+)~u';

    /** @return array<string,int> relative path => occurrence count */
    public static function collect(mixed $value): array
    {
        $refs = [];
        self::collectInto($value, $refs);
        return $refs;
    }

    /**
     * Rewrites only references recognized by collect(). JSON documents are decoded first so escaped
     * slashes and nested Blox values follow exactly the same recognition path as site-template export.
     *
     * @param array<string,string> $map relative source path => relative target path
     */
    public static function rewrite(mixed $value, array $map, int &$changes = 0): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::rewrite($item, $map, $changes);
            }
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            $rewritten = self::rewrite($decoded, $map, $changes);
            if ($rewritten === $decoded) {
                return $value;
            }
            return json_encode($rewritten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return preg_replace_callback(self::PATTERN, static function (array $match) use ($map, &$changes): string {
            $relative = self::normalize((string) $match[1]);
            if (!isset($map[$relative])) {
                return (string) $match[0];
            }
            $prefixLength = strlen((string) $match[0]) - strlen((string) $match[1]);
            $prefix = substr((string) $match[0], 0, $prefixLength);
            $changes++;
            return $prefix . self::encodeLike((string) $match[1], $map[$relative]);
        }, $value) ?? $value;
    }

    /** @param array<string,int> $refs */
    private static function collectInto(mixed $value, array &$refs): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                self::collectInto($item, $refs);
            }
            return;
        }
        if (!is_string($value) || $value === '') {
            return;
        }
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            self::collectInto($decoded, $refs);
            return;
        }
        preg_match_all(self::PATTERN, html_entity_decode($value, ENT_QUOTES, 'UTF-8'), $matches);
        foreach ($matches[1] as $path) {
            $relative = self::normalize((string) $path);
            $refs[$relative] = ($refs[$relative] ?? 0) + 1;
        }
    }

    private static function encodeLike(string $source, string $target): string
    {
        $separator = str_contains($source, '\\') ? '\\' : '/';
        $encoded = str_contains($source, '%') ? str_replace('%2F', '/', rawurlencode($target)) : $target;
        return $separator === '\\' ? str_replace('/', '\\', $encoded) : $encoded;
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', rawurldecode($path));
    }
}
