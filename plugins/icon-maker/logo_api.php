<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/**
 * Calls the central logo.yikaicms.com generator from the CMS server.
 * The API key stays server-side and the target host is deliberately fixed.
 */
function iconMakerLogoApiConfig(): array
{
    return [
        'url' => rtrim((string) config('iconmaker_logo_api_url', 'https://logo.yikaicms.com/logo-api.php'), '/'),
        'key' => trim((string) config('iconmaker_logo_api_key', '')),
    ];
}

/**
 * @return array{code:int,msg:string,data:array<string,mixed>|null}
 */
function iconMakerLogoApiRequest(array $payload): array
{
    $config = iconMakerLogoApiConfig();
    if ($config['url'] === '' || $config['key'] === '') {
        throw new RuntimeException('请先配置 LOGO LAB API 地址和 API Key。');
    }
    $parts = parse_url($config['url']);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || !in_array($host, ['logo.yikaicms.com', 'logo.yikai', 'www.logo.yikai'], true)) {
        throw new RuntimeException('LOGO LAB API 地址只允许使用 logo.yikaicms.com。');
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) {
        throw new RuntimeException('LOGO LAB 请求参数编码失败。');
    }
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-API-Key: ' . $config['key'],
        'Content-Length: ' . strlen($body),
    ];
    $status = 0;
    $response = false;
    if (function_exists('curl_init')) {
        $curl = curl_init($config['url']);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if ($response === false) {
            throw new RuntimeException('LOGO LAB API 请求失败：' . ($error !== '' ? $error : 'network error'));
        }
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'ignore_errors' => true,
                'timeout' => 25,
            ],
        ]);
        $response = @file_get_contents($config['url'], false, $context);
        $statusLine = (string) ($http_response_header[0] ?? '');
        $status = preg_match('/\s(\d{3})\s/', $statusLine, $match) ? (int) $match[1] : 0;
        if ($response === false) {
            throw new RuntimeException('LOGO LAB API 请求失败。');
        }
    }

    $decoded = json_decode((string) $response, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('LOGO LAB API 返回了无效响应。');
    }
    if ($status >= 400 || (int) ($decoded['code'] ?? 0) !== 0) {
        throw new RuntimeException((string) ($decoded['msg'] ?? 'LOGO LAB API 请求失败。'));
    }
    return $decoded;
}
