<?php

/** Build a machine-readable link between an artifact, its source commit and CI. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$zip = (string) ($argv[1] ?? '');
$version = (string) ($argv[2] ?? '');
$sourceCommit = strtolower((string) ($argv[3] ?? ''));
$output = (string) ($argv[4] ?? '');
$repository = 'https://github.com/bluesailor/yikaicms';

if (!is_file($zip)
    || preg_match('/^\d+\.\d+\.\d+(?:\.\d+)?$/D', $version) !== 1
    || preg_match('/^[a-f0-9]{40}$/D', $sourceCommit) !== 1
    || $output === ''
) {
    fwrite(STDERR, "Usage: php build-release-evidence.php <zip> <version> <40-char-commit> <output>\n");
    exit(2);
}

$hash = hash_file('sha256', $zip);
if (!is_string($hash)) {
    fwrite(STDERR, "Unable to hash release artifact.\n");
    exit(1);
}

$evidence = [
    'schema' => 1,
    'version' => $version,
    'artifact' => basename($zip),
    'artifact_sha256' => $hash,
    'source_commit' => $sourceCommit,
    'source_url' => $repository . '/commit/' . $sourceCommit,
    'ci_url' => $repository . '/commit/' . $sourceCommit . '/checks',
    'tests_in_install_package' => false,
    'verification_note' => 'Tests run against the linked source commit; the install package intentionally excludes development tests.',
];

$json = json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
if (file_put_contents($output, $json) === false) {
    fwrite(STDERR, "Unable to write release evidence.\n");
    exit(1);
}

fwrite(STDOUT, "Release evidence: {$output}\n");
