<?php

declare(strict_types=1);

// No Composer/PHPUnit bootstrap: this script must execute on the PHP 8.0 floor.
require_once dirname(__DIR__, 2) . '/includes/UpgradeEntryOrder.php';

$sources = [
    'includes/Dependency.php' => '<?php final class Dependency {}',
    'includes/Consumer.php' => "<?php require_once __DIR__ . '/Dependency.php';",
];
$entries = [
    ['rel' => 'includes/Consumer.php'],
    ['rel' => 'includes/Dependency.php'],
];
$ordered = UpgradeEntryOrder::sort(
    $entries,
    static fn (array $entry): string => $sources[(string) $entry['rel']] ?? ''
);

if (array_column($ordered, 'rel') !== ['includes/Dependency.php', 'includes/Consumer.php']) {
    fwrite(STDERR, "Upgrade entry ordering compatibility failed\n");
    exit(1);
}

echo 'PHP ' . PHP_VERSION . ': upgrade entry ordering passed' . PHP_EOL;
