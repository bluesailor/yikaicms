<?php

declare(strict_types=1);

/** 官方模板市场的只读目录、受限下载与本地版本比较。 */
final class ThemeMarket
{
    public const API = 'https://update.yikaicms.com/api/themes/list.php';
    public const MAX_PACKAGE_BYTES = 50 * 1024 * 1024;

    /**
     * @param null|callable(string):(?string) $transport 测试注入；生产环境固定请求官方地址
     * @return null|array{code:int,msg?:string,data:array{updated_at:string,themes:list<array<string,mixed>>}}
     */
    public static function request(string $query = '', ?callable $transport = null): ?array
    {
        $url = self::API . ($query !== '' ? '?q=' . rawurlencode($query) : '');
        $body = $transport !== null ? $transport($url) : self::httpGet($url);
        if (!is_string($body) || $body === '') {
            return null;
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || (int) ($decoded['code'] ?? 1) !== 0
            || !is_array($decoded['data'] ?? null) || !is_array($decoded['data']['themes'] ?? null)) {
            return null;
        }

        $themes = [];
        $seen = [];
        foreach ($decoded['data']['themes'] as $theme) {
            if (!is_array($theme)) {
                continue;
            }
            $normalized = self::normalizeCatalogTheme($theme);
            if ($normalized === null || isset($seen[$normalized['slug']])) {
                continue;
            }
            $seen[$normalized['slug']] = true;
            $themes[] = $normalized;
        }

        return [
            'code' => 0,
            'data' => [
                'updated_at' => (string) ($decoded['data']['updated_at'] ?? ''),
                'themes' => $themes,
            ],
        ];
    }

    /**
     * 将官方主题包直接流式写入文件，任一入口超过上限都立即中止并删除残留文件。
     *
     * 测试 transport 接收 URL、Content-Length 校验回调、分块写入回调和超时秒数。
     *
     * @param null|callable(string,callable(?int):bool,callable(string):int,int):array{status:int,error?:string} $transport
     * @return array{ok:bool,code:string,bytes:int}
     */
    public static function downloadPackageToFile(
        string $url,
        string $targetPath,
        int $maxBytes = self::MAX_PACKAGE_BYTES,
        int $timeout = 120,
        ?callable $transport = null
    ): array {
        if (!self::isOfficialPackageUrl($url)) {
            return self::downloadResult(false, 'invalid_url');
        }
        if ($maxBytes < 1 || $timeout < 1) {
            return self::downloadResult(false, 'invalid_limit');
        }

        $output = @fopen($targetPath, 'wb');
        if (!is_resource($output)) {
            return self::downloadResult(false, 'open_target');
        }

        $bytes = 0;
        $tooLarge = false;
        $writeFailed = false;
        $acceptLength = static function (?int $declaredBytes) use ($maxBytes, &$tooLarge): bool {
            if ($declaredBytes !== null && ($declaredBytes < 0 || $declaredBytes > $maxBytes)) {
                $tooLarge = true;
                return false;
            }
            return true;
        };
        $writeChunk = static function (string $chunk) use ($output, $maxBytes, &$bytes, &$tooLarge, &$writeFailed): int {
            $length = strlen($chunk);
            if ($length === 0) {
                return 0;
            }
            if ($bytes > $maxBytes - $length) {
                $tooLarge = true;
                return 0;
            }
            $written = @fwrite($output, $chunk);
            if (!is_int($written) || $written !== $length) {
                $writeFailed = true;
                return 0;
            }
            $bytes += $written;
            return $written;
        };

        try {
            $transfer = $transport !== null
                ? $transport($url, $acceptLength, $writeChunk, $timeout)
                : self::transferPackage($url, $acceptLength, $writeChunk, $timeout);
        } catch (Throwable $e) {
            $transfer = ['status' => 0, 'error' => $e->getMessage()];
        }
        $flushed = @fflush($output);
        @fclose($output);

        if ($tooLarge) {
            self::discardDownload($targetPath);
            return self::downloadResult(false, 'too_large', $bytes);
        }
        if ($writeFailed || !$flushed) {
            self::discardDownload($targetPath);
            return self::downloadResult(false, 'write_error', $bytes);
        }

        $status = is_array($transfer) ? (int) ($transfer['status'] ?? 0) : 0;
        $error = is_array($transfer) ? trim((string) ($transfer['error'] ?? '')) : 'invalid transport result';
        if ($status < 200 || $status >= 300 || $error !== '') {
            self::discardDownload($targetPath);
            return self::downloadResult(false, 'http_error', $bytes);
        }
        $actualBytes = @filesize($targetPath);
        if (!is_int($actualBytes) || $actualBytes !== $bytes || $actualBytes < 1 || $actualBytes > $maxBytes) {
            self::discardDownload($targetPath);
            return self::downloadResult(false, is_int($actualBytes) && $actualBytes > $maxBytes ? 'too_large' : 'size_mismatch', $bytes);
        }

        return self::downloadResult(true, 'downloaded', $bytes);
    }

    /** @param array<string,string> $localVersions */
    public static function isRemoteVersionNewer(array $localVersions, string $slug, string $remoteVersion): bool
    {
        if (preg_match('/^\d+\.\d+\.\d+$/D', $remoteVersion) !== 1) {
            return false;
        }
        $current = (string) ($localVersions[$slug] ?? '');
        return $current === '' || version_compare($remoteVersion, $current, '>');
    }

    public static function verifyPackageSignature(
        string $slug,
        string $version,
        string $hash,
        string $encodedSignature,
        string $publicKey
    ): bool {
        $normalizedHash = strtolower(trim($hash));
        $signature = base64_decode($encodedSignature, true);
        if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', $slug) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $normalizedHash) !== 1
            || $signature === false || $signature === '' || $publicKey === ''
            || !function_exists('openssl_verify')) {
            return false;
        }
        $canonical = $slug . '|' . $version . '|' . $normalizedHash;
        return openssl_verify($canonical, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /** @return array<string,string> slug => version */
    public static function localVersions(string $themesRoot): array
    {
        $versions = [];
        if (!is_dir($themesRoot)) {
            return $versions;
        }
        foreach ((array) scandir($themesRoot) as $slug) {
            if (!is_string($slug) || preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', $slug) !== 1) {
                continue;
            }
            $path = rtrim($themesRoot, '/\\') . '/' . $slug . '/theme.json';
            $meta = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
            $version = is_array($meta) ? trim((string) ($meta['version'] ?? '')) : '';
            if (preg_match('/^\d+\.\d+\.\d+$/D', $version) === 1) {
                $versions[$slug] = $version;
            }
        }
        ksort($versions);
        return $versions;
    }

    /**
     * @param array<string,string> $localVersions
     * @param list<array<string,mixed>> $marketThemes
     * @return list<array{slug:string,name:string,name_en:string,name_ja:string,current_version:string,latest_version:string}>
     */
    public static function availableUpdates(array $localVersions, array $marketThemes): array
    {
        $updates = [];
        foreach ($marketThemes as $theme) {
            $slug = (string) ($theme['slug'] ?? '');
            $latest = (string) ($theme['version'] ?? '');
            $current = $localVersions[$slug] ?? '';
            if ($current === '' || !self::isRemoteVersionNewer($localVersions, $slug, $latest)) {
                continue;
            }
            $updates[] = [
                'slug' => $slug,
                'name' => (string) ($theme['name'] ?? $slug),
                'name_en' => (string) ($theme['name_en'] ?? $theme['name'] ?? $slug),
                'name_ja' => (string) ($theme['name_ja'] ?? $theme['name'] ?? $slug),
                'current_version' => $current,
                'latest_version' => $latest,
            ];
        }
        return $updates;
    }

    /** @param array<string,mixed> $theme @return null|array<string,mixed> */
    private static function normalizeCatalogTheme(array $theme): ?array
    {
        $slug = trim((string) ($theme['slug'] ?? ''));
        $version = trim((string) ($theme['version'] ?? ''));
        $package = trim((string) ($theme['package'] ?? ''));
        $url = trim((string) ($theme['download_url'] ?? ''));
        $hash = strtolower(trim((string) ($theme['hash'] ?? '')));
        $signature = base64_decode((string) ($theme['sig'] ?? ''), true);
        $screenshot = trim((string) ($theme['screenshot'] ?? ''));
        $requiresCms = trim((string) ($theme['requires_cms'] ?? ''));
        $requiresPhp = trim((string) ($theme['requires_php'] ?? ''));
        $sizeKb = filter_var($theme['size_kb'] ?? null, FILTER_VALIDATE_INT);
        $cmsVersion = defined('CMS_VERSION') ? (string) CMS_VERSION : '';

        if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/D', $slug) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1
            || trim((string) ($theme['name'] ?? '')) === ''
            || $package !== $slug . '-v' . $version . '.zip'
            || !self::isOfficialPackageUrl($url)
            || (string) parse_url($url, PHP_URL_PATH) !== '/packages/themes/' . $package
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $hash) !== 1
            || $signature === false || $signature === ''
            || !is_int($sizeKb) || $sizeKb < 1 || $sizeKb > intdiv(self::MAX_PACKAGE_BYTES, 1024)
            || $cmsVersion === ''
            || !self::officialConstraintSatisfied($cmsVersion, $requiresCms)
            || !self::officialConstraintSatisfied(PHP_VERSION, $requiresPhp)) {
            return null;
        }

        $theme['slug'] = $slug;
        $theme['version'] = $version;
        $theme['package'] = $package;
        $theme['download_url'] = $url;
        $theme['hash'] = $hash;
        $theme['screenshot'] = self::officialScreenshotUrl($screenshot, $slug);
        $theme['requires_cms'] = $requiresCms;
        $theme['requires_php'] = $requiresPhp;
        $theme['size_kb'] = $sizeKb;
        return $theme;
    }

    private static function officialScreenshotUrl(string $url, string $slug): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        return ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'update.yikaicms.com'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && (string) ($parts['path'] ?? '') === '/assets/themes/' . $slug . '/screenshot.jpg'
            ? $url
            : '';
    }

    private static function officialConstraintSatisfied(string $actual, string $constraint): bool
    {
        if (preg_match('/^>=\s*(\d+\.\d+\.\d+)$/D', $constraint, $match) !== 1) {
            return false;
        }
        return version_compare($actual, $match[1], '>=');
    }

    private static function isOfficialPackageUrl(string $url): bool
    {
        $parts = parse_url($url);
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'update.yikaicms.com'
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
            && !isset($parts['query']) && !isset($parts['fragment'])
            && preg_match('#^/packages/themes/[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?-v\d+\.\d+\.\d+\.zip$#D', (string) ($parts['path'] ?? '')) === 1;
    }

    /**
     * @param callable(?int):bool $acceptLength
     * @param callable(string):int $writeChunk
     * @return array{status:int,error:string}
     */
    private static function transferPackage(string $url, callable $acceptLength, callable $writeChunk, int $timeout): array
    {
        if (function_exists('curl_init')) {
            return self::transferWithCurl($url, $acceptLength, $writeChunk, $timeout);
        }
        return self::transferWithFopen($url, $acceptLength, $writeChunk, $timeout);
    }

    /**
     * @param callable(?int):bool $acceptLength
     * @param callable(string):int $writeChunk
     * @return array{status:int,error:string}
     */
    private static function transferWithCurl(string $url, callable $acceptLength, callable $writeChunk, int $timeout): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'error' => 'curl initialization failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use ($acceptLength): int {
                if (preg_match('/^Content-Length:\s*(\d+)\s*$/iD', trim($line), $match) === 1
                    && !$acceptLength((int) $match[1])) {
                    return 0;
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static fn ($handle, string $chunk): int => $writeChunk($chunk),
        ]);
        $completed = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $completed === false ? curl_error($ch) : '';
        curl_close($ch);
        return ['status' => $status, 'error' => $error];
    }

    /**
     * @param callable(?int):bool $acceptLength
     * @param callable(string):int $writeChunk
     * @return array{status:int,error:string}
     */
    private static function transferWithFopen(string $url, callable $acceptLength, callable $writeChunk, int $timeout): array
    {
        if (!(bool) ini_get('allow_url_fopen')) {
            return ['status' => 0, 'error' => 'URL streams are disabled'];
        }
        $context = stream_context_create([
            'http' => ['timeout' => $timeout, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        $headers = is_array($http_response_header ?? null) ? $http_response_header : [];
        $statusLine = (string) ($headers[0] ?? '');
        $status = preg_match('#\s(\d{3})\s#', $statusLine, $match) === 1 ? (int) $match[1] : 0;
        if (!is_resource($stream)) {
            return ['status' => $status, 'error' => 'unable to open URL stream'];
        }
        foreach ($headers as $header) {
            if (preg_match('/^Content-Length:\s*(\d+)\s*$/iD', trim((string) $header), $lengthMatch) === 1
                && !$acceptLength((int) $lengthMatch[1])) {
                @fclose($stream);
                return ['status' => $status, 'error' => 'declared package size exceeds limit'];
            }
        }
        $error = self::copyStream($stream, $writeChunk);
        @fclose($stream);
        return ['status' => $status, 'error' => $error];
    }

    /** @param resource $stream @param callable(string):int $writeChunk */
    private static function copyStream($stream, callable $writeChunk): string
    {
        while (!feof($stream)) {
            $chunk = @fread($stream, 8192);
            if (!is_string($chunk)) {
                return 'stream read failed';
            }
            if ($chunk === '') {
                if (feof($stream)) {
                    break;
                }
                return 'stream ended without data';
            }
            if ($writeChunk($chunk) !== strlen($chunk)) {
                return 'stream write interrupted';
            }
        }
        return '';
    }

    private static function httpGet(string $url, int $timeout = 15): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $response = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return is_string($response) && $response !== '' && $status >= 200 && $status < 300 ? $response : null;
        }
        if (!(bool) ini_get('allow_url_fopen')) {
            return null;
        }
        $context = stream_context_create([
            'http' => ['timeout' => $timeout, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $response = @file_get_contents($url, false, $context);
        $statusLine = is_array($http_response_header ?? null) ? (string) ($http_response_header[0] ?? '') : '';
        return is_string($response) && $response !== '' && preg_match('#\s2\d\d\s#', $statusLine) === 1 ? $response : null;
    }

    /** @return array{ok:bool,code:string,bytes:int} */
    private static function downloadResult(bool $ok, string $code, int $bytes = 0): array
    {
        return compact('ok', 'code', 'bytes');
    }

    private static function discardDownload(string $targetPath): void
    {
        if (is_file($targetPath) || is_link($targetPath)) {
            @unlink($targetPath);
        }
    }
}
