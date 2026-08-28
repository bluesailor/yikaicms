<?php
/** Blox 远程模板市场客户端：目录发现、授权状态与签名包解析。 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/security.php';

final class BloxRemoteTemplateProvider
{
    public const API_URL = 'https://update.yikaicms.com/api/templates/list.php';
    private const PROVIDER = 'update.yikaicms.com';
    private const MAX_CATALOG_BYTES = 1_000_000;
    private const MAX_PACKAGE_BYTES = 5_000_000;
    private const MAX_ITEMS = 500;
    private const CATALOG_TTL = 604800;
    private const ENTITLEMENT_TTL = 60;
    /**
     * 拉取失败的负缓存窗口：远程不可达时（15s 超时）不做负缓存的话，
     * 模板管理页每次加载都同步挂满超时——共享主机上是管理员每次进页卡 15 秒，
     * CI 上曾把 9s 的 e2e 用例拖过 45s 超时线（外网抖动 × 页面多次导航）。
     */
    private const FAILURE_TTL = 120;

    private Closure $httpGet;
    private Closure $verifySignature;
    private string $language;
    private string $endpoint;
    private ?Closure $cacheGet;
    private ?Closure $cacheSet;

    public function __construct(
        ?Closure $httpGet = null,
        ?Closure $verifySignature = null,
        ?string $language = null,
        string $endpoint = self::API_URL,
        ?Closure $cacheGet = null,
        ?Closure $cacheSet = null
    ) {
        $this->httpGet = $httpGet ?? static fn (string $url, int $timeout, int $maxBytes): ?string
            => self::request($url, $timeout, $maxBytes);
        $this->verifySignature = $verifySignature ?? static function (string $canonical, string $signature): bool {
            $decoded = base64_decode($signature, true);
            return $decoded !== false
                && $decoded !== ''
                && function_exists('openssl_verify')
                && function_exists('license_pubkey')
                && openssl_verify($canonical, $decoded, license_pubkey(), OPENSSL_ALGO_SHA256) === 1;
        };
        $this->language = $language ?? (function_exists('getLang') ? getLang() : 'zh-CN');
        $this->endpoint = $endpoint;
        $useDefaultCache = $httpGet === null && $endpoint === self::API_URL;
        $this->cacheGet = $cacheGet ?? ($useDefaultCache && function_exists('cacheGet')
            ? static fn (string $key): mixed => cacheGet($key)
            : null);
        $this->cacheSet = $cacheSet ?? ($useDefaultCache && function_exists('cacheSet')
            ? static function (string $key, mixed $value, int $ttl): void {
                cacheSet($key, $value, $ttl);
            }
            : null);
    }

    /**
     * @return list<array<string,mixed>>
     * @psalm-suppress UnusedParam （$context 是本地/插件/远程三类来源的统一签名；远程目录按 context 过滤需服务端先在 list.php 返回该字段，接入前保留参数不改调用方。）
     */
    public function items(string $context = 'page', bool $forceRefresh = false): array
    {
        $catalog = $this->catalog($forceRefresh);
        $items = [];
        foreach ($catalog['templates'] as $raw) {
            $item = $this->normalizeItem($raw, $catalog['updated_at']);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * 官方模板全类型目录（r16：含 header/footer——编辑器插入面板仍走 items() 窄类型，
     * 头尾资产经管理页「官方模板库」一键安装落库，不混入正文插入动线）。
     *
     * @return list<array<string,mixed>>
     */
    public function installable(bool $forceRefresh = false): array
    {
        // 审计 r17-2：目录携带 entitled/locked 授权态——安装场景用 60 秒短 TTL 复验
        // （新授权用户不再最长 7 天看到锁定态）；编辑器插入目录 items() 的 7 天
        // 元数据缓存不受影响。forceRefresh 供管理页「刷新授权状态」按钮直穿。
        $catalog = $this->catalog($forceRefresh, self::ENTITLEMENT_TTL);
        $items = [];
        foreach ($catalog['templates'] as $raw) {
            $item = $this->normalizeItem($raw, $catalog['updated_at'], true);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * 下载并验证官方模板包，返回包 JSON 原文（安装走 BloxTemplateImporter::importJson，
     * 与文件导入同一安全链）。复用 resolve 同一 hash+RSA 签名校验。
     * @psalm-suppress PossiblyUnusedMethod 保留给插件侧只需要 JSON 的兼容入口。
     */
    public function fetchPackageJson(string $slug): string
    {
        [, $json] = $this->verifiedPackage($slug);
        return $json;
    }

    /** @return array{item:array<string,mixed>,json:string} */
    public function fetchVerifiedPackage(string $slug): array
    {
        [$item, $json] = $this->verifiedPackage($slug);
        return ['item' => $item, 'json' => $json];
    }

    /**
     * @return array{key:string,type:string,name:string,source:string,provider:string,sections:array<int,array<string,mixed>>}
     * @psalm-suppress UnusedParam （$context 是本地/插件/远程三类来源的统一签名；远程目录按 context 过滤需服务端先在 list.php 返回该字段，接入前保留参数不改调用方。）
     */
    public function resolve(string $slug, string $context = 'page'): array
    {
        [$item, $json] = $this->verifiedPackage($slug);

        BuilderRegistry::boot();
        $prepared = BloxTemplateImporter::prepare($json);
        if ($prepared['type'] !== $item['type']) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }

        return [
            'key' => 'remote:' . $slug,
            'type' => $prepared['type'],
            'name' => $item['name'],
            'source' => 'remote',
            'provider' => self::PROVIDER,
            'sections' => $prepared['sections'],
        ];
    }

    /**
     * 目录定位 + 下载 + hash + RSA 签名 + 包内 source_ref 复核（resolve 与
     * fetchPackageJson 的共用安检段）。@return array{0:array<string,mixed>,1:string}
     */
    private function verifiedPackage(string $slug): array
    {
        if (!$this->validSlug($slug)) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }

        $catalog = $this->catalog(false, self::ENTITLEMENT_TTL);
        $raw = null;
        foreach ($catalog['templates'] as $candidate) {
            if ((string) ($candidate['slug'] ?? '') === $slug) {
                $raw = $candidate;
                break;
            }
        }
        if (!is_array($raw)) {
            throw new RuntimeException(__('blox_template_remote_missing'));
        }

        $item = $this->normalizeItem($raw, $catalog['updated_at'], true);
        if ($item === null) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }
        if (!empty($item['locked'])) {
            throw new RuntimeException($this->lockedMessage((string) ($item['locked_reason'] ?? '')));
        }

        $version = trim((string) ($raw['version'] ?? ''));
        $hash = strtolower(trim((string) ($raw['hash'] ?? '')));
        $signature = trim((string) ($raw['sig'] ?? ''));
        $downloadUrl = trim((string) ($raw['download_url'] ?? ''));
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,49}$/', $version) !== 1
            || preg_match('/^sha256:([a-f0-9]{64})$/', $hash, $hashMatch) !== 1
            || $signature === ''
            || !$this->safeDownloadUrl($downloadUrl)) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }

        $body = ($this->httpGet)($downloadUrl, 120, self::MAX_PACKAGE_BYTES);
        if (!is_string($body) || $body === '') {
            throw new RuntimeException(__('blox_template_remote_download_failed'));
        }
        if (!hash_equals($hashMatch[1], hash('sha256', $body))) {
            throw new RuntimeException(__('blox_template_remote_hash_failed'));
        }
        $canonical = $slug . '|' . $version . '|' . $hash;
        if (!(bool) ($this->verifySignature)($canonical, $signature)) {
            throw new RuntimeException(__('blox_template_remote_signature_failed'));
        }

        $json = $this->templateJson($body);
        try {
            $envelope = json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }
        // 审计 r17-4/5：两项一致性下沉到共用安检段（resolve 与安装真正同一段）——
        // ① 包内 source_ref 必填且必须等于目录 slug（此前空值放行，打包错误无法在
        //    客户端稳定发现；存量 9 包已核验全部携带，必填化无兼容代价）；
        // ② 包声明的业务 type 必须等于目录声明的 type（签名只证明"包由服务器签发"，
        //    不证明 registry 与包的业务字段一致——registry 写 header、包内误写 page
        //    此前会以 page 落库）。
        $sourceRef = is_array($envelope) && is_array($envelope['meta'] ?? null)
            ? trim((string) ($envelope['meta']['source_ref'] ?? ''))
            : '';
        if ($sourceRef !== $slug) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }
        $packageType = is_array($envelope) ? trim((string) ($envelope['type'] ?? '')) : '';
        if ($packageType !== (string) $item['type']) {
            throw new RuntimeException(__('blox_template_remote_invalid'));
        }

        return [$item, $json];
    }

    /** @return array{updated_at:string,fetched_at:int,templates:list<array<string,mixed>>} */
    /** 记录一次远程拉取失败（负缓存），FAILURE_TTL 窗口内 catalog() 直接短路 */
    private function rememberFailure(string $cacheKey): void
    {
        if ($this->cacheSet !== null) {
            ($this->cacheSet)($cacheKey . ':fail', time(), self::FAILURE_TTL);
        }
    }

    private function catalog(bool $forceRefresh = false, int $maxAge = self::CATALOG_TTL): array
    {
        $licenseKey = function_exists('license_key') ? license_key() : '';
        $licenseDomain = function_exists('license_domain') ? license_domain() : '';
        $query = array_filter([
            'key' => $licenseKey,
            'domain' => $licenseDomain,
        ], static fn (string $value): bool => $value !== '');
        $cacheKey = 'blox_remote_templates:' . hash(
            'sha256',
            $this->endpoint . '|' . $licenseKey . '|' . $licenseDomain
        );
        if ($this->cacheGet !== null && !$forceRefresh) {
            $cached = ($this->cacheGet)($cacheKey);
            $fetchedAt = is_array($cached) ? (int) ($cached['fetched_at'] ?? 0) : 0;
            if (is_array($cached)
                && $fetchedAt >= time() - max(0, $maxAge)
                && is_string($cached['updated_at'] ?? null)
                && is_array($cached['templates'] ?? null)
                && count($cached['templates']) <= self::MAX_ITEMS) {
                return [
                    'updated_at' => $cached['updated_at'],
                    'fetched_at' => $fetchedAt,
                    'templates' => array_values(array_filter($cached['templates'], 'is_array')),
                ];
            }
            // 负缓存命中：短窗口内不再重试远程（管理页「刷新」按钮 forceRefresh 直穿）
            if ((int) ($this->cacheGet)($cacheKey . ':fail') >= time() - self::FAILURE_TTL) {
                throw new RuntimeException(__('blox_template_remote_unavailable'));
            }
        }

        $url = $this->endpoint . ($query !== [] ? '?' . http_build_query($query) : '');
        $response = ($this->httpGet)($url, 15, self::MAX_CATALOG_BYTES);
        if (!is_string($response) || $response === '') {
            $this->rememberFailure($cacheKey);
            throw new RuntimeException(__('blox_template_remote_unavailable'));
        }
        try {
            $decoded = json_decode($response, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->rememberFailure($cacheKey);
            throw new RuntimeException(__('blox_template_remote_unavailable'));
        }
        $templates = is_array($decoded) && (int) ($decoded['code'] ?? 1) === 0
            ? ($decoded['data']['templates'] ?? null)
            : null;
        if (!is_array($templates) || count($templates) > self::MAX_ITEMS) {
            $this->rememberFailure($cacheKey);
            throw new RuntimeException(__('blox_template_remote_unavailable'));
        }
        $catalog = [
            'updated_at' => trim((string) ($decoded['data']['updated_at'] ?? '')),
            'fetched_at' => time(),
            'templates' => array_values(array_filter($templates, 'is_array')),
        ];
        if ($this->cacheSet !== null) {
            ($this->cacheSet)($cacheKey, $catalog, self::CATALOG_TTL);
        }
        return $catalog;
    }
    /** @param array<string,mixed> $raw @return array<string,mixed>|null */
    private function normalizeItem(array $raw, string $updatedAt, bool $allTypes = false): ?array
    {
        $slug = trim((string) ($raw['slug'] ?? ''));
        $type = trim((string) ($raw['type'] ?? ''));
        $allowed = $allTypes ? ['section', 'page', 'header', 'footer', 'popup'] : ['section', 'page'];
        if (!$this->validSlug($slug) || !in_array($type, $allowed, true)) {
            return null;
        }
        $paid = !empty($raw['paid']) || (string) ($raw['tier'] ?? 'free') !== 'free';
        $entitled = array_key_exists('entitled', $raw)
            ? !empty($raw['entitled'])
            : (!$paid || !empty($raw['download_url']));
        return [
            'key' => 'remote:' . $slug,
            'type' => $type,
            'name' => $this->localized($raw, 'name', $slug),
            'description' => $this->localized($raw, 'description', ''),
            'source' => 'remote',
            'provider' => self::PROVIDER,
            'category' => $this->safeCategory($raw['category'] ?? $type, $type),
            'thumbnail' => $this->safeThumbnail($raw['thumbnail'] ?? $raw['thumbnail_url'] ?? ''),
            'metadata' => BloxSectionMetadata::normalize($raw['metadata'] ?? $raw['meta'] ?? []),
            'version' => trim((string) ($raw['version'] ?? '')),
            'paid' => $paid,
            'locked' => !$entitled,
            'locked_reason' => trim((string) ($raw['locked_reason'] ?? '')),
            'updated_at' => $updatedAt !== '' ? max(0, (int) strtotime($updatedAt)) : 0,
        ];
    }

    /** @param array<string,mixed> $raw */
    private function localized(array $raw, string $field, string $fallback): string
    {
        $suffix = match ($this->language) {
            'en' => '_en',
            'ja' => '_ja',
            default => '',
        };
        $value = trim((string) ($raw[$field . $suffix] ?? ''));
        return $value !== '' ? $value : (trim((string) ($raw[$field] ?? '')) ?: $fallback);
    }

    private function lockedMessage(string $reason): string
    {
        return match ($reason) {
            'license_expired' => __('blox_template_locked_expired'),
            'module_missing' => __('blox_template_locked_module'),
            default => __('blox_template_locked_license'),
        };
    }

    private function validSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9-]{0,98}[a-z0-9])?$/', $slug) === 1;
    }

    private function safeCategory(mixed $raw, string $fallback): string
    {
        $category = is_string($raw) ? strtolower(trim($raw)) : '';
        return preg_match('/^[a-z][a-z0-9-]{0,49}$/', $category) === 1 ? $category : $fallback;
    }

    private function safeThumbnail(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }
        $value = trim($raw);
        if ($value === '' || strlen($value) > 500 || str_contains($value, "\\")) {
            return '';
        }
        if (str_starts_with($value, '/')) {
            $value = 'https://' . self::PROVIDER . $value;
        }
        $parts = parse_url($value);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::PROVIDER
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        if (str_contains($path, '..')
            || preg_match('#^/(?:assets|uploads)/templates/[a-zA-Z0-9/_-]+\.(?:avif|gif|jpe?g|png|webp)$#', $path) !== 1) {
            return '';
        }
        return $value;
    }

    private function safeDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::PROVIDER
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['port'])) {
            return false;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        return !str_contains($path, '..')
            && preg_match('#^/packages/templates/[a-z0-9][a-z0-9._-]*\.zip$#', $path) === 1;
    }

    private function templateJson(string $package): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException(__('blox_template_remote_zip_missing'));
        }
        $tmp = tempnam(sys_get_temp_dir(), 'ykbtpl');
        if ($tmp === false || file_put_contents($tmp, $package) === false) {
            if (is_string($tmp)) @unlink($tmp);
            throw new RuntimeException(__('blox_template_remote_download_failed'));
        }
        $zip = new ZipArchive();
        $opened = false;
        try {
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException(__('blox_template_remote_invalid'));
            }
            $opened = true;
            if ($zip->numFiles !== 1
                || (function_exists('zipUnsafeEntry') && zipUnsafeEntry($zip) !== null)) {
                throw new RuntimeException(__('blox_template_remote_invalid'));
            }
            $stat = $zip->statIndex(0);
            if (!is_array($stat)
                || (string) ($stat['name'] ?? '') !== 'template.json'
                || (int) ($stat['size'] ?? 0) <= 0
                || (int) ($stat['size'] ?? 0) > BloxTemplateImporter::MAX_BYTES) {
                throw new RuntimeException(__('blox_template_remote_invalid'));
            }
            $json = $zip->getFromIndex(0);
            if (!is_string($json) || $json === '') {
                throw new RuntimeException(__('blox_template_remote_invalid'));
            }
            return $json;
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($tmp);
        }
    }
    private static function request(string $url, int $timeout, int $maxBytes): ?string
    {
        if (function_exists('curl_init')) {
            $body = '';
            $tooLarge = false;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $ok !== false && !$tooLarge && $status >= 200 && $status < 300 && $body !== '' ? $body : null;
        }
        if (ini_get('allow_url_fopen')) {
            $context = stream_context_create([
                'http' => ['timeout' => $timeout, 'ignore_errors' => false, 'header' => "Accept: application/json\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
            return is_string($body) && $body !== '' && strlen($body) <= $maxBytes ? $body : null;
        }
        return null;
    }
}
