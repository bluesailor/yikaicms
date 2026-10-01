<?php
/**
 * 导出目标语言尚未翻译的文案为 JSONL 分片。
 *   php tools/i18n/export.php <code> [--scope=core|plugins|installer|all] [--prefix=blox_] [--size=400] [--out=DIR]
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

[$args, $opt] = i18n_args($argv);
$code = $args[0] ?? '';
if ($code === '') {
    fwrite(STDERR, "Usage: php tools/i18n/export.php <code> [--scope=core|plugins|installer|all] [--prefix=] [--size=400] [--out=DIR]\n");
    exit(2);
}
$scope = $opt['scope'] ?? 'all';
$scopes = $scope === 'all' ? I18nPipeline::SCOPES : [$scope];
$out = $opt['out'] ?? (sys_get_temp_dir() . "/yikaicms-i18n-{$code}");
try {
    $result = i18n_pipeline()->export($code, $scopes, $opt['prefix'] ?? '', (int) ($opt['size'] ?? 400), $out);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
foreach ($result['shards'] as $s) {
    printf("  %-32s %4d lines\n", $s['file'], $s['lines']);
}
printf("Exported %d untranslated strings for %s to %s (see manifest.json)\n", $result['total'], $code, $out);
