<?php
/**
 * 校验一片译文：php tools/i18n/validate.php <code> <exported.jsonl> <translated.jsonl> [--glossary=glossary.<code>.json]
 * 有错误时退出码为 1，并逐条列出行号与原因。
 */
declare(strict_types=1);
require __DIR__ . '/_cli.php';

[$args, $opt] = i18n_args($argv);
if (count($args) < 3) {
    fwrite(STDERR, "Usage: php tools/i18n/validate.php <code> <exported.jsonl> <translated.jsonl> [--glossary=FILE]\n");
    exit(2);
}
$glossary = [];
if (isset($opt['glossary'])) {
    $glossary = json_decode((string) file_get_contents($opt['glossary']), true);
    if (!is_array($glossary)) {
        fwrite(STDERR, "Glossary is not a JSON object: {$opt['glossary']}\n");
        exit(2);
    }
}
try {
    $r = i18n_pipeline()->validate($args[0], $args[1], $args[2], $glossary);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
foreach ($r['errors'] as $e) {
    echo "ERROR   {$e}\n";
}
foreach ($r['warnings'] as $w) {
    echo "WARNING {$w}\n";
}
printf("%s: %d lines, %d errors, %d warnings\n", $r['errors'] === [] ? 'OK' : 'FAILED', $r['rows'], count($r['errors']), count($r['warnings']));
exit($r['errors'] === [] ? 0 : 1);
