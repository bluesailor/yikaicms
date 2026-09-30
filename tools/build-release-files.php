<?php
/** Write config/release-files.php (per-file hashes of the package) into a package directory. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require_once dirname(__DIR__) . '/includes/ReleaseFiles.php';

$packageRoot = (string) ($argv[1] ?? '');
$version = (string) ($argv[2] ?? '');

try {
    if (preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/', $version) !== 1) {
        throw new InvalidArgumentException('Invalid product version.');
    }
    $manifest = ReleaseFiles::build($packageRoot, $version);
    $output = rtrim($packageRoot, '/\\') . '/' . ReleaseFiles::FILE;
    $php = "<?php\n\ndeclare(strict_types=1);\n\n// 发行包文件清单：升级前用来找出本站改过的核心文件（见 includes/ReleaseFiles.php）。\nreturn " . var_export($manifest, true) . ";\n";
    // 与 build-product-manifest.php 相同：WSL UNC 路径上取不到独占锁，不加 LOCK_EX
    if (file_put_contents($output, $php) === false) {
        throw new RuntimeException('Unable to write release file list.');
    }
    fwrite(STDOUT, '  Release file list: ' . count($manifest['files']) . ' files' . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Release file list failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
