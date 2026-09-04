<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$sources = [
    'tabler' => [$root . '/assets/tabler/tabler-icons.min.css', '/\.ti-([a-z0-9-]+):before/'],
    'bootstrap' => [$root . '/assets/bootstrap-icons/bootstrap-icons.min.css', '/\.bi-([a-z0-9-]+)::before/'],
];
$catalog = ['schema' => 1, 'tabler' => [], 'bootstrap' => []];

foreach ($sources as $provider => [$path, $pattern]) {
    $css = file_get_contents($path);
    if (!is_string($css) || preg_match_all($pattern, $css, $matches) === false) {
        fwrite(STDERR, "Unable to read icon source: {$path}\n");
        exit(1);
    }
    $names = array_values(array_unique($matches[1]));
    $catalog[$provider] = $provider === 'bootstrap'
        ? array_map(static fn (string $name): string => 'bi:' . $name, $names)
        : $names;
}

$output = $root . '/assets/icons/blox-icon-catalog.json';
$json = json_encode($catalog, JSON_UNESCAPED_SLASHES);
if (!is_string($json) || file_put_contents($output, $json . "\n") === false) {
    fwrite(STDERR, "Unable to write icon catalog: {$output}\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Blox icon catalog: %d Tabler, %d Bootstrap\n",
    count($catalog['tabler']),
    count($catalog['bootstrap'])
));
