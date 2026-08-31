<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/includes/License.php';
$dir = __DIR__ . '/candidate';
$entry = json_decode(file_get_contents($dir . '/release-entry.json'), true, 512, JSON_THROW_ON_ERROR);
$report = json_decode(file_get_contents($dir . '/build-report.json'), true, 512, JSON_THROW_ON_ERROR);
$zipPath = $dir . '/' . $entry['package'];
$hash = 'sha256:' . hash_file('sha256', $zipPath);
if ($hash !== 'sha256:cf18856fdd94571b3fba0cde04da3633c648546c4fcc36375094f00e8fdb3780'
    || $hash !== $entry['hash'] || $entry['version'] !== '1.19.3.1') throw new RuntimeException('Unexpected artifact');
$signature = base64_decode($entry['sig'], true);
if (!$signature || openssl_verify('1.19.3.1|' . $hash, $signature, license_pubkey(), OPENSSL_ALGO_SHA256) !== 1) throw new RuntimeException('Client public key rejects signature');
foreach (['1.19.3.2|' . $hash, '1.19.3.1|sha256:' . str_repeat('0', 64)] as $tampered) {
    if (openssl_verify($tampered, $signature, license_pubkey(), OPENSSL_ALGO_SHA256) === 1) throw new RuntimeException('Tampering accepted');
}
$zip = new ZipArchive();
if ($zip->open($zipPath) !== true || $zip->numFiles !== 7) throw new RuntimeException('Unexpected ZIP');
$manifest = json_decode($zip->getFromName('.delta-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if ($manifest !== ['from' => '1.19.3', 'to' => '1.19.3.1', 'deleted' => []]) throw new RuntimeException('Unexpected delta binding');
$allowed = ['config/version.php', 'themes/business/assets/js/header.js', 'themes/business/layouts/footer.php',
    'themes/business/layouts/header.php', 'themes/business/partials/page-hero.php', 'themes/business/theme.json'];
if (array_keys($report['files']) !== $allowed) throw new RuntimeException('Unexpected payload inventory');
foreach ($allowed as $name) {
    $bytes = $zip->getFromName('payload/' . $name);
    if (!is_string($bytes) || hash('sha256', $bytes) !== $report['files'][$name]['after_sha256']) throw new RuntimeException('Payload mismatch: ' . $name);
    if (substr($name, -4) === '.php') {
        $tmp = tempnam(sys_get_temp_dir(), 'maintenance-lint-');
        file_put_contents($tmp, $bytes);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp), $output, $status);
        unlink($tmp);
        if ($status !== 0) throw new RuntimeException('Payload lint failed');
    }
}
if (json_decode($zip->getFromName('payload/themes/business/theme.json'), true)['version'] !== '1.0.1') throw new RuntimeException('Unexpected theme version');
$zip->close();
echo "SIGNED MAINTENANCE ARTIFACT OK: six files, exact hash, client RSA key, delta binding, PHP lint\n";
