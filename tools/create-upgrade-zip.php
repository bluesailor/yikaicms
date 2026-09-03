<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/UpgradeEntryOrder.php';

if ($argc < 3 || $argc > 4) {
    fwrite(STDERR, "Usage: php tools/create-upgrade-zip.php <source-dir> <output.zip> [entry-prefix]\n");
    exit(2);
}

$source = realpath((string) $argv[1]);
$output = (string) $argv[2];
$prefix = trim(str_replace('\\', '/', (string) ($argv[3] ?? '')), '/');
$prefix = $prefix === '' ? '' : $prefix . '/';
if ($source === false || !is_dir($source)) {
    fwrite(STDERR, "Source directory not found.\n");
    exit(2);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(2);
}

$entries = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
        continue;
    }
    $path = $file->getPathname();
    $archiveRel = str_replace('\\', '/', substr($path, strlen($source) + 1));
    $targetRel = str_starts_with($archiveRel, 'payload/') ? substr($archiveRel, 8) : $archiveRel;
    $entries[] = ['rel' => $targetRel, 'archive_rel' => $archiveRel, 'path' => $path];
}

$entries = UpgradeEntryOrder::sort(
    $entries,
    static fn (array $entry): string => (string) file_get_contents($entry['path'])
);
if ($entries === []) {
    fwrite(STDERR, "Source directory contains no files.\n");
    exit(2);
}

$outputDir = dirname($output);
if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Cannot create output directory.\n");
    exit(2);
}
$temporary = $output . '.tmp-' . bin2hex(random_bytes(4));
$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create ZIP file.\n");
    exit(2);
}

foreach ($entries as $entry) {
    $name = $prefix . ($entry['archive_rel'] ?? $entry['rel']);
    if (!$zip->addFile($entry['path'], $name)) {
        $zip->close();
        @unlink($temporary);
        fwrite(STDERR, "Cannot add ZIP entry: {$name}\n");
        exit(2);
    }
    if (method_exists($zip, 'setMtimeName')) {
        $zip->setMtimeName($name, (int) filemtime($entry['path']));
    }
}
if (!$zip->close()) {
    @unlink($temporary);
    fwrite(STDERR, "Cannot finalize ZIP file.\n");
    exit(2);
}
if (is_file($output) && !unlink($output)) {
    @unlink($temporary);
    fwrite(STDERR, "Cannot replace existing ZIP file.\n");
    exit(2);
}
if (!rename($temporary, $output)) {
    @unlink($temporary);
    fwrite(STDERR, "Cannot move ZIP file into place.\n");
    exit(2);
}

echo 'Created ' . $output . ' with ' . count($entries) . " ordered files.\n";
