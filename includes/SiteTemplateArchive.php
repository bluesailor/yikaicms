<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ThemeValidator.php';
require_once __DIR__ . '/SiteTemplateData.php';

/** Bounded, data-first ZIP format. Never extracts arbitrary paths or executes theme files. */
final class SiteTemplateArchive
{
    public const MAX_ZIP = 33554432;
    public const MAX_TOTAL = 50331648;
    public const MAX_FILE = 12582912;
    private const STATIC_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'pdf', 'woff', 'woff2', 'ttf', 'mp4', 'webm'];

    public static function safePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 240 || str_contains($path, '\\') || str_contains($path, '//')) return false;
        foreach (explode('/', $path) as $part) {
            if (!preg_match('/^[a-zA-Z0-9_\x{0080}-\x{FFFF}][a-zA-Z0-9_.\-\x{0080}-\x{FFFF}]*$/uD', $part)
                || str_ends_with($part, '.') || preg_match('/^(?:con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $part)) return false;
        }
        return true;
    }

    public static function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_ZIP) throw new RuntimeException('st_limit');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('st_invalid');
        try {
            if (zipResourceViolation($zip, 3000, self::MAX_TOTAL, self::MAX_FILE) !== null || zipUnsafeEntry($zip) !== null) throw new RuntimeException('st_limit');
            $files = [];
            $folded = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $os = $attr = 0;
                $zip->getExternalAttributesIndex($i, $os, $attr);
                if (!self::safePath($name) || isset($folded[strtolower($name)])
                    || ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000)) throw new RuntimeException('st_unsafe');
                $folded[strtolower($name)] = true;
                if ($name !== 'site.json') {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allowed = str_starts_with($name, 'theme/') ? array_merge(self::STATIC_EXT, ['php', 'css', 'js', 'json']) : self::STATIC_EXT;
                    if ((!str_starts_with($name, 'theme/') && !str_starts_with($name, 'media/')) || !in_array($ext, $allowed, true)) throw new RuntimeException('st_unsafe');
                    if (str_starts_with($name, 'media/') && preg_match('/\.(?:php[0-9]?|phtml|phar)(?:\.|$)/i', $name)) throw new RuntimeException('st_unsafe');
                }
                $bytes = $zip->getFromIndex($i);
                if (!is_string($bytes)) throw new RuntimeException('st_invalid');
                $files[$name] = $bytes;
            }
        } finally { $zip->close(); }
        $manifest = json_decode($files['site.json'] ?? '', true, 64, JSON_THROW_ON_ERROR);
        unset($files['site.json']);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'yikaicms-site-template' || ($manifest['version'] ?? 0) !== 1
            || ($manifest['cms'] ?? '') !== (defined('CMS_VERSION') ? CMS_VERSION : '1.20.1')
            || ($manifest['schema'] ?? null) !== SiteTemplateData::schema()) throw new RuntimeException('st_schema');
        if (!is_string($manifest['theme'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $manifest['theme'])) throw new RuntimeException('st_invalid');
        if (!is_array($manifest['files'] ?? null) || array_diff(array_keys($files), array_keys($manifest['files']))
            || array_diff(array_keys($manifest['files']), array_keys($files))) throw new RuntimeException('st_invalid');
        foreach ($files as $name => $bytes) {
            if (!is_string($manifest['files'][$name]) || !hash_equals($manifest['files'][$name], hash('sha256', $bytes))) throw new RuntimeException('st_invalid');
            if (str_starts_with($name, 'media/') && str_ends_with(strtolower($name), '.svg')) $files[$name] = sanitizeSvg($bytes);
        }
        $meta = json_decode($files['theme/theme.json'] ?? '', true);
        if (!is_array($meta) || ThemeValidator::validateMeta($meta, $manifest['theme'])['errors'] !== []) throw new RuntimeException('st_theme');
        foreach (ThemeValidator::REQUIRED_FILES as $required) if (!isset($files['theme/' . $required])) throw new RuntimeException('st_theme');
        SiteTemplateData::validate($manifest['data'] ?? []);
        foreach ($manifest['data']['tables']['media'] as $row) {
            $url = (string) ($row['url'] ?? '');
            if (!str_starts_with($url, '/uploads/') || ($row['path'] ?? '') !== ltrim($url, '/')
                || !isset($files['media/' . substr($url, 9)])) throw new RuntimeException('st_missing_media');
        }
        return ['manifest' => $manifest, 'files' => $files, 'hash' => (string) hash_file('sha256', $path)];
    }

    public static function write(string $path, array $manifest, array $files): void
    {
        $manifest['files'] = [];
        $total = 0;
        foreach ($files as $name => $bytes) {
            if (!self::safePath($name) || strlen($bytes) > self::MAX_FILE) throw new RuntimeException('st_limit');
            $total += strlen($bytes);
            $manifest['files'][$name] = hash('sha256', $bytes);
        }
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if ($total + strlen($json) > self::MAX_TOTAL || strlen($json) > self::MAX_FILE) throw new RuntimeException('st_limit');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('st_storage');
        try {
            if (!$zip->addFromString('site.json', $json)) throw new RuntimeException('st_storage');
            foreach ($files as $name => $bytes) if (!$zip->addFromString($name, $bytes)) throw new RuntimeException('st_storage');
        } finally { if (!$zip->close()) throw new RuntimeException('st_storage'); }
        if (filesize($path) > self::MAX_ZIP) throw new RuntimeException('st_limit');
    }

    /** Normalize same-origin references, leaving third-party links untouched. */
    public static function rewrite(mixed $value, array $map): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) $value[$key] = self::rewrite($item, $map);
            return $value;
        }
        if (!is_string($value)) return $value;
        $json = json_decode($value, true);
        if (is_array($json)) return json_encode(self::rewrite($json, $map), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        foreach ($map as $from => $to) {
            $value = (string) preg_replace_callback('~(?<![a-zA-Z0-9_/:.\-])' . preg_quote($from, '~') . '~', static fn(): string => $to, $value);
        }
        return $value;
    }
}
