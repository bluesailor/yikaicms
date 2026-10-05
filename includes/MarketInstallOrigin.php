<?php

declare(strict_types=1);

/** Site-owned receipt, moved with its resource so rollback restores provenance too. */
final class MarketInstallOrigin
{
    public const FILE = '.yikai-market-origin.json';
    /** 主题安装时各文件的 sha256（相对路径 → 哈希），升级前据此找出站长改过、加过的文件（2.0.5） */
    public const FILES = '.yikai-theme-files.json';

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
        // 首次信任（2026-09-17 产品决定）：1.20.0 之前安装的主题/插件（含随核心分发的 default）都没有回执。
        // 完全没有回执、且本次条目来自服务端官方目录时放行，安装成功后由安装器写入 official 回执；
        // 社区条目、损坏/伪造回执、手动上传留下的 local 回执仍一律拒绝。替换前安装器会先备份原目录。
        if ($origin === 'official' && !file_exists($file) && !is_link($file)) return;
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

    /**
     * @param array{market_slug?:string,rewrite?:array<string,string>} $link 2.0.5：随官方整站模板装上的主题（目录是 sitepack-* 别名），
     *        记下它对应的市场主题 slug 与导入时做过的路径改写，市场出新版时才能对上号并照样改写后升级。
     */
    public static function write(string $directory, string $kind, string $slug, string $version, string $origin, array $link = []): bool
    {
        self::validate($kind, $slug, $origin);
        $file = $directory . '/' . self::FILE;
        if (!is_dir($directory) || is_link($directory) || is_link($file)) return false;
        $receipt = [
            'kind' => $kind, 'slug' => $slug, 'origin' => $origin,
            'provider' => $origin === 'local' ? '' : 'update.yikaicms.com', 'version' => $version,
        ];
        if (isset($link['market_slug'])) {
            if ($kind !== 'theme' || $origin !== 'official' || !self::validLink($link)) throw new RuntimeException('invalid');
            $receipt['market_slug'] = $link['market_slug'];
            $receipt['rewrite'] = $link['rewrite'] ?? [];
        }
        $json = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return strlen($json) <= 4096 && @file_put_contents($file, $json, LOCK_EX) === strlen($json);
    }

    /**
     * 随官方整站模板装上、关联了市场主题的目录：返回市场 slug 与路径改写；否则 null（手动上传、旧版导入、回执损坏都算没关联）。
     * @return array{market_slug:string,rewrite:array<string,string>}|null
     */
    public static function linked(string $root, string $directory): ?array
    {
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $directory) !== 1) return null;
        $file = rtrim($root, '/\\') . '/' . $directory . '/' . self::FILE;
        clearstatcache(true, $file);
        $size = @filesize($file);
        $receipt = !is_link($file) && is_int($size) && $size > 0 && $size <= 4096
            ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($receipt) || ($receipt['kind'] ?? '') !== 'theme' || ($receipt['slug'] ?? '') !== $directory
            || ($receipt['origin'] ?? '') !== 'official' || ($receipt['provider'] ?? '') !== 'update.yikaicms.com'
            || !isset($receipt['market_slug']) || !self::validLink($receipt) || $receipt['market_slug'] === $directory) {
            return null;
        }
        return ['market_slug' => $receipt['market_slug'], 'rewrite' => $receipt['rewrite'] ?? []];
    }

    /** @param array<array-key,mixed> $link */
    private static function validLink(array $link): bool
    {
        if (!is_string($link['market_slug'] ?? null)
            || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/D', $link['market_slug']) !== 1) return false;
        $rewrite = $link['rewrite'] ?? [];
        if (!is_array($rewrite) || count($rewrite) > 8) return false;
        foreach ($rewrite as $from => $to) {
            // 只允许导入时那种「/themes/x/ → /themes/别名/」「/uploads/ → /uploads/别名/」的站内路径前缀
            if (!is_string($from) || !is_string($to) || preg_match('#^/[a-z0-9/_-]{1,120}/$#D', $from) !== 1
                || preg_match('#^/[a-z0-9/_-]{1,120}/$#D', $to) !== 1) return false;
        }
        return true;
    }

    public static function isReceiptPath(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            $part = strtolower(rtrim($part, '. '));
            if ($part === self::FILE || $part === self::FILES) return true;
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
