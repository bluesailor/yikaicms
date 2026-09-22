<?php
declare(strict_types=1);
define('ROOT_PATH', dirname(__DIR__, 2));
require ROOT_PATH . '/config/version.php';
require ROOT_PATH . '/includes/SiteTemplateMarket.php';
$fixture = [
    'slug' => 'php80-fixture', 'name' => 'Runtime fixture', 'version' => '1.0.0', 'cms' => CMS_VERSION,
    'format_version' => 1, 'category' => 'general', 'requires_php' => '>=8.0.0', 'status' => 'published', 'tier' => 'free',
    'package' => 'php80-fixture-site-v1.0.0.zip',
    'download_url' => 'https://update.yikaicms.com/packages/site-templates/php80-fixture-site-v1.0.0.zip',
    'hash' => 'sha256:' . hash('sha256', 'fixture'), 'size_bytes' => 7,
];
$config = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + (is_file($config) ? ['config' => $config] : []));
if ($key === false) throw new RuntimeException('Test key generation failed');
if (!openssl_sign(SiteTemplateMarket::canonical($fixture), $signature, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('Signing failed');
$fixture['sig'] = base64_encode($signature);
$body = json_encode(['code' => 0, 'data' => ['protocol_version' => 1, 'templates' => [$fixture]]], JSON_THROW_ON_ERROR);
$catalog = SiteTemplateMarket::request(static fn(): string => $body);
if (count($catalog['templates'] ?? []) !== 1) throw new RuntimeException('Catalog failed');
$target = tempnam(sys_get_temp_dir(), 'yk-php80-market-');
try {
    SiteTemplateMarket::download($catalog['templates'][0], $target, (string) openssl_pkey_get_details($key)['key'],
        static function (string $url, callable $accept, callable $write, int $timeout): array {
            if (!$accept(7) || $write('fixture') !== 7) throw new RuntimeException('Transfer failed');
            return ['status' => 200, 'error' => ''];
        });
    if (file_get_contents($target) !== 'fixture') throw new RuntimeException('Byte verification failed');
    echo json_encode(['php' => PHP_VERSION, 'catalog' => true, 'signature' => true, 'streaming_hash' => true], JSON_THROW_ON_ERROR) . "\n";
} finally { if (is_file($target)) unlink($target); }
