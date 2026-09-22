<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ThemeValidator.php';
require_once __DIR__ . '/SiteTemplateData.php';

/** Bounded, data-first ZIP format. Never extracts arbitrary paths or executes theme files. */
final class SiteTemplateArchive
{
    public const SUPPORTED_VERSIONS = [1, 2];
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

    /**
     * 条目层安全校验（不读大文件内容）。
     *
     * 从 read() 抽出，供分阶段导入复用：inspect 一次、提取分多次，
     * 但两条路径共用同一套条目判定，避免"校验两份口径"。
     *
     * @return array{names:list<string>, manifest_bytes:string, small:array<string,string>}
     *         small 只含解析所必需的小文件（site.json 与 theme/theme.json）
     */
    private static function inspectEntries(ZipArchive $zip): array
    {
        if (zipResourceViolation($zip, 3000, self::MAX_TOTAL, self::MAX_FILE) !== null || zipUnsafeEntry($zip) !== null) throw new RuntimeException('st_limit');
        $names = [];
        $small = [];
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
            $names[] = $name;
            if ($name === 'site.json' || $name === 'theme/theme.json') {
                $bytes = $zip->getFromIndex($i);
                if (!is_string($bytes)) throw new RuntimeException('st_invalid');
                $small[$name] = $bytes;
            }
        }
        return ['names' => $names, 'manifest_bytes' => $small['site.json'] ?? '', 'small' => $small];
    }

    public static function read(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_ZIP) throw new RuntimeException('st_limit');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('st_invalid');
        try {
            $inspected = self::inspectEntries($zip);
            $files = [];
            foreach ($inspected['names'] as $name) {
                $bytes = $zip->getFromName($name);
                if (!is_string($bytes)) throw new RuntimeException('st_invalid');
                $files[$name] = $bytes;
            }
        } finally { $zip->close(); }
        $manifestBytes = $files['site.json'] ?? '';
        unset($files['site.json']);
        $manifest = self::validateManifest($manifestBytes, array_keys($files), $files);
        foreach ($files as $name => $bytes) {
            if (!hash_equals($manifest['files'][$name], hash('sha256', $bytes))) throw new RuntimeException('st_invalid');
            if (str_starts_with($name, 'media/') && str_ends_with(strtolower($name), '.svg')) $files[$name] = sanitizeSvg($bytes);
        }
        return ['manifest' => $manifest, 'files' => $files, 'hash' => (string) hash_file('sha256', $path)];
    }

    /**
     * manifest 的结构与引用校验（read() 与 inspect() 共用的唯一口径）。
     *
     * @param list<string> $names 包内除 site.json 外的全部条目名
     * @param array<string,string> $small 已读入的小文件（至少含 theme/theme.json）
     * @return array<string,mixed>
     */
    private static function validateManifest(string $manifestBytes, array $names, array $small): array
    {
        $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'yikaicms-site-template' || !in_array($manifest['version'] ?? 0, self::SUPPORTED_VERSIONS, true)
            || ($manifest['cms'] ?? '') !== (defined('CMS_VERSION') ? CMS_VERSION : '1.20.1')
            || ($manifest['schema'] ?? null) !== SiteTemplateData::schema()) throw new RuntimeException('st_schema');
        if (!is_string($manifest['theme'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $manifest['theme'])) throw new RuntimeException('st_invalid');
        $manifest['plugins'] = self::validatePlugins($manifest['plugins'] ?? []);
        $declared = array_keys(is_array($manifest['files'] ?? null) ? $manifest['files'] : []);
        if (!is_array($manifest['files'] ?? null) || array_diff($names, $declared) || array_diff($declared, $names)) throw new RuntimeException('st_invalid');
        foreach ($manifest['files'] as $digest) if (!is_string($digest)) throw new RuntimeException('st_invalid');
        $meta = json_decode($small['theme/theme.json'] ?? '', true);
        if (!is_array($meta) || ThemeValidator::validateMeta($meta, $manifest['theme'])['errors'] !== []) throw new RuntimeException('st_theme');
        $present = array_fill_keys($names, true);
        foreach (ThemeValidator::REQUIRED_FILES as $required) if (!isset($present['theme/' . $required])) throw new RuntimeException('st_theme');
        SiteTemplateData::validate($manifest['data'] ?? []);
        // v2 prevents older importers from silently dropping the public plugin contract.
        if (($manifest['version'] === 2) !== !empty($manifest['plugin_data'])) throw new RuntimeException('st_invalid');
        if (array_key_exists('plugin_data', $manifest)) {
            if (!is_array($manifest['plugin_data'])) throw new RuntimeException('st_invalid');
            SiteTemplatePluginData::validate($manifest['plugin_data'], $manifest['plugins'], $manifest['data']);
        }
        foreach ($manifest['data']['tables']['media'] as $row) {
            $url = (string) ($row['url'] ?? '');
            if (!str_starts_with($url, '/uploads/') || ($row['path'] ?? '') !== ltrim($url, '/')
                || !isset($present['media/' . substr($url, 9)])) throw new RuntimeException('st_missing_media');
        }
        return $manifest;
    }

    /** @return list<array{slug:string,version:string}> */
    private static function validatePlugins(mixed $plugins): array
    {
        if (!is_array($plugins) || count($plugins) > 100) throw new RuntimeException('st_invalid');
        $validated = [];
        foreach ($plugins as $plugin) {
            if (!is_array($plugin) || array_keys($plugin) !== ['slug', 'version']) throw new RuntimeException('st_invalid');
            $slug = $plugin['slug'] ?? null;
            $version = $plugin['version'] ?? null;
            if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $slug) !== 1
                || !is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/D', $version) !== 1
                || isset($validated[$slug])) throw new RuntimeException('st_invalid');
            $validated[$slug] = ['slug' => $slug, 'version' => $version];
        }
        ksort($validated);
        return array_values($validated);
    }

    /**
     * 分阶段导入的第一步：只做条目层校验与 manifest 解析，**不把文件内容读进内存**。
     *
     * 返回的 manifest 与 read() 完全一致（同一套校验），但 files 只给名字清单。
     * 大包在这里的内存峰值是单个小文件，而不是整包解压后的 50MB。
     *
     * @return array{manifest:array<string,mixed>, names:list<string>, hash:string}
     */
    public static function inspect(string $path): array
    {
        if (!is_file($path) || filesize($path) > self::MAX_ZIP) throw new RuntimeException('st_limit');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('st_invalid');
        try {
            $inspected = self::inspectEntries($zip);
        } finally { $zip->close(); }
        $names = array_values(array_filter($inspected['names'], static fn(string $n): bool => $n !== 'site.json'));
        $manifest = self::validateManifest($inspected['manifest_bytes'], $names, $inspected['small']);
        return ['manifest' => $manifest, 'names' => $names, 'hash' => (string) hash_file('sha256', $path)];
    }

    /**
     * 取出一个条目并完成内容层校验（sha256 + SVG 消毒）。
     * 逐条调用，配合游标即可按请求预算分批落盘。
     */
    public static function entry(string $path, array $manifest, string $name): string
    {
        if (!isset($manifest['files'][$name]) || !is_string($manifest['files'][$name])) throw new RuntimeException('st_invalid');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('st_invalid');
        try {
            $bytes = $zip->getFromName($name);
        } finally { $zip->close(); }
        if (!is_string($bytes)) throw new RuntimeException('st_invalid');
        // 内容摘要在提取时逐条核对，与一次性 read() 同口径
        if (!hash_equals($manifest['files'][$name], hash('sha256', $bytes))) throw new RuntimeException('st_invalid');
        if (str_starts_with($name, 'media/') && str_ends_with(strtolower($name), '.svg')) $bytes = sanitizeSvg($bytes);
        return $bytes;
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
