<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/includes/init.php';
require_once ROOT_PATH . '/includes/MediaWebpConverter.php';

$options = getopt('', ['apply', 'dry-run', 'quality:']);
if (isset($options['apply'], $options['dry-run'])) {
    fwrite(STDERR, "--apply 与 --dry-run 不能同时使用\n");
    exit(1);
}
$apply = isset($options['apply']);
$qualityRaw = $options['quality'] ?? '85';
if (!is_string($qualityRaw) || !ctype_digit($qualityRaw)) {
    fwrite(STDERR, "--quality 必须是 50 到 95 的整数\n");
    exit(1);
}
$quality = (int) $qualityRaw;
if ($quality < 50 || $quality > 95) {
    fwrite(STDERR, "--quality 必须是 50 到 95 的整数\n");
    exit(1);
}

try {
    $report = (new MediaWebpConverter(ROOT_PATH))->run($apply, $quality);
    $mode = $apply ? 'apply' : 'dry-run';
    echo "[{$mode}] 扫描 {$report['scanned']}，待转换 {$report['pending']}，实际转换 {$report['converted']}，复用 {$report['reused']}\n";
    echo "节省字节 {$report['saved_bytes']}，引用修改 {$report['reference_changes']}，无源文件引用 {$report['missing_references']}\n";
    foreach ($report['missing_paths'] as $path) {
        echo "  missing: uploads/{$path}\n";
    }
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, '转换失败：' . $error->getMessage() . "\n");
    exit(1);
}
