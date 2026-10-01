<?php
/**
 * 合入已校验的译文分片：php tools/i18n/merge.php <code> <translated.jsonl>...
 * 原文在导出后改过的键会跳过并列出，需要重新导出翻译。
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

[$args] = i18n_args($argv);
if (count($args) < 2) {
    fwrite(STDERR, "Usage: php tools/i18n/merge.php <code> <translated.jsonl>...\n");
    exit(2);
}
try {
    $r = i18n_pipeline()->merge($args[0], array_slice($args, 1));
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
foreach ($r['written'] as $file => $n) {
    echo "  {$file}: {$n} strings merged\n";
}
foreach ($r['rejected'] as $x) {
    echo "REJECTED {$x}\n";
}
foreach ($r['stale'] as $x) {
    echo "STALE    {$x}\n";
}
exit($r['rejected'] === [] ? 0 : 1);
