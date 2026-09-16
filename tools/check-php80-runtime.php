<?php

declare(strict_types=1);

// Parse distributed source with the actual minimum runtime, without loading site config.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80000 || PHP_VERSION_ID >= 80100) {
    fwrite(STDERR, "Run this check with PHP 8.0.x, not " . PHP_VERSION . ".\n");
    exit(2);
}

$root = dirname(__DIR__);
$paths = glob($root . '/*.php') ?: [];
foreach (['admin', 'api', 'bin', 'config', 'controllers', 'deploy', 'includes', 'install', 'lang', 'migrations', 'plugins', 'themes/default', 'marketplace/themes', 'views'] as $directory) {
    if (!is_dir($root . '/' . $directory)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php' && !$file->isLink()) {
            $paths[] = $file->getPathname();
        }
    }
}
$errors = [];
$count = 0;
foreach (array_unique($paths) as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    if ($relative === 'config/config.php') {
        continue;
    }
    try {
        $source = file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Cannot read source');
        }
        token_get_all($source, TOKEN_PARSE);
        $count++;
    } catch (Throwable $error) {
        $errors[] = $relative . ': ' . $error->getMessage();
    }
}
foreach ($errors as $error) {
    fwrite(STDERR, $error . "\n");
}
echo 'PHP ' . PHP_VERSION . ': ' . $count . ' runtime files parsed; ' . count($errors) . " errors.\n";
echo "Syntax only; runtime behavior and extension availability require separate smoke tests.\n";
exit($errors === [] ? 0 : 1);
