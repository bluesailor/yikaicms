<?php

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

require_once ROOT_PATH . '/tools/ReleaseArtifactSmoke.php';

$artifact = $argv[1] ?? '';
if ($artifact === '') {
    fwrite(STDERR, "Usage: php tools/release-artifact-smoke.php <package-root|release.zip>\n");
    exit(2);
}

/** @var array{required_files:list<string>,forbidden_paths:list<string>} $manifest */
$manifest = require ROOT_PATH . '/config/release-runtime.php';
$smoke = new ReleaseArtifactSmoke($manifest);
$errors = $smoke->inspect($artifact);

if ($errors !== []) {
    fwrite(STDERR, "Release artifact smoke failed:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, '  - ' . $error . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "Release artifact smoke passed: {$artifact}\n");
