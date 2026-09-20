<?php
/**
 * 跨平台 PHP 语法检查（外审 P2-7）：composer lint 原来是 Unix find|xargs 管道，
 * Windows 下会命中系统自带的 find.exe（参数完全不同）直接失败。
 * 这里用 PHP 自己遍历 + 小并发池跑 php -l，三平台同一入口：
 *
 *   composer lint          # 或 php tools/lint.php
 *
 * 退出码：0 全过；1 有语法错误（逐条打印）；2 环境问题。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$root = dirname(__DIR__);
$skipDirs = ['vendor', 'node_modules', 'storage', '.git'];

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $current) use ($skipDirs): bool {
            if ($current->isDir()) {
                return !in_array($current->getBasename(), $skipDirs, true);
            }
            return $current->getExtension() === 'php';
        }
    )
);
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $files[] = $file->getPathname();
}
sort($files);

$php = PHP_BINARY;
$pool = [];
$poolSize = 8;
$errors = [];
$queue = $files;

$spawn = static function (string $path) use ($php): array {
    $pipes = [];
    $proc = proc_open(
        [$php, '-n', '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    return ['proc' => $proc, 'pipes' => $pipes, 'path' => $path];
};

while ($queue !== [] || $pool !== []) {
    while (count($pool) < $poolSize && $queue !== []) {
        $job = $spawn(array_shift($queue));
        if (!is_resource($job['proc'])) {
            fwrite(STDERR, "无法启动 php -l\n");
            exit(2);
        }
        $pool[] = $job;
    }
    foreach ($pool as $i => $job) {
        $status = proc_get_status($job['proc']);
        if ($status['running']) {
            continue;
        }
        $stdout = stream_get_contents($job['pipes'][1]) ?: '';
        $stderr = stream_get_contents($job['pipes'][2]) ?: '';
        fclose($job['pipes'][1]);
        fclose($job['pipes'][2]);
        proc_close($job['proc']);
        if ($status['exitcode'] !== 0) {
            $errors[] = trim($stderr !== '' ? $stderr : $stdout);
        }
        unset($pool[$i]);
    }
    $pool = array_values($pool);
    if ($pool !== []) {
        usleep(5000);
    }
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, $error . "\n");
    }
    fwrite(STDERR, count($errors) . " 个文件有语法错误\n");
    exit(1);
}
echo count($files) . " 个 PHP 文件语法全部通过\n";
