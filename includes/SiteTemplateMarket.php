<?php
declare(strict_types=1);

require_once __DIR__ . '/SiteTemplateArchive.php';

/** Official whole-site packages. This catalog never applies or trusts a package for the administrator. */
final class SiteTemplateMarket
{
    public const API = 'https://update.yikaicms.com/api/site-templates/list.php';
    public const MAX_CATALOG_BYTES = 524288;
    public const CACHE_SECONDS = 60;
    public const SIGNATURE_PROTOCOL = 2;

    /** @return null|array{updated_at:string,templates:list<array<string,mixed>>} */
    public static function request(?callable $transport = null): ?array
    {
        $url = self::API . '?' . http_build_query([
            'protocol_version' => 1, 'cms_version' => defined('CMS_VERSION') ? CMS_VERSION : '',
            'php_version' => PHP_VERSION, 'format_versions' => implode(',', SiteTemplateArchive::SUPPORTED_VERSIONS),
        ], '', '&', PHP_QUERY_RFC3986);
        if ($transport !== null) {
            $body = $transport($url);
        } else {
            $body = '';
            $result = self::transfer($url, static fn(?int $size): bool => $size === null || $size <= self::MAX_CATALOG_BYTES,
                static function (string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_CATALOG_BYTES) return 0;
                    $body .= $chunk;
                    return strlen($chunk);
                }, 5);
            if ($result['status'] !== 200 || $result['error'] !== '') return null;
        }
        if (!is_string($body) || strlen($body) > self::MAX_CATALOG_BYTES) return null;
        $decoded = json_decode($body, true, 32);
        if (!is_array($decoded) || ($decoded['code'] ?? null) !== 0 || !is_array($decoded['data'] ?? null)
            || ($decoded['data']['protocol_version'] ?? null) !== 1 || !is_array($decoded['data']['templates'] ?? null)
            || count($decoded['data']['templates']) > 200) return null;
        $items = [];
        $seen = [];
        foreach ($decoded['data']['templates'] as $entry) {
            $item = is_array($entry) ? self::normalize($entry) : null;
            if ($item === null || isset($seen[$item['slug']])) continue;
            $seen[$item['slug']] = true;
            $items[] = $item;
        }
        return ['updated_at' => is_string($decoded['data']['updated_at'] ?? null) ? $decoded['data']['updated_at'] : '', 'templates' => $items];
    }

    /** @param array<string,mixed> $entry @return null|array<string,mixed> */
    public static function normalize(array $entry): ?array
    {
        foreach (['slug', 'name', 'version', 'cms', 'category', 'requires_php', 'status'] as $key) {
            if (!is_string($entry[$key] ?? null) || strlen($entry[$key]) > 200) return null;
        }
        if (!is_int($entry['format_version'] ?? null) || $entry['format_version'] < 1) return null;
        $slug = $entry['slug'];
        $version = $entry['version'];
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,78}[a-z0-9])?$/D', $slug) !== 1
            || trim($entry['name']) === ''
            || preg_match('/^\d+\.\d+\.\d+$/D', $version) !== 1
            || preg_match('/^\d+\.\d+\.\d+$/D', $entry['cms']) !== 1
            || preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $entry['category']) !== 1
            || preg_match('/^>=([0-9]+\.[0-9]+\.[0-9]+)$/D', $entry['requires_php']) !== 1
            || !in_array($entry['status'], ['draft', 'published'], true)) return null;
        $item = $entry;
        foreach (['name_en', 'name_ja', 'description', 'description_en', 'description_ja', 'category_name', 'category_name_en', 'category_name_ja'] as $key) {
            $item[$key] = is_string($entry[$key] ?? null) ? mb_substr($entry[$key], 0, 1200) : '';
        }
        $image = $entry['screenshot'] ?? '';
        $prefix = 'https://update.yikaicms.com/assets/site-templates/' . $slug . '/' . $version . '/preview.';
        $item['screenshot'] = is_string($image) && in_array($image, [$prefix . 'webp', $prefix . 'jpg', $prefix . 'png'], true) ? $image : '';
        $item['demo_url'] = self::demoUrl($entry['demo_url'] ?? '');
        $tier = $entry['tier'] ?? null;
        if (!is_string($tier) || !in_array($tier, ['free', 'pro'], true)) return null;
        $item['tier'] = $tier;
        $item['blocked_reason'] = $entry['status'] !== 'published' ? 'st_market_pending' : '';
        if ($tier !== 'free') $item['blocked_reason'] = 'st_market_license';
        if (!SiteTemplateArchive::cmsCompatible($entry['cms'])) $item['blocked_reason'] = 'st_market_cms';
        if (!version_compare(PHP_VERSION, substr($entry['requires_php'], 2), '>=')) $item['blocked_reason'] = 'st_market_php';
        if (!in_array($entry['format_version'], SiteTemplateArchive::SUPPORTED_VERSIONS, true)) $item['blocked_reason'] = 'st_market_format';
        $package = $slug . '-site-v' . $version . '.zip';
        $download = 'https://update.yikaicms.com/packages/site-templates/' . $package;
        $hash = is_string($entry['hash'] ?? null) ? strtolower($entry['hash']) : '';
        $signature = is_string($entry['sig'] ?? null) ? base64_decode($entry['sig'], true) : false;
        $size = $entry['size_bytes'] ?? null;
        $deliverable = ($entry['package'] ?? '') === $package && ($entry['download_url'] ?? '') === $download
            && preg_match('/^sha256:[a-f0-9]{64}$/D', $hash) === 1 && is_string($signature) && $signature !== ''
            && is_int($size) && $size > 0 && $size <= SiteTemplateArchive::MAX_ZIP;
        if (!$deliverable && $item['blocked_reason'] === '') $item['blocked_reason'] = 'st_market_pending';
        $item['download_url'] = $item['blocked_reason'] === '' ? $download : '';
        $item['hash'] = $item['blocked_reason'] === '' ? $hash : '';
        $item['sig'] = $item['blocked_reason'] === '' ? (string) $entry['sig'] : '';
        $item['size_bytes'] = $item['blocked_reason'] === '' ? $size : 0;
        return $item;
    }

    /**
     * Protocol v2 binds every field that can change authorization or downloaded bytes.
     * There is deliberately no v1 fallback: catalogs must be re-signed before publication.
     */
    public static function canonical(array $item): string
    {
        return 'site-template-v' . self::SIGNATURE_PROTOCOL . '|' . (string) ($item['slug'] ?? '')
            . '|' . (string) ($item['version'] ?? '') . '|' . (string) ($item['cms'] ?? '')
            . '|' . (string) ($item['format_version'] ?? 0) . '|' . (string) ($item['requires_php'] ?? '')
            . '|' . (string) ($item['tier'] ?? '')
            . '|' . (string) ($item['status'] ?? '') . '|' . (string) ($item['package'] ?? '')
            . '|' . (string) ($item['size_bytes'] ?? 0) . '|' . (string) ($item['hash'] ?? '');
    }

    public static function verifySignature(array $item, string $publicKey): bool
    {
        $normalized = self::normalize($item);
        if ($normalized === null || $normalized['blocked_reason'] !== '' || $publicKey === '' || !function_exists('openssl_verify')) return false;
        $signature = base64_decode((string) $normalized['sig'], true);
        return is_string($signature) && openssl_verify(self::canonical($normalized), $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    /**
     * The caller owns the temporary path. Failed transfers or verification remove it.
     * @param null|callable(string,callable(?int):bool,callable(string):int,int):array{status:int,error:string} $transport
     */
    public static function download(array $item, string $target, string $publicKey, ?callable $transport = null): void
    {
        $normalized = self::normalize($item);
        if ($normalized === null || $normalized['blocked_reason'] !== '') throw new RuntimeException('st_market_pending');
        // Authenticate delivery metadata before contacting the package endpoint.
        if (!self::verifySignature($normalized, $publicKey)) throw new RuntimeException('st_market_signature');
        $maximum = (int) $normalized['size_bytes'];
        $output = @fopen($target, 'wb');
        if (!is_resource($output)) throw new RuntimeException('st_storage');
        $bytes = 0;
        $failed = false;
        $accept = static fn(?int $length): bool => $length === null || ($length > 0 && $length <= $maximum);
        $write = static function (string $chunk) use ($output, $maximum, &$bytes, &$failed): int {
            if ($bytes + strlen($chunk) > $maximum) { $failed = true; return 0; }
            $count = @fwrite($output, $chunk);
            if (!is_int($count) || $count !== strlen($chunk)) { $failed = true; return 0; }
            $bytes += $count;
            return $count;
        };
        try {
            $result = $transport !== null ? $transport((string) $normalized['download_url'], $accept, $write, 60)
                : self::transfer((string) $normalized['download_url'], $accept, $write, 60);
            if (!@fflush($output) || $failed || $result['status'] !== 200 || $result['error'] !== '' || $bytes !== $maximum) {
                throw new RuntimeException('st_market_download');
            }
            @fclose($output);
            $output = null;
            if (!hash_equals(substr((string) $normalized['hash'], 7), (string) hash_file('sha256', $target))) throw new RuntimeException('st_market_hash');
        } catch (Throwable $error) {
            if (is_resource($output)) @fclose($output);
            @unlink($target);
            throw $error;
        }
    }

    /** Bind the authenticated market identity to the archive before passing it to the existing importer. */
    /** 演示站地址：只接受 https://demo.yikaicms.com/<目录>/，其余一律不显示按钮。 */
    public static function demoUrl(mixed $url): string
    {
        return is_string($url) && preg_match('#^https://demo\.yikaicms\.com/[a-z0-9][a-z0-9-]{0,79}/$#D', $url) === 1 ? $url : '';
    }

    public static function verifyArchive(string $path, array $item): void
    {
        $package = SiteTemplateArchive::inspect($path);
        $manifest = $package['manifest'];
        $meta = json_decode(SiteTemplateArchive::entry($path, $manifest, 'theme/theme.json'), true);
        if (($manifest['theme'] ?? '') !== ($item['slug'] ?? '') || ($manifest['cms'] ?? '') !== ($item['cms'] ?? '')
            || ($manifest['version'] ?? 0) !== ($item['format_version'] ?? 0)
            || !is_array($meta) || ($meta['version'] ?? '') !== ($item['version'] ?? '')) throw new RuntimeException('st_market_identity');
    }

    /** Same HTTPS, no-redirect, bounded streaming policy as the theme marketplace.
     * @param callable(?int):bool $acceptLength @param callable(string):int $writeChunk
     * @return array{status:int,error:string}
     */
    private static function transfer(string $url, callable $acceptLength, callable $writeChunk, int $timeout): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) return ['status' => 0, 'error' => 'connection'];
            curl_setopt_array($ch, [
                CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use ($acceptLength): int {
                    if (preg_match('/^Content-Length:\s*(\d+)\s*$/iD', trim($line), $match) === 1 && !$acceptLength((int) $match[1])) return 0;
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => static fn($handle, string $chunk): int => $writeChunk($chunk),
            ]);
            $completed = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = $completed === false ? curl_error($ch) : '';
            curl_close($ch);
            return ['status' => $status, 'error' => $error];
        }
        $context = stream_context_create([
            'http' => ['timeout' => $timeout, 'follow_location' => 0, 'max_redirects' => 0, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        $headers = is_array($http_response_header ?? null) ? $http_response_header : [];
        $status = preg_match('#\s(\d{3})\s#', (string) ($headers[0] ?? ''), $match) === 1 ? (int) $match[1] : 0;
        if (!is_resource($stream)) return ['status' => $status, 'error' => 'connection'];
        try {
            foreach ($headers as $header) {
                if (preg_match('/^Content-Length:\s*(\d+)\s*$/iD', trim($header), $match) === 1 && !$acceptLength((int) $match[1])) return ['status' => $status, 'error' => 'size'];
            }
            while (!feof($stream)) {
                $chunk = @fread($stream, 8192);
                if (!is_string($chunk) || ($chunk === '' && !feof($stream)) || $writeChunk($chunk) !== strlen($chunk)) return ['status' => $status, 'error' => 'transfer'];
            }
            return ['status' => $status, 'error' => ''];
        } finally { fclose($stream); }
    }
}
