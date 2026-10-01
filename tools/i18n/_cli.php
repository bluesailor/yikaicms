<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/I18nPipeline.php';

/** @return array{0: list<string>, 1: array<string,string>} 位置参数与 --name=value 选项 */
function i18n_args(array $argv): array
{
    $positional = [];
    $options = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
            $options[$m[1]] = $m[2] ?? '1';
        } else {
            $positional[] = $arg;
        }
    }
    return [$positional, $options];
}

function i18n_pipeline(): I18nPipeline
{
    return new I18nPipeline(dirname(__DIR__, 2));
}
