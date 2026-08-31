<?php

declare(strict_types=1);

// No Composer/PHPUnit bootstrap: the dev test runner itself requires PHP 8.1+.
require_once dirname(__DIR__, 2) . '/includes/builder/BloxDocumentPipeline.php';
require_once dirname(__DIR__, 2) . '/includes/builder/BloxDisplayConditions.php';

$groups = [['rules' => [['type' => 'login', 'operator' => 'is', 'value' => 'logged_in']]]];
if (!BloxDisplayConditions::matches($groups, ['logged_in' => true])
    || BloxDisplayConditions::matches($groups, ['logged_in' => false])
    || BloxDisplayConditions::parse([1 => $groups[0]]) !== null
    || BloxDisplayConditions::parse([['rules' => [1 => $groups[0]['rules'][0]]]]) !== null) {
    fwrite(STDERR, "Display condition compatibility failed\n");
    exit(1);
}
echo 'PHP ' . PHP_VERSION . ': list-array validation and visibility passed' . PHP_EOL;
