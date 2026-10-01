<?php
/** 各语言翻译进度：php tools/i18n/status.php [--json] */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

[, $opt] = i18n_args($argv);
$status = i18n_pipeline()->status();
if (isset($opt['json'])) {
    echo json_encode($status, JSON_PRETTY_PRINT) . "\n";
    exit(0);
}
printf("%-7s %-22s %-22s %-22s\n", 'code', 'core', 'plugins', 'installer');
foreach ($status as $code => $scopes) {
    $cells = [];
    foreach (I18nPipeline::SCOPES as $scope) {
        $s = $scopes[$scope];
        $pct = $s['total'] > 0 ? 100 * $s['translated'] / $s['total'] : 0;
        $cells[] = sprintf('%5d/%-5d %3.0f%%%s', $s['translated'], $s['total'], $pct, $s['stale'] > 0 ? " ({$s['stale']} stale)" : '');
    }
    printf("%-7s %-22s %-22s %-22s\n", $code, ...$cells);
}
