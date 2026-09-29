<?php
declare(strict_types=1);

/**
 * 生成插件自带的中国大陆 IP 数据（仅命令行，发布插件新版本前运行）：
 *
 *   php plugins/geo-block/tools/build-data.php <delegated-apnic-latest 文件路径>
 *
 * 源文件：https://ftp.apnic.net/stats/apnic/delegated-apnic-latest（APNIC 公开分配统计，
 * 附带 .md5 校验文件）。输出 data/cn-ipv4.bin、data/cn-ipv6.bin 与 data/meta.json。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/GeoBlockRanges.php';

$source = $argv[1] ?? '';
if ($source === '' || !is_file($source)) {
    fwrite(STDERR, "usage: php build-data.php <delegated-apnic-latest>\n");
    exit(1);
}
$built = GeoBlockRanges::buildFromDelegated((string) file_get_contents($source));
if ($built['ipv4'] === '' || $built['ipv6'] === '') {
    fwrite(STDERR, "no CN records found — is this an APNIC delegated file?\n");
    exit(1);
}
$dir = dirname(__DIR__) . '/data';
if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
    fwrite(STDERR, "cannot create $dir\n");
    exit(1);
}
file_put_contents($dir . '/cn-ipv4.bin', $built['ipv4']);
file_put_contents($dir . '/cn-ipv6.bin', $built['ipv6']);
$meta = [
    'source' => 'APNIC delegated-apnic-latest (country code CN)',
    'source_date' => $built['date'],
    'generated_at' => gmdate('Y-m-d'),
    'ipv4_ranges' => intdiv(strlen($built['ipv4']), 8),
    'ipv6_ranges' => intdiv(strlen($built['ipv6']), 32),
    'ipv4_addresses' => $built['ipv4_addresses'],
    'sha256' => [
        'cn-ipv4.bin' => hash('sha256', $built['ipv4']),
        'cn-ipv6.bin' => hash('sha256', $built['ipv6']),
    ],
];
file_put_contents($dir . '/meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
echo json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
