<?php

declare(strict_types=1);

// Compile distributed source with the actual minimum runtime, without loading site config.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (PHP_VERSION_ID < 80000 || PHP_VERSION_ID >= 80100) {
    fwrite(STDERR, "Run this check with PHP 8.0.x, not " . PHP_VERSION . ".\n");
    exit(2);
}

$root = dirname(__DIR__);
// Tests may point the gate at a fixture tree; production CI always checks this repository.
$override = getenv('YIKAI_PHP80_CHECK_ROOT');
if (is_string($override) && $override !== '' && is_dir($override)) {
    $root = rtrim(str_replace('\\', '/', $override), '/');
}
$paths = glob($root . '/*.php') ?: [];
foreach (['admin', 'api', 'bin', 'config', 'controllers', 'deploy', 'includes', 'install', 'lang', 'migrations', 'plugins', 'themes', 'marketplace/themes', 'views'] as $directory) {
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

/**
 * token_get_all(TOKEN_PARSE) only builds the AST. Constructs PHP 8.0 rejects at compile
 * time (for example `new` in a parameter default, added in 8.1) pass the parser, so each
 * file is also compiled by `php -n -l` with this same PHP 8.0 binary, in small parallel batches.
 *
 * @param list<string> $files
 * @return array<string,string>
 */
$lint = static function (array $files): array {
    $running = [];
    foreach ($files as $path) {
        $process = proc_open([PHP_BINARY, '-n', '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $running[] = is_resource($process) ? [$path, $process, $pipes[1], $pipes[2]] : [$path, null, null, null];
    }
    $failures = [];
    foreach ($running as [$path, $process, $stdout, $stderr]) {
        if ($process === null) {
            $failures[$path] = 'Cannot start php -l';
            continue;
        }
        $output = stream_get_contents($stdout) . stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        if (proc_close($process) !== 0) {
            $failures[$path] = trim(preg_replace('/\s+/', ' ', $output) ?? $output);
        }
    }
    return $failures;
};

$errors = [];
$count = 0;
$compile = [];
foreach (array_unique($paths) as $path) {
    $relative = str_replace('\\', '/', substr(str_replace('\\', '/', $path), strlen($root) + 1));
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
        $compile[] = $path;
    } catch (Throwable $error) {
        $errors[] = $relative . ': ' . $error->getMessage();
    }
}
foreach (array_chunk($compile, 8) as $batch) {
    foreach ($lint($batch) as $path => $message) {
        $errors[] = substr(str_replace('\\', '/', $path), strlen($root) + 1) . ': ' . $message;
    }
}
foreach ($errors as $error) {
    fwrite(STDERR, $error . "\n");
}
echo 'PHP ' . PHP_VERSION . ': ' . $count . ' runtime files parsed and compiled; ' . count($errors) . " errors.\n";
echo "Compile check only; runtime behavior and extension availability require separate smoke tests.\n";
exit($errors === [] ? 0 : 1);
