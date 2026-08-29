<?php
/** Build the package-local YikaiCMS provenance manifest. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/ProductIdentity.php';

$packageRoot = (string) ($argv[1] ?? '');
$version = (string) ($argv[2] ?? '');
$buildId = (string) ($argv[3] ?? '');
$sourceCommit = (string) ($argv[4] ?? '');
$sourceDirty = (string) ($argv[5] ?? '0') === '1';

try {
    $manifest = YikaiProductIdentity::createBuildManifest(
        $packageRoot,
        $version,
        $buildId,
        $sourceCommit,
        $sourceDirty
    );
    $output = rtrim($packageRoot, '/\\') . '/config/provenance.php';
    $php = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($manifest, true) . ";\n";
    if (file_put_contents($output, $php, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write provenance manifest.');
    }
    fwrite(STDOUT, '  Product provenance: ' . $manifest['core_tree_sha256'] . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Product provenance failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
