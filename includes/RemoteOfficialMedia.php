<?php
/**
 * Yikai 官方远程素材库客户端。
 *
 * 客户站只通过 asset_id 请求导入；远程原图地址由官方服务解析后，
 * 本类再做白名单、签名、哈希、MIME、尺寸和体积校验，最后保存为本站媒体。
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once ROOT_PATH . '/includes/License.php';

final class RemoteOfficialMedia
{
    private const MODULE = 'official_media_library';
    private const PROVIDER = 'update.yikaicms.com';
    private const DEFAULT_API_BASE = 'https://update.yikaicms.com/api/media';
    private const MAX_BYTES = 15728640;
    private const MAX_WIDTH = 10000;
    private const MAX_HEIGHT = 10000;
    private const MAX_PIXELS = 40000000;
    private const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const ALLOWED_PURPOSES = ['banner', 'cta', 'about', 'band-bg', 'page-hero', 'service', 'product', 'contact', 'article-cover', 'icon'];
    private const ALLOWED_INDUSTRIES = ['technology', 'service', 'manufacturing', 'corporate', 'office', 'industrial', 'energy', 'medical', 'education', 'trade'];

    /** @return array<string,mixed> */
    public static function list(array $query): array
    {
        $params = [
            'page' => max(1, (int) ($query['page'] ?? 1)),
            'per_page' => min(60, max(1, (int) ($query['per_page'] ?? 24))),
            'purpose' => self::purposeFromUsage((string) ($query['purpose'] ?? ($query['usage'] ?? ''))),
            'industry' => self::cleanToken((string) ($query['industry'] ?? ''), self::ALLOWED_INDUSTRIES),
            'keyword' => self::cleanKeyword((string) ($query['keyword'] ?? '')),
            'lang' => self::cleanLang((string) ($query['lang'] ?? config('admin_lang', 'zh-CN'))),
            'key' => license_key(),
            'domain' => license_domain(),
        ];

        $url = rtrim(self::apiBase(), '/') . '/list.php?' . http_build_query($params);
        $body = self::httpRequest($url, 'GET', '', 1048576, 8);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('官方素材暂时不可用，本站媒体仍可使用');
        }

        $payload = is_array($data['data'] ?? null) ? $data['data'] : [];
        $items = [];
        foreach ((array) ($payload['items'] ?? []) as $item) {
            if (is_array($item)) {
                $items[] = self::publicItem($item);
            }
        }

        return [
            'items' => $items,
            'total' => max(0, (int) ($payload['total'] ?? count($items))),
            'page' => max(1, (int) ($payload['page'] ?? $params['page'])),
            'pages' => max(0, (int) ($payload['pages'] ?? 0)),
            'entitlement' => [
                'can_import' => self::canImport(),
                'reason' => self::entitlementReason(),
            ],
            'updated_at' => (string) ($payload['updated_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    public static function import(string $assetId, int $adminId): array
    {
        $assetId = trim($assetId);
        if (!self::validAssetId($assetId)) {
            throw new RuntimeException('素材 ID 不合法');
        }
        if (!self::canImport()) {
            throw new RuntimeException(self::entitlementMessage());
        }
        if (!db()->tableExists('media_remote_imports')) {
            throw new RuntimeException('数据库结构未升级，请先运行数据库升级');
        }

        $resolved = self::resolve($assetId);
        $version = (string) ($resolved['version'] ?? '');
        $existing = self::findExisting($assetId, $version);
        if ($existing !== null) {
            return [
                'media_id' => (int) $existing['id'],
                'url' => (string) $existing['url'],
                'name' => (string) $existing['name'],
                'imported' => false,
                'reused' => true,
            ];
        }

        $tempFile = self::downloadOriginal($resolved);
        try {
            self::verifyDownloadedFile($tempFile, $resolved);
            $finalPath = self::moveIntoUploads($tempFile, $assetId, (string) ($resolved['mime'] ?? 'image/jpeg'));
            $url = self::uploadsUrl($finalPath);
            $info = getimagesize($finalPath);
            $mediaData = [
                'name' => self::mediaName($resolved, $assetId),
                'path' => $finalPath,
                'url' => $url,
                'type' => 'image',
                'ext' => pathinfo($finalPath, PATHINFO_EXTENSION),
                'mime' => (string) ($info['mime'] ?? $resolved['mime']),
                'size' => filesize($finalPath) ?: 0,
                'width' => (int) ($info[0] ?? $resolved['width']),
                'height' => (int) ($info[1] ?? $resolved['height']),
                'md5' => md5_file($finalPath) ?: '',
                'admin_id' => $adminId,
                'created_at' => time(),
            ];

            db()->beginTransaction();
            try {
                $mediaId = (int) mediaModel()->create($mediaData);
                db()->insert('media_remote_imports', [
                    'media_id' => $mediaId,
                    'provider' => self::PROVIDER,
                    'asset_id' => $assetId,
                    'asset_version' => $version,
                    'sha256' => (string) ($resolved['sha256'] ?? ''),
                    'license_code' => (string) ($resolved['license_code'] ?? ''),
                    'attribution' => mb_substr((string) ($resolved['attribution'] ?? 'Yikai official media library'), 0, 255),
                    'imported_by' => $adminId,
                    'imported_at' => time(),
                ]);
                db()->commit();
            } catch (Throwable $e) {
                db()->rollback();
                @unlink($finalPath);
                throw $e;
            }

            return [
                'media_id' => $mediaId,
                'url' => $url,
                'name' => $mediaData['name'],
                'imported' => true,
                'reused' => false,
                'focal_point' => $resolved['focal_point'] ?? null,
                'text_tone' => $resolved['text_tone'] ?? '',
                'overlay' => $resolved['overlay'] ?? null,
            ];
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private static function apiBase(): string
    {
        $env = getenv('YIKAI_OFFICIAL_MEDIA_API_BASE');
        $base = $env !== false && trim($env) !== '' ? trim($env) : (string) config('official_media_api_base', self::DEFAULT_API_BASE);
        return self::normalizeApiBase($base);
    }

    /**
     * 收敛候选 API 地址：合规则原样（去尾斜杠）返回，否则退回官方默认值。
     * 独立成公开方法是为了能直接对各种绕过形态做断言，而不必发真实请求。
     */
    public static function normalizeApiBase(string $candidate): string
    {
        return self::apiBaseAllowed($candidate) ? rtrim($candidate, '/') : self::DEFAULT_API_BASE;
    }

    private static function apiBaseAllowed(string $base): bool
    {
        if ($base === '' || preg_match('/[\x00-\x1F\x7F]/', $base) === 1) {
            return false;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = rtrim(rawurldecode((string) ($parts['path'] ?? '')), '/');
        if ($path !== '/api/media' || str_contains($path, '..') || str_contains($path, '\\')) {
            return false;
        }
        if ($host === 'update.yikaicms.com') {
            return $scheme === 'https' && !isset($parts['port']);
        }
        return $host === '127.0.0.1'
            && getenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL') === '1'
            && in_array($scheme, ['http', 'https'], true);
    }

    private static function canImport(): bool
    {
        $state = license();
        return license_service_active($state) && in_array(self::MODULE, (array) ($state['modules'] ?? []), true);
    }

    private static function entitlementReason(): string
    {
        $state = license();
        if (empty($state['modules'])) {
            return (string) ($state['reason'] ?? 'no_key');
        }
        if (!in_array(self::MODULE, (array) ($state['modules'] ?? []), true)) {
            return 'module_missing';
        }
        if (!license_service_active($state)) {
            return 'expired';
        }
        return 'ok';
    }

    private static function entitlementMessage(): string
    {
        return self::entitlementReason() === 'expired'
            ? '服务期已到期。已导入素材不受影响，续费后可继续导入官方素材。'
            : '当前版本可预览；开通官方素材服务后可导入原图';
    }

    private static function purposeFromUsage(string $usage): string
    {
        $usage = strtolower(trim($usage));
        $map = [
            'hero-bg' => 'banner',
            'banner' => 'banner',
            'slider' => 'banner',
            'cta' => 'cta',
            'about' => 'about',
            'band-bg' => 'band-bg',
            'section-bg' => 'band-bg',
            'page-hero' => 'page-hero',
        ];
        return self::cleanToken($map[$usage] ?? $usage, self::ALLOWED_PURPOSES);
    }

    private static function cleanToken(string $value, array $allowed): string
    {
        $value = strtolower(trim($value));
        if ($value === '' || $value === 'all') {
            return '';
        }
        return in_array($value, $allowed, true) ? $value : '';
    }

    private static function cleanKeyword(string $value): string
    {
        $value = trim(strip_tags($value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
        return mb_substr($value, 0, 60);
    }

    private static function cleanLang(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['zh-cn', 'zh', 'en', 'ja'], true) ? $value : 'zh-CN';
    }

    private static function validAssetId(string $assetId): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]{1,98}[a-z0-9]$/', $assetId) === 1;
    }

    /** @return array<string,mixed> */
    private static function publicItem(array $item): array
    {
        $out = [];
        foreach ([
            'id', 'version', 'name', 'name_en', 'name_ja', 'description', 'description_en', 'description_ja',
            'purposes', 'industries', 'keywords', 'width', 'height', 'aspect', 'focal_point', 'safe_area',
            'text_tone', 'overlay', 'preview_url', 'preview_large_url', 'license_code', 'attribution', 'updated_at',
        ] as $field) {
            if (array_key_exists($field, $item)) {
                $out[$field] = $item[$field];
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function resolve(string $assetId): array
    {
        $body = http_build_query([
            'asset_id' => $assetId,
            'key' => license_key(),
            'domain' => license_domain(),
        ]);
        $url = rtrim(self::apiBase(), '/') . '/resolve.php';
        $json = self::httpRequest($url, 'POST', $body, 1048576, 15, [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ]);
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || (int) ($decoded['code'] ?? 1) !== 0 || !is_array($decoded['data'] ?? null)) {
            throw new RuntimeException((string) ($decoded['msg'] ?? '官方素材暂时不可用'));
        }
        $data = $decoded['data'];
        self::verifyResolvePayload($data, $assetId);
        return $data;
    }

    private static function verifyResolvePayload(array $data, string $assetId): void
    {
        $sha256 = strtolower((string) ($data['sha256'] ?? ''));
        $version = (string) ($data['version'] ?? '');
        $width = (int) ($data['width'] ?? 0);
        $height = (int) ($data['height'] ?? 0);
        $size = (int) ($data['size'] ?? 0);
        $mime = strtolower((string) ($data['mime'] ?? ''));
        $signature = (string) ($data['signature'] ?? '');

        if ((string) ($data['asset_id'] ?? '') !== $assetId || $version === '') {
            throw new RuntimeException('素材解析结果不一致');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new RuntimeException('素材完整性元数据缺失');
        }
        if (!isset(self::ALLOWED_MIME[$mime])) {
            throw new RuntimeException('素材格式暂不支持');
        }
        if ($size < 1 || $size > self::MAX_BYTES || $size > UPLOAD_MAX_SIZE) {
            throw new RuntimeException('图片超过本站文件大小限制');
        }
        if ($width < 1 || $height < 1 || $width > self::MAX_WIDTH || $height > self::MAX_HEIGHT || ($width * $height) > self::MAX_PIXELS) {
            throw new RuntimeException('图片超过本站像素或尺寸限制');
        }
        if (!self::downloadUrlAllowed((string) ($data['download_url'] ?? ''))) {
            throw new RuntimeException('素材下载地址不符合安全策略');
        }
        if (!self::verifySignature($assetId, $version, $sha256, $width, $height, $signature)) {
            throw new RuntimeException('素材完整性验证失败，已停止导入');
        }
    }

    private static function verifySignature(string $assetId, string $version, string $sha256, int $width, int $height, string $signature): bool
    {
        if (!function_exists('openssl_verify')) {
            return false;
        }
        $decoded = base64_decode($signature, true);
        if ($decoded === false || $decoded === '') {
            return false;
        }
        $canonical = $assetId . '|' . $version . '|' . $sha256 . '|' . $width . '|' . $height;
        return openssl_verify($canonical, $decoded, license_pubkey(), OPENSSL_ALGO_SHA256) === 1;
    }

    private static function downloadUrlAllowed(string $url): bool
    {
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($host, ['update.yikaicms.com', 'media.yikaicms.com', '127.0.0.1'], true)) {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])) {
            return false;
        }
        if ($host === '127.0.0.1' && getenv('YIKAI_OFFICIAL_MEDIA_ALLOW_LOCAL') !== '1') {
            return false;
        }
        $path = rawurldecode((string) ($parts['path'] ?? ''));
        return str_starts_with($path, '/packages/media/originals/') && !str_contains($path, '..') && !str_contains($path, '\\');
    }

    /** @return array<string,mixed>|null */
    private static function findExisting(string $assetId, string $version): ?array
    {
        $mapping = db()->fetchOne(
            "SELECT id, media_id FROM " . DB_PREFIX . "media_remote_imports
             WHERE provider = ? AND asset_id = ? AND asset_version = ? LIMIT 1",
            [self::PROVIDER, $assetId, $version]
        );
        if (!$mapping) {
            return null;
        }
        $media = mediaModel()->find((int) $mapping['media_id']);
        if ($media) {
            return $media;
        }

        // 删除流程若曾中断，旧映射不能永久卡住同版本素材的再次导入。
        db()->delete('media_remote_imports', 'id = ?', [(int) $mapping['id']]);
        return null;
    }

    /** @param array<string,mixed> $resolved */
    private static function downloadOriginal(array $resolved): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'yk-media-');
        if ($temp === false) {
            throw new RuntimeException('无法创建临时文件');
        }
        try {
            $body = self::httpRequest((string) $resolved['download_url'], 'GET', '', self::MAX_BYTES + 1, 60);
            file_put_contents($temp, $body, LOCK_EX);
            return $temp;
        } catch (Throwable $e) {
            @unlink($temp);
            throw $e;
        }
    }

    /** @param array<string,mixed> $resolved */
    private static function verifyDownloadedFile(string $path, array $resolved): void
    {
        $size = filesize($path) ?: 0;
        if ($size < 1 || $size > self::MAX_BYTES || $size > UPLOAD_MAX_SIZE) {
            throw new RuntimeException('图片超过本站文件大小限制');
        }
        if (hash_file('sha256', $path) !== strtolower((string) $resolved['sha256'])) {
            throw new RuntimeException('素材完整性验证失败，已停止导入');
        }
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException('无效的图片文件');
        }
        $mime = strtolower((string) ($info['mime'] ?? ''));
        if (!isset(self::ALLOWED_MIME[$mime]) || $mime !== strtolower((string) $resolved['mime'])) {
            throw new RuntimeException('素材格式暂不支持');
        }
        if ((int) ($info[0] ?? 0) !== (int) $resolved['width'] || (int) ($info[1] ?? 0) !== (int) $resolved['height']) {
            throw new RuntimeException('素材尺寸验证失败');
        }
    }

    private static function moveIntoUploads(string $tempFile, string $assetId, string $mime): string
    {
        $ext = self::ALLOWED_MIME[strtolower($mime)] ?? 'jpg';
        $dir = UPLOADS_PATH . 'images/' . date('Ym') . '/';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('无法保存到本站上传目录，请检查目录权限');
        }
        $target = $dir . $assetId . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
        if (!rename($tempFile, $target)) {
            throw new RuntimeException('无法保存到本站上传目录，请检查目录权限');
        }
        return $target;
    }

    private static function uploadsUrl(string $path): string
    {
        $root = str_replace('\\', '/', rtrim(ROOT_PATH, '/\\'));
        $normalized = str_replace('\\', '/', $path);
        return substr($normalized, 0, strlen($root)) === $root ? substr($normalized, strlen($root)) : $normalized;
    }

    /** @param array<string,mixed> $resolved */
    private static function mediaName(array $resolved, string $assetId): string
    {
        $name = trim((string) ($resolved['name'] ?? ''));
        return $name !== '' ? mb_substr($name, 0, 255) : $assetId . '.' . self::ALLOWED_MIME[strtolower((string) ($resolved['mime'] ?? 'image/jpeg'))];
    }

    /** @param list<string> $headers */
    private static function httpRequest(string $url, string $method, string $body = '', int $maxBytes = 1048576, int $timeout = 10, array $headers = []): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $buffer = '';
            $headers[] = 'Accept: application/json';
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $method === 'POST' ? $body : null,
                CURLOPT_HTTPHEADER => array_values(array_unique($headers)),
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, $maxBytes): int {
                    $buffer .= $chunk;
                    return strlen($buffer) > $maxBytes ? 0 : strlen($chunk);
                },
            ]);
            $ok = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($ok === false || $code >= 300 || $code < 200) {
                throw new RuntimeException($err !== '' ? $err : '官方素材暂时不可用，本站媒体仍可使用');
            }
            if (strlen($buffer) > $maxBytes) {
                throw new RuntimeException('官方素材响应超过限制');
            }
            return $buffer;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
                'max_redirects' => 0,
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $method === 'POST' ? $body : '',
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $result = @file_get_contents($url, false, $context, 0, $maxBytes + 1);
        if ($result === false) {
            throw new RuntimeException('官方素材暂时不可用，本站媒体仍可使用');
        }
        if (strlen($result) > $maxBytes) {
            throw new RuntimeException('官方素材响应超过限制');
        }
        return $result;
    }
}
