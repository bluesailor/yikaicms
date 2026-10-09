<?php
/**
 * 给 build.sh 产出的语言包签名（发版机本地跑；私钥不进仓库、不进 argv 以外的任何输出）。
 *
 *   php tools/sign-lang-packs.php <releases/lang/<版本> 目录> <版本> <私钥文件>
 *
 * 私钥文件与更新服务器同一份（update.yikaicms/data/license_rsa.php，首行 <?php 守卫后接 PEM）。
 * 签名写进各包的 pack.json，汇总 lang-packs-v<版本>.json 同步更新 zip 哈希。
 * 签完立即用 CMS 内置公钥（includes/License.php）逐个复验，任何一个不过即失败。
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/i18n/LanguagePacks.php';
require_once dirname(__DIR__) . '/includes/License.php';
require_once __DIR__ . '/LangPackZip.php';

if (count($argv) !== 4) {
    fwrite(STDERR, "用法: php tools/sign-lang-packs.php <语言包目录> <版本> <私钥文件>\n");
    exit(2);
}
[, $dir, $version, $keyFile] = $argv;
$summaryFile = $dir . '/lang-packs-v' . $version . '.json';
$summary = json_decode((string) @file_get_contents($summaryFile), true);
if (!is_array($summary) || ($summary['cms_version'] ?? '') !== $version || !is_array($summary['packs'] ?? null)) {
    fwrite(STDERR, "汇总文件缺失或版本不符: {$summaryFile}\n");
    exit(1);
}
$raw = (string) @file_get_contents($keyFile);
$privateKey = (string) preg_replace('/^<\?php[^\r\n]*(?:\r\n|\n|\r)/', '', $raw, 1);
if (!str_contains($privateKey, 'BEGIN PRIVATE KEY')) {
    fwrite(STDERR, "私钥不可用\n");
    exit(1);
}

foreach ($summary['packs'] as $code => &$row) {
    $path = $dir . '/' . $row['file'];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        fwrite(STDERR, "无法打开 {$row['file']}\n");
        exit(1);
    }
    $meta = json_decode((string) $zip->getFromName('pack.json'), true);
    $php = (string) $zip->getFromName('lang/' . $code . '.php');
    $zip->close();
    if (!is_array($meta) || ($meta['code'] ?? '') !== $code || hash('sha256', $php) !== ($meta['sha256'] ?? '')) {
        fwrite(STDERR, "{$row['file']} 内容与清单不符\n");
        exit(1);
    }
    $signature = '';
    if (!openssl_sign(LanguagePacks::canonical($code, $version, $meta['sha256']), $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        fwrite(STDERR, "签名失败: {$code}\n");
        exit(1);
    }
    $meta['signature'] = base64_encode($signature);
    if (!LanguagePacks::verifySignature($code, $version, $meta['sha256'], $meta['signature'], license_pubkey())) {
        fwrite(STDERR, "复验失败（私钥与 CMS 内置公钥不是一对）: {$code}\n");
        exit(1);
    }
    langPackZip($path, $code, $php, $meta);
    $row['zip_sha256'] = hash_file('sha256', $path);
    $row['bytes'] = filesize($path);
    $row['signed'] = true;
    echo "signed: {$row['file']}\n";
}
unset($row);

file_put_contents($summaryFile, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
