<?php
/**
 * 按需下载的界面语言包（设计：yikaicms-docs/planning/features/language-packs-on-demand-design-2026-10-09.md）。
 *
 * 安装包只带 BUNDLED 三种；其余语言随每个 CMS 版本发布为签名语言包：
 *   https://down.yikai.cn/soft/yikaicms/lang/<版本>/yikaicms-lang-<code>-<版本>.zip
 * 包里恰好两个条目：lang/<code>.php 与 pack.json（code、cms_version、sha256、signature）。
 *
 * 装好后仍落在 lang/<code>.php——「语言已安装 = 文件存在」是路由、语言设置、安装向导的共同口径，
 * 它们因此一行不用改。反过来：**启用中的语言包被删，/<code>/ 整站 404**，所以卸载要先停用。
 *
 * 安全：lang/<code>.php 会被 require 执行，未签名或签名不符一律拒装，没有跳过开关。
 * 签名串带 yikaicms-lang-pack 前缀，与 CMS 安装包（version|hash）、插件包互不通用，
 * 防止同版本号的语言包签名被拿去冒充安装包签名。
 *
 * 本类不依赖数据库与 functions.php：安装向导在建库之前也要用。
 */

declare(strict_types=1);

require_once __DIR__ . '/LanguageRegistry.php';

final class LanguagePackException extends RuntimeException
{
    /** 机器可读原因，后台据此给出对应语言的提示（PHP 8.0 无 readonly，用私有属性 + 读取方法） */
    private string $reason;

    public function __construct(string $reason, string $detail = '')
    {
        $this->reason = $reason;
        parent::__construct($detail !== '' ? $reason . ': ' . $detail : $reason);
    }

    public function reason(): string
    {
        return $this->reason;
    }
}

final class LanguagePacks
{
    /** 随安装包分发的语言：zh-CN 是原文与兜底底座，en 是非汉字语言的兜底层，ja 是第二大客户语言。 */
    public const BUNDLED = ['zh-CN', 'en', 'ja'];

    public const BASE_URL = 'https://down.yikai.cn/soft/yikaicms/lang/';

    /** 单个语言包上限：最大的 th 解压后约 1.1 MB，压缩后约 0.2 MB。 */
    public const MAX_DOWNLOAD_BYTES = 1048576;
    private const MAX_PHP_BYTES = 4194304;

    private const SIGNATURE_PREFIX = 'yikaicms-lang-pack|v1|';

    /** 已装语言包的记录（版本与哈希）。放 storage/：受访问保护，且随站点备份走。 */
    private const MANIFEST = '/storage/lang-packs.json';

    public static function isBundled(string $code): bool
    {
        return in_array($code, self::BUNDLED, true);
    }

    /** 可以下载安装的语言：注册表里除内置三种以外的全部。 @return list<string> */
    public static function downloadable(): array
    {
        return array_values(array_filter(LanguageRegistry::codes(), static fn(string $code): bool => !self::isBundled($code)));
    }

    public static function packageName(string $code, string $version): string
    {
        return 'yikaicms-lang-' . $code . '-' . $version . '.zip';
    }

    public static function url(string $code, string $version): string
    {
        return self::BASE_URL . $version . '/' . self::packageName($code, $version);
    }

    public static function canonical(string $code, string $version, string $sha256): string
    {
        return self::SIGNATURE_PREFIX . $code . '|' . $version . '|sha256:' . strtolower($sha256);
    }

    public static function verifySignature(string $code, string $version, string $sha256, string $signature, string $publicKey): bool
    {
        if (!function_exists('openssl_verify') || $signature === '') {
            return false;
        }
        $raw = base64_decode($signature, true);
        return is_string($raw)
            && openssl_verify(self::canonical($code, $version, $sha256), $raw, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @return array<string, array{version:string, sha256:string, installed_at:int}> */
    public static function manifest(string $root): array
    {
        $raw = @file_get_contents($root . self::MANIFEST);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $code => $row) {
            if (is_string($code) && is_array($row) && is_string($row['version'] ?? null)) {
                $out[$code] = [
                    'version' => $row['version'],
                    'sha256' => (string) ($row['sha256'] ?? ''),
                    'installed_at' => (int) ($row['installed_at'] ?? 0),
                ];
            }
        }
        return $out;
    }

    /** 本站装着的非内置语言（以 lang/<code>.php 是否存在为准，不只看记录）。 @return list<string> */
    public static function installed(string $root): array
    {
        return array_values(array_filter(self::downloadable(), static fn(string $code): bool => is_file($root . '/lang/' . $code . '.php')));
    }

    /**
     * 需要更新的语言包：装着、但记录的版本不是当前 CMS 版本（含 2.1 之前随包来的、没有记录的）。
     *
     * @return list<string>
     */
    public static function outdated(string $root, string $cmsVersion): array
    {
        $manifest = self::manifest($root);
        return array_values(array_filter(
            self::installed($root),
            static fn(string $code): bool => ($manifest[$code]['version'] ?? '') !== $cmsVersion
        ));
    }

    /**
     * 下载并安装 <code> 在 <cmsVersion> 的语言包。
     *
     * @param callable(string $url, int $maxBytes): ?string $fetch 返回包字节；失败返回 null
     * @return array{code:string, version:string}
     */
    public static function download(string $root, string $code, string $cmsVersion, string $publicKey, callable $fetch): array
    {
        self::assertDownloadable($code);
        if (preg_match('/^\d+\.\d+\.\d+$/D', $cmsVersion) !== 1) {
            throw new LanguagePackException('bad_version', $cmsVersion);
        }
        $bytes = $fetch(self::url($code, $cmsVersion), self::MAX_DOWNLOAD_BYTES);
        if (!is_string($bytes) || $bytes === '') {
            throw new LanguagePackException('download_failed', self::url($code, $cmsVersion));
        }
        return self::installFromZip($root, $bytes, $cmsVersion, $publicKey, $code);
    }

    /**
     * 校验并安装一个语言包 zip（在线下载与离线上传共用）。
     *
     * 顺序：大小 → 条目白名单 → pack.json → 版本 → 内层哈希 → 验签 → 临时文件试加载 → 原子替换 → 记录。
     * 任何一步失败都不碰现有的 lang/<code>.php。
     *
     * @return array{code:string, version:string}
     */
    public static function installFromZip(string $root, string $zipBytes, string $cmsVersion, string $publicKey, ?string $expectCode = null): array
    {
        if (strlen($zipBytes) > self::MAX_DOWNLOAD_BYTES) {
            throw new LanguagePackException('too_large');
        }
        if (!class_exists('ZipArchive')) {
            throw new LanguagePackException('no_zip');
        }
        $tmpZip = tempnam(sys_get_temp_dir(), 'yklp');
        if ($tmpZip === false || file_put_contents($tmpZip, $zipBytes) === false) {
            throw new LanguagePackException('not_writable', sys_get_temp_dir());
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmpZip) !== true) {
                throw new LanguagePackException('bad_zip');
            }
            try {
                $meta = json_decode((string) $zip->getFromName('pack.json'), true);
                $code = is_array($meta) ? (string) ($meta['code'] ?? '') : '';
                if ($zip->numFiles !== 2 || $code === '' || $zip->locateName('lang/' . $code . '.php') === false) {
                    throw new LanguagePackException('bad_zip');
                }
                $php = $zip->getFromName('lang/' . $code . '.php');
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmpZip);
        }

        self::assertDownloadable($code);
        if ($expectCode !== null && $code !== $expectCode) {
            throw new LanguagePackException('wrong_language', $code);
        }
        $version = (string) ($meta['cms_version'] ?? '');
        if (preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1 || ($meta['schema'] ?? null) !== 1) {
            throw new LanguagePackException('bad_zip');
        }
        // 比本站新的语言包可能引用本站还没有的东西，不装；旧的可以装，缺的键走兜底
        if (version_compare($version, $cmsVersion, '>')) {
            throw new LanguagePackException('newer_than_cms', $version);
        }
        if (!is_string($php) || $php === '' || strlen($php) > self::MAX_PHP_BYTES || !str_starts_with($php, '<?php')) {
            throw new LanguagePackException('bad_zip');
        }
        $sha256 = hash('sha256', $php);
        if (!hash_equals(strtolower((string) ($meta['sha256'] ?? '')), $sha256)) {
            throw new LanguagePackException('hash_mismatch');
        }
        if (!self::verifySignature($code, $version, $sha256, (string) ($meta['signature'] ?? ''), $publicKey)) {
            throw new LanguagePackException('bad_signature');
        }

        $target = $root . '/lang/' . $code . '.php';
        $tmp = $root . '/lang/.' . $code . '.php.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $php, LOCK_EX) === false) {
            throw new LanguagePackException('not_writable', $root . '/lang');
        }
        // 签名已过，这里只防「签了一个坏文件」：必须返回文案数组，否则不替换现有语言包
        $loaded = (static fn(string $file): mixed => require $file)($tmp);
        if (!is_array($loaded) || $loaded === []) {
            @unlink($tmp);
            throw new LanguagePackException('bad_zip');
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            throw new LanguagePackException('not_writable', $target);
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($target, true);
        }
        self::record($root, $code, ['version' => $version, 'sha256' => $sha256, 'installed_at' => time()]);

        return ['code' => $code, 'version' => $version];
    }

    /**
     * 卸载一个语言包。启用中的语言拒绝卸载——它的 URL 前缀与后台界面都靠这个文件。
     *
     * @param list<string> $inUse 前台启用语言、默认语言、后台语言等
     */
    public static function uninstall(string $root, string $code, array $inUse): void
    {
        self::assertDownloadable($code);
        if (in_array($code, $inUse, true)) {
            throw new LanguagePackException('in_use', $code);
        }
        $file = $root . '/lang/' . $code . '.php';
        if (is_file($file) && !@unlink($file)) {
            throw new LanguagePackException('not_writable', $file);
        }
        self::record($root, $code, null);
    }

    /**
     * 默认下载器：只 GET 固定的官方地址、校验证书、不跟随跳转、超过上限即中止。
     *
     * 不复用 PluginMarketInstall::httpGet：安装向导在建库、加载 functions.php 之前就要下语言包，
     * 那条链会连带 security.php 等依赖。地址由 url() 拼出，不接受外部传入。
     */
    public static function httpFetch(string $url, int $maxBytes): ?string
    {
        if (!str_starts_with($url, self::BASE_URL)) {
            return null;
        }
        if (function_exists('curl_init')) {
            $body = '';
            $tooLarge = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return !$tooLarge && $status === 200 && $body !== '' ? $body : null;
        }
        if (!ini_get('allow_url_fopen')) {
            return null;
        }
        $context = stream_context_create(['http' => ['timeout' => 30, 'follow_location' => 0, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        $statusLine = (string) ($http_response_header[0] ?? '');
        return is_string($body) && $body !== '' && strlen($body) <= $maxBytes && preg_match('/^HTTP\/\S+\s+200\b/', $statusLine) === 1 ? $body : null;
    }

    private static function assertDownloadable(string $code): void
    {
        if (!LanguageRegistry::has($code) || self::isBundled($code)) {
            throw new LanguagePackException('unknown_language', $code);
        }
    }

    /** @param array{version:string, sha256:string, installed_at:int}|null $row null = 删除记录 */
    private static function record(string $root, string $code, ?array $row): void
    {
        $manifest = self::manifest($root);
        if ($row === null) {
            unset($manifest[$code]);
        } else {
            $manifest[$code] = $row;
        }
        ksort($manifest);
        $file = $root . self::MANIFEST;
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        // 记录写失败不回滚语言包：文件已就位、能用；下次升级会按「版本未知」再更新一次
        if (@file_put_contents($tmp, $json, LOCK_EX) !== false && !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }
}
