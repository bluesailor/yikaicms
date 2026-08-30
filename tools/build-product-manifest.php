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
    // 不要加 LOCK_EX：打包在 WSL 下把 $TMP_DIR 传成 \\wsl.localhost\... 的 UNC 路径，
    // Windows php.exe 在 9P 文件系统上取不到独占锁，file_put_contents 直接返回 false
    // （"Exclusive locks are not supported for this stream"），整个 build.sh 中止。
    // 本步是一次性 CLI 写入、目标是私有临时目录，没有并发写者，锁本来也没有意义。
    if (file_put_contents($output, $php) === false) {
        throw new RuntimeException('Unable to write provenance manifest.');
    }
    fwrite(STDOUT, '  Product provenance: ' . $manifest['core_tree_sha256'] . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Product provenance failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
