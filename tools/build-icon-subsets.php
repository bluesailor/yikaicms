<?php
/** Build/check the front-end icon font subset and its auditable manifest. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config/version.php';
$check = in_array('--check', $argv, true);
$config = require $root . '/config/icon-subset.php';
if (!is_array($config) || ($config['schema'] ?? null) !== 1
    || !is_array($config['scan_roots'] ?? null) || !is_array($config['extensions'] ?? null)
    || !is_array($config['ignored_icon_values'] ?? null)
    || !is_array($config['safelist']['tabler'] ?? null)
    || !is_array($config['safelist']['bootstrap'] ?? null)) {
    fwrite(STDERR, "Invalid config/icon-subset.php\n");
    exit(1);
}

$sources = [
    'tabler' => [
        'css' => $root . '/assets/tabler/tabler-icons.min.css',
        'font' => $root . '/assets/tabler/fonts/tabler-icons.woff2',
        'pattern' => '/\.ti-([a-z0-9-]+):before\{content:"\\\\([0-9a-f]+)"\}/',
    ],
    'bootstrap' => [
        'css' => $root . '/assets/bootstrap-icons/bootstrap-icons.min.css',
        'font' => $root . '/assets/bootstrap-icons/fonts/bootstrap-icons.woff2',
        'pattern' => '/\.bi-([a-z0-9-]+)::before\{content:"\\\\([0-9a-f]+)"\}/',
    ],
];

$maps = [];
foreach ($sources as $provider => $source) {
    $css = file_get_contents($source['css']);
    if (!is_string($css) || preg_match_all($source['pattern'], $css, $matches, PREG_SET_ORDER) === false) {
        fwrite(STDERR, "Unable to parse {$provider} icon CSS\n");
        exit(1);
    }
    $maps[$provider] = [];
    foreach ($matches as $match) {
        $maps[$provider][$match[1]] = strtolower($match[2]);
    }
    if ($maps[$provider] === []) {
        fwrite(STDERR, "No {$provider} icons found\n");
        exit(1);
    }
}

$discovered = ['tabler' => [], 'bootstrap' => []];
$extensions = array_fill_keys(array_map('strtolower', $config['extensions']), true);
foreach ($config['scan_roots'] as $relativeRoot) {
    if (!is_string($relativeRoot) || preg_match('#^[a-zA-Z0-9_./-]+$#D', $relativeRoot) !== 1
        || str_contains($relativeRoot, '..')) {
        fwrite(STDERR, "Unsafe scan root\n");
        exit(1);
    }
    $directory = $root . '/' . $relativeRoot;
    if (!is_dir($directory)) {
        fwrite(STDERR, "Missing scan root: {$relativeRoot}\n");
        exit(1);
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()
            || !isset($extensions[strtolower($file->getExtension())])) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        if (str_contains($path, '/assets/icon-library/')) {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            fwrite(STDERR, "Unable to read: {$path}\n");
            exit(1);
        }
        if (preg_match_all('/(?:^|[\\s"\'=:])ti(?: ti)?-([a-z0-9][a-z0-9-]*)/', $contents, $found) !== false) {
            foreach ($found[1] as $name) {
                $discovered['tabler'][$name] = true;
            }
        }
        if (preg_match_all('/(?:^|[\\s"\'=:])bi(?: bi)?-([a-z0-9][a-z0-9-]*)|bi:([a-z0-9][a-z0-9-]*)/', $contents, $found, PREG_SET_ORDER) !== false) {
            foreach ($found as $match) {
                $name = (string) (($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
                if ($name !== '' && $name !== 'xxx') {
                    $discovered['bootstrap'][$name] = true;
                }
            }
        }
        // Blox 文档、预设和元素 schema 里的 icon 值会在运行时才拼成 class；这里把
        // 仓库自带的完整字面量也纳入扫描。任意站点数据中的其他值仍由运行时完整集兜底。
        if (preg_match_all('/(?:(?:"icon"\s*:\s*")|(?:\'icon\'\s*=>\s*\'))((?:bi:)?[a-z0-9][a-z0-9-]*)["\']/', $contents, $found) !== false) {
            foreach ($found[1] as $value) {
                if (in_array($value, $config['ignored_icon_values'], true)) {
                    continue;
                }
                if (str_starts_with($value, 'bi:')) {
                    $discovered['bootstrap'][substr($value, 3)] = true;
                } else {
                    $discovered['tabler'][$value] = true;
                }
            }
        }
    }
}

$final = ['tabler' => [], 'bootstrap' => []];
$missing = [];
foreach (['tabler', 'bootstrap'] as $provider) {
    $names = array_keys($discovered[$provider]);
    foreach ($config['safelist'][$provider] as $name) {
        if (!is_string($name) || preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/D', $name) !== 1) {
            fwrite(STDERR, "Invalid {$provider} safelist entry\n");
            exit(1);
        }
        $names[] = $name;
    }
    $names = array_values(array_unique($names));
    sort($names);
    foreach ($names as $name) {
        if (!isset($maps[$provider][$name])) {
            $missing[] = $provider . ':' . $name;
        }
    }
    $final[$provider] = $names;
}
if ($missing !== []) {
    fwrite(STDERR, 'Missing icons: ' . implode(', ', $missing) . "\n");
    exit(1);
}

$outputDirectory = $root . '/assets/icons';
$fontDirectory = $outputDirectory . '/fonts';
$cssPath = $outputDirectory . '/site-icons.min.css';
$auditPath = $outputDirectory . '/site-icon-audit.json';
$fontPaths = [
    'tabler' => $fontDirectory . '/tabler-icons-site.woff2',
    'bootstrap' => $fontDirectory . '/bootstrap-icons-site.woff2',
];

$css = '/* Generated by tools/build-icon-subsets.php; do not edit. */';
if ($final['tabler'] !== []) {
    $css .= '@font-face{font-display:block;font-family:"tabler-icons-site";font-style:normal;font-weight:400;src:url("fonts/tabler-icons-site.woff2?v=' . rawurlencode(CMS_VERSION) . '") format("woff2")}'
        . '.ti{font-family:"tabler-icons-site"!important;speak:none;font-style:normal;font-weight:normal;font-variant:normal;text-transform:none;line-height:1;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}';
    foreach ($final['tabler'] as $name) {
        $css .= '.ti-' . $name . ':before{content:"\\' . $maps['tabler'][$name] . '"}';
    }
}
if ($final['bootstrap'] !== []) {
    $css .= '@font-face{font-display:block;font-family:"bootstrap-icons-site";src:url("fonts/bootstrap-icons-site.woff2?v=' . rawurlencode(CMS_VERSION) . '") format("woff2")}'
        . '.bi::before,[class*=" bi-"]::before,[class^=bi-]::before{display:inline-block;font-family:"bootstrap-icons-site"!important;font-style:normal;font-weight:400!important;font-variant:normal;text-transform:none;line-height:1;vertical-align:-.125em;-webkit-font-smoothing:antialiased;-moz-osx-font-smoothing:grayscale}';
    foreach ($final['bootstrap'] as $name) {
        $css .= '.bi-' . $name . '::before{content:"\\' . $maps['bootstrap'][$name] . '"}';
    }
}
$css .= "\n";

foreach ($discovered as &$items) {
    $items = array_keys($items);
    sort($items);
}
unset($items);

if ($check) {
    $audit = is_file($auditPath) ? json_decode((string) file_get_contents($auditPath), true) : null;
    $failures = [];
    if (!is_array($audit) || ($audit['schema'] ?? null) !== 1) {
        $failures[] = 'audit missing or invalid';
    } else {
        if (($audit['icons'] ?? null) !== $final) {
            $failures[] = 'icon list is stale';
        }
        if (($audit['discovered'] ?? null) !== $discovered) {
            $failures[] = 'source scan is stale';
        }
        if (($audit['safelist'] ?? null) !== $config['safelist']) {
            $failures[] = 'safelist is stale';
        }
        if (($audit['ignored_icon_values'] ?? null) !== $config['ignored_icon_values']) {
            $failures[] = 'ignored icon values are stale';
        }
        foreach ($sources as $provider => $source) {
            if (($audit['sources'][$provider]['sha256'] ?? '') !== hash_file('sha256', $source['css'])) {
                $failures[] = $provider . ' source changed';
            }
            if (($audit['sources'][$provider]['font_sha256'] ?? '') !== hash_file('sha256', $source['font'])) {
                $failures[] = $provider . ' source font changed';
            }
        }
        $expectedOutputs = ['assets/icons/site-icons.min.css'];
        foreach (['tabler', 'bootstrap'] as $provider) {
            if ($final[$provider] !== []) {
                $expectedOutputs[] = str_replace('\\', '/', substr($fontPaths[$provider], strlen($root) + 1));
            }
        }
        $actualOutputs = array_keys(is_array($audit['outputs'] ?? null) ? $audit['outputs'] : []);
        sort($expectedOutputs);
        sort($actualOutputs);
        if ($actualOutputs !== $expectedOutputs) {
            $failures[] = 'output inventory mismatch';
        }
        foreach (($audit['outputs'] ?? []) as $relative => $metadata) {
            $path = $root . '/' . $relative;
            if (!is_file($path) || !is_array($metadata)
                || ($metadata['bytes'] ?? -1) !== filesize($path)
                || ($metadata['sha256'] ?? '') !== hash_file('sha256', $path)) {
                $failures[] = $relative . ' hash mismatch';
            }
        }
    }
    if (!is_file($cssPath) || file_get_contents($cssPath) !== $css) {
        $failures[] = 'subset CSS is stale';
    }
    if ($failures !== []) {
        fwrite(STDERR, 'Icon subset check failed: ' . implode('; ', array_unique($failures)) . "\n");
        exit(1);
    }
    fwrite(STDOUT, sprintf(
        "Icon subset OK: %d Tabler, %d Bootstrap, %d bytes CSS\n",
        count($final['tabler']), count($final['bootstrap']), filesize($cssPath)
    ));
    exit(0);
}

if (!is_dir($fontDirectory) && !mkdir($fontDirectory, 0755, true) && !is_dir($fontDirectory)) {
    fwrite(STDERR, "Unable to create icon output directory\n");
    exit(1);
}
$python = getenv('PYTHON_BIN') ?: 'python';
foreach (['tabler', 'bootstrap'] as $provider) {
    if ($final[$provider] === []) {
        if (is_file($fontPaths[$provider])) {
            unlink($fontPaths[$provider]);
        }
        continue;
    }
    $unicodes = array_map(
        static fn(string $name): string => 'U+' . strtoupper($maps[$provider][$name]),
        $final[$provider]
    );
    $command = escapeshellarg($python)
        . ' -m fontTools.subset ' . escapeshellarg($sources[$provider]['font'])
        . ' --output-file=' . escapeshellarg($fontPaths[$provider])
        . ' --flavor=woff2 --unicodes=' . escapeshellarg(implode(',', $unicodes))
        . ' --layout-features=* --no-recalc-timestamp';
    $output = [];
    $exitCode = 0;
    exec($command . ' 2>&1', $output, $exitCode);
    if ($exitCode !== 0 || !is_file($fontPaths[$provider])) {
        fwrite(STDERR, "Font subset failed ({$provider}). Install Python fonttools[brotli] and set PYTHON_BIN.\n");
        fwrite(STDERR, implode("\n", $output) . "\n");
        exit(1);
    }
}
if (file_put_contents($cssPath, $css) === false) {
    fwrite(STDERR, "Unable to write subset CSS\n");
    exit(1);
}

$outputs = [];
foreach ([$cssPath, ...array_values($fontPaths)] as $path) {
    if (!is_file($path)) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $outputs[$relative] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
}
$audit = [
    'schema' => 1,
    'generator' => 'tools/build-icon-subsets.php',
    'sources' => [],
    'discovered' => $discovered,
    'safelist' => $config['safelist'],
    'ignored_icon_values' => $config['ignored_icon_values'],
    'icons' => $final,
    'outputs' => $outputs,
];
foreach ($sources as $provider => $source) {
    $audit['sources'][$provider] = [
        'css_bytes' => filesize($source['css']),
        'font_bytes' => filesize($source['font']),
        'sha256' => hash_file('sha256', $source['css']),
        'font_sha256' => hash_file('sha256', $source['font']),
    ];
}
if (file_put_contents(
    $auditPath,
    json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
) === false) {
    fwrite(STDERR, "Unable to write icon audit\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Built site icons: %d Tabler, %d Bootstrap; CSS %d bytes; fonts %d bytes\n",
    count($final['tabler']), count($final['bootstrap']), filesize($cssPath),
    array_sum(array_map(static fn(array $item): int => (int) $item['bytes'], array_filter(
        $outputs,
        static fn(string $path): bool => str_ends_with($path, '.woff2'),
        ARRAY_FILTER_USE_KEY
    )))
));
