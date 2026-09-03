<?php

declare(strict_types=1);

require_once __DIR__ . '/ReleaseUploadGuard.php';

$projectRoot = dirname(__DIR__);
$version = trim((string) ($argv[1] ?? ''));
$updateRoot = getenv('YK_UPDATE_ROOT');
$releaseDir = getenv('YK_RELEASE_DIR');
$updateRoot = is_string($updateRoot) && trim($updateRoot) !== ''
    ? $updateRoot
    : dirname($projectRoot) . '/update.yikaicms';
$releaseDir = is_string($releaseDir) && trim($releaseDir) !== ''
    ? $releaseDir
    : $projectRoot . '/releases';

if ($version === '') {
    fwrite(STDERR, "Usage: php tools/release-upload-guard.php <version>\n");
    exit(2);
}

try {
    fwrite(STDOUT, "[1/4] Update channel scenarios\n");
    $channelOutput = ReleaseUploadGuard::runPhpScript(
        $updateRoot . '/tests/update-check-channel.php'
    );
    fwrite(STDOUT, "  {$channelOutput}\n");

    fwrite(STDOUT, "[2/4] Update runtime gate\n");
    $runtimeOutput = ReleaseUploadGuard::runPhpScript(
        $updateRoot . '/tests/update-runtime-gate.php'
    );
    fwrite(STDOUT, "  {$runtimeOutput}\n");

    fwrite(STDOUT, "[3/4] Release signatures\n");
    $signatureOutput = ReleaseUploadGuard::runPhpScript(
        $updateRoot . '/bin/verify-release-signatures.php',
        ['--required']
    );
    fwrite(STDOUT, "  {$signatureOutput}\n");

    fwrite(STDOUT, "[4/4] Upload manifest, hashes and build mtimes\n");
    $plan = ReleaseUploadGuard::inspect($updateRoot, $releaseDir, $version);
} catch (Throwable $e) {
    fwrite(STDERR, "RELEASE UPLOAD BLOCKED: {$e->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, "\nPACKAGES FIRST\n");
foreach ($plan['packages'] as $path) {
    fwrite(STDOUT, "  {$path}\n");
}
fwrite(STDOUT, "DATA LAST\n");
foreach ($plan['data'] as $path) {
    fwrite(STDOUT, "  {$path}\n");
}
fwrite(STDOUT, "\nRELEASE UPLOAD PLAN OK: v{$plan['version']} ({$plan['channel']})\n");
