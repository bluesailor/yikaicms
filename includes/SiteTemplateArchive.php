<?php
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/ThemeValidator.php';
require_once __DIR__ . '/SiteTemplateData.php';
require_once __DIR__ . '/SiteTemplatePluginData.php';
require_once __DIR__ . '/UploadReferences.php';

/** Bounded, data-first ZIP format. Never extracts arbitrary paths or executes theme files. */
final class SiteTemplateArchive
{
    public const SUPPORTED_VERSIONS = [1, 2];
    public const MAX_ZIP = 33554432;
    public const MAX_TOTAL = 50331648;
    public const MAX_FILE = 12582912;
    private const STATIC_EXT = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'pdf', 'woff', 'woff2', 'ttf', 'mp4', 'webm'];
    private const THEME_EXT = ['php', 'css', 'js', 'json', 'md'];

    /**
     * 模板包与当前 CMS 是否兼容：同一「主.次」版本线内，模板制作版本不晚于本站即可
     * （2.0.0 做的模板在 2.0.x 补丁版上都能导入，不必每个补丁版重签全部模板）。
     * 跨次版本仍拒绝；补丁版若改了表结构，由 schema 契约另行拒绝。
     */
    public static function cmsCompatible(string $templateCms, ?string $current = null): bool
    {
        $current ??= defined('CMS_VERSION') ? (string) CMS_VERSION : '';
        if (preg_match('/^(\d+)\.(\d+)\.\d+$/D', $templateCms, $template) !== 1
            || preg_match('/^(\d+)\.(\d+)\.\d+$/D', $current, $site) !== 1) {
            return false;
        }
        return (int) $template[1] === (int) $site[1] && (int) $template[2] === (int) $site[2]
            && version_compare($current, $templateCms, '>=');
    }

    /** 市场卡片上显示的适用版本线，如 2.0.x。 */
    public static function cmsSeries(string $templateCms): string
    {
        return preg_match('/^(\d+)\.(\d+)\.\d+$/D', $templateCms, $match) === 1 ? $match[1] . '.' . $match[2] . '.x' : $templateCms;
    }

    /** 主题 theme.json 的 required_plugins（主题缺了它就不能启用）。 @return list<string> */
    public static function themeRequiredPlugins(array $meta): array
    {
        $slugs = [];
        foreach ((array) ($meta['required_plugins'] ?? []) as $slug) {
            if (is_string($slug) && $slug !== '') $slugs[] = $slug;
        }
        return array_values(array_unique($slugs));
    }

    public static function safePath(string $path): bool
    {
        if ($path === '' || strlen($path) > 240 || str_contains($path, '\\') || str_contains($path, '//')) return false;
        foreach (explode('/', $path) as $part) {
            if (!preg_match('/^[a-zA-Z0-9_\x{0080}-\x{FFFF}][a-zA-Z0-9_.\-\x{0080}-\x{FFFF}]*$/uD', $part)
                || str_ends_with($part, '.') || preg_match('/^(?:con|prn|aux|nul|com[0-9]|lpt[0-9])(?:\.|$)/i', $part)) return false;
        }
        return true;
    }

    /** Export and import must share one theme-file allowlist. */
    public static function themeFileAllowed(string $relative): bool
    {
        if (!self::safePath($relative)) return false;
        $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        return in_array($extension, array_merge(self::STATIC_EXT, self::THEME_EXT), true);
    }

    /**
     * 条目层安全校验（不读大文件内容）。
     *
     * 从 read() 抽出，供分阶段导入复用：inspect 一次、提取分多次，
     * 但两条路径共用同一套条目判定，避免"校验两份口径"。
     *
     * @return array{names:list<string>, manifest_bytes:string, small:array<string,string>, theme_references:array<string,int>}
     *         small 只含解析所必需且受总量约束的小文件（manifest、theme meta、plugin data）
     */
    private static function inspectEntries(ZipArchive $zip): array
    {
        if (zipResourceViolation($zip, 3000, self::MAX_TOTAL, self::MAX_FILE) !== null || zipUnsafeEntry($zip) !== null) throw new RuntimeException('st_limit');
        $names = [];
        $small = [];
        $folded = [];
        $pluginDataBytes = 0;
        $themeReferences = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $os = $attr = 0;
            $zip->getExternalAttributesIndex($i, $os, $attr);
            if (!self::safePath($name) || isset($folded[strtolower($name)])
                || ($os === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000)) throw new RuntimeException('st_unsafe');
            $folded[strtolower($name)] = true;
            $pluginData = false;
            if ($name !== 'site.json') {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $pluginData = preg_match('#^plugin-data/[a-z0-9][a-z0-9-]{0,79}\.json$#D', $name) === 1;
                $themeFile = str_starts_with($name, 'theme/')
                    && self::themeFileAllowed(substr($name, strlen('theme/')));
                $allowed = $pluginData ? ['json'] : self::STATIC_EXT;
                if ((!str_starts_with($name, 'theme/') && !str_starts_with($name, 'media/') && !$pluginData)
                    || (str_starts_with($name, 'theme/') ? !$themeFile : !in_array($ext, $allowed, true))) {
                    throw new RuntimeException('st_unsafe');
                }
                if (str_starts_with($name, 'media/') && preg_match('/\.(?:php[0-9]?|phtml|phar)(?:\.|$)/i', $name)) throw new RuntimeException('st_unsafe');
            }
            $names[] = $name;
            if ($name === 'site.json' || $name === 'theme/theme.json' || str_starts_with($name, 'plugin-data/')) {
                if ($pluginData) {
                    $stat = $zip->statIndex($i);
                    $entrySize = is_array($stat) ? (int) ($stat['size'] ?? -1) : -1;
                    $pluginDataBytes += max(0, $entrySize);
                    if ($entrySize < 0 || $entrySize > SiteTemplatePluginData::MAX_FILE_BYTES
                        || $pluginDataBytes > SiteTemplatePluginData::MAX_TOTAL_BYTES) throw new RuntimeException('st_limit');
                }
                $bytes = $zip->getFromIndex($i);
                if (!is_string($bytes)) throw new RuntimeException('st_invalid');
                $small[$name] = $bytes;
            }
            if (str_starts_with($name, 'theme/') && preg_match('/\.(?:php|css|js|json|svg)$/iD', $name) === 1) {
                $bytes = $small[$name] ?? $zip->getFromIndex($i);
                if (!is_string($bytes)) throw new RuntimeException('st_invalid');
                foreach (UploadReferences::collect($bytes) as $relative => $count) {
                    $themeReferences[$relative] = ($themeReferences[$relative] ?? 0) + $count;
                }
            }
        }
        return ['names' => $names, 'manifest_bytes' => $small['site.json'] ?? '', 'small' => $small,
            'theme_references' => $themeReferences];
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
        $manifest = self::validateManifest($manifestBytes, array_keys($files), $files, $inspected['theme_references']);
        foreach ($files as $name => $bytes) {
            if (!hash_equals($manifest['files'][$name], hash('sha256', $bytes))) throw new RuntimeException('st_invalid');
            if (str_starts_with($name, 'media/') && str_ends_with(strtolower($name), '.svg')) $files[$name] = sanitizeSvg($bytes);
        }
        $pluginData = SiteTemplatePluginData::decode($manifest['plugin_data'] ?? [], $manifest['plugins'], $files, $manifest['files']);
        return ['manifest' => $manifest, 'files' => $files, 'plugin_data' => $pluginData,
            'hash' => (string) hash_file('sha256', $path)];
    }

    /**
     * manifest 的结构与引用校验（read() 与 inspect() 共用的唯一口径）。
     *
     * @param list<string> $names 包内除 site.json 外的全部条目名
     * @param array<string,string> $small 已读入的小文件（至少含 theme/theme.json）
     * @return array<string,mixed>
     */
    private static function validateManifest(string $manifestBytes, array $names, array $small, array $themeReferences = []): array
    {
        $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'yikaicms-site-template' || !in_array($manifest['version'] ?? 0, self::SUPPORTED_VERSIONS, true)
            || !self::cmsCompatible((string) ($manifest['cms'] ?? ''), defined('CMS_VERSION') ? (string) CMS_VERSION : '2.0.0')
            || ($manifest['schema'] ?? null) !== SiteTemplateData::contractSchema()
            || ($manifest['schema'] ?? null) !== SiteTemplateData::schema()) throw new RuntimeException('st_schema');
        if (!is_string($manifest['theme'] ?? null) || !preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $manifest['theme'])) throw new RuntimeException('st_invalid');
        $manifest['plugins'] = self::validatePlugins($manifest['plugins'] ?? []);
        $declared = array_keys(is_array($manifest['files'] ?? null) ? $manifest['files'] : []);
        if (!is_array($manifest['files'] ?? null) || array_diff($names, $declared) || array_diff($declared, $names)) throw new RuntimeException('st_invalid');
        foreach ($manifest['files'] as $digest) if (!is_string($digest)) throw new RuntimeException('st_invalid');
        $meta = json_decode($small['theme/theme.json'] ?? '', true);
        if (!is_array($meta) || ThemeValidator::validateMeta($meta, $manifest['theme'], false)['errors'] !== []) throw new RuntimeException('st_theme');
        // 主题依赖的插件必须同时在模板的插件清单里声明：这样预览会把它列为缺失插件、可一键安装；没声明的仍按主题不完整拒绝
        $declaredPlugins = array_column($manifest['plugins'], 'slug');
        foreach (self::themeRequiredPlugins($meta) as $slug) if (!in_array($slug, $declaredPlugins, true)) throw new RuntimeException('st_theme');
        $present = array_fill_keys($names, true);
        foreach (ThemeValidator::REQUIRED_FILES as $required) if (!isset($present['theme/' . $required])) throw new RuntimeException('st_theme');
        SiteTemplateData::validate($manifest['data'] ?? []);
        // v2 prevents older importers from silently dropping the public plugin contract.
        if (($manifest['version'] === 2) !== !empty($manifest['plugin_data'])) throw new RuntimeException('st_invalid');
        SiteTemplatePluginData::validateArchive($manifest, $names, $small);
        $pluginData = SiteTemplatePluginData::decode($manifest['plugin_data'] ?? [], $manifest['plugins'], $small, $manifest['files']);
        self::validateMediaReferences($manifest, $pluginData, $themeReferences);
        return $manifest;
    }

    /**
     * Every portable upload reference must resolve to a declared media file. Runtime imports and
     * release-side inspection call this same contract so a package cannot pass one path only.
     *
     * @param array<string,mixed> $manifest
     * @param array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array}> $pluginData
     * @param array<string,int> $additionalReferences References collected from streamed theme text.
     */
    public static function validateMediaReferences(array $manifest, array $pluginData, array $additionalReferences = []): void
    {
        $declared = array_fill_keys(array_keys(is_array($manifest['files'] ?? null) ? $manifest['files'] : []), true);
        $references = UploadReferences::collect($manifest['data'] ?? []);
        foreach ($pluginData as $entry) {
            foreach (UploadReferences::collect($entry['payload']) as $relative => $count) {
                $references[$relative] = ($references[$relative] ?? 0) + $count;
            }
        }
        foreach ($additionalReferences as $relative => $count) {
            if (!is_string($relative) || !is_int($count)) throw new RuntimeException('st_invalid');
            $references[$relative] = ($references[$relative] ?? 0) + $count;
        }
        foreach ($references as $relative => $count) {
            if ($count > 0 && (!self::safePath($relative) || !isset($declared['media/' . $relative]))) {
                throw new RuntimeException('st_missing_media');
            }
        }
        $mediaRows = $manifest['data']['tables']['media'] ?? null;
        if (!is_array($mediaRows)) throw new RuntimeException('st_invalid');
        foreach ($mediaRows as $row) {
            $url = is_array($row) ? (string) ($row['url'] ?? '') : '';
            if (!is_array($row) || !str_starts_with($url, '/uploads/') || ($row['path'] ?? '') !== ltrim($url, '/')
                || !isset($declared['media/' . substr($url, 9)])) throw new RuntimeException('st_missing_media');
        }
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
     * @return array{manifest:array<string,mixed>, names:list<string>, plugin_data:array<string,array{contract:int,schema:array{id:string,version:int,sha256:string},payload:array}>, hash:string}
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
        $manifest = self::validateManifest($inspected['manifest_bytes'], $names, $inspected['small'], $inspected['theme_references']);
        $pluginData = SiteTemplatePluginData::decode($manifest['plugin_data'] ?? [], $manifest['plugins'], $inspected['small'], $manifest['files']);
        return ['manifest' => $manifest, 'names' => $names, 'plugin_data' => $pluginData,
            'hash' => (string) hash_file('sha256', $path)];
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
