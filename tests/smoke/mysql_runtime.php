<?php
/**
 * MySQL installation runtime gate.
 *
 * The CI job installs YikaiCMS through the real HTTP installer first. This
 * script then verifies that the generated configuration boots, all migrations
 * are represented by the fresh-install seed, transactions work, and the
 * existing HTTP smoke clients receive fixture IDs from the MySQL database.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
define('IK_CLI', true);

require_once $root . '/includes/init.php';
require_once $root . '/includes/Migrator.php';

/** @return never */
function mysqlSmokeFail(string $message): void
{
    fwrite(STDERR, "MYSQL SMOKE FAILED: {$message}\n");
    exit(1);
}

if (DB_DRIVER !== 'mysql' || db()->isSqlite()) {
    mysqlSmokeFail('the installed site is not using the mysql driver');
}

$version = (string) db()->fetchColumn('SELECT VERSION()');
if ($version === '') {
    mysqlSmokeFail('SELECT VERSION() returned an empty value');
}
$expectedSeries = (string) getenv('EXPECTED_MYSQL_SERIES');
if ($expectedSeries !== '' && !str_starts_with($version, $expectedSeries . '.')) {
    mysqlSmokeFail("expected MySQL {$expectedSeries}.x, got {$version}");
}

$tableCount = (int) db()->fetchColumn(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ?',
    [DB_NAME]
);
if ($tableCount < 35) {
    mysqlSmokeFail("fresh installation created only {$tableCount} tables");
}

$pending = [];
foreach (Migrator::loadAll() as $migration) {
    if (!Migrator::isApplied($migration)) {
        $pending[] = (string) ($migration['id'] ?? 'unknown');
    }
}
if ($pending !== []) {
    mysqlSmokeFail('fresh installation has pending migrations: ' . implode(', ', $pending));
}

// Parameter binding and transaction rollback are both production-critical and
// differ materially from SQLite behavior. The probe row must never persist.
$probeKey = 'mysql_ci_probe_' . bin2hex(random_bytes(6));
$transactionStarted = db()->beginTransaction();
try {
    db()->insert('settings', [
        'group' => 'system',
        'key' => $probeKey,
        'value' => 'bound-value',
        'type' => 'text',
        'name' => 'MySQL CI probe',
        'tip' => '',
        'options' => null,
        'sort_order' => 999,
    ]);
    $stored = db()->fetchColumn(
        'SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?',
        [$probeKey]
    );
    if ($stored !== 'bound-value') {
        throw new RuntimeException('parameterized insert/read did not round-trip');
    }
    db()->rollback();
    $transactionStarted = false;
} catch (Throwable $e) {
    if ($transactionStarted) {
        try {
            db()->rollback();
        } catch (Throwable) {
            // Preserve the original smoke failure below.
        }
    }
    mysqlSmokeFail($e->getMessage());
}
if (db()->fetchOne('SELECT id FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$probeKey]) !== null) {
    mysqlSmokeFail('transaction rollback left the probe row behind');
}

$siteLang = (string) config('site_lang', 'zh-CN');
$channelList = (int) (db()->fetchColumn(
    'SELECT id FROM ' . DB_PREFIX . 'channels WHERE type = ? AND lang = ? ORDER BY id LIMIT 1',
    ['list', $siteLang]
) ?: db()->fetchColumn(
    'SELECT id FROM ' . DB_PREFIX . 'channels WHERE type = ? ORDER BY id LIMIT 1',
    ['list']
));
$channelAny = (int) (db()->fetchColumn(
    'SELECT id FROM ' . DB_PREFIX . 'channels WHERE lang = ? ORDER BY id LIMIT 1',
    [$siteLang]
) ?: db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'channels ORDER BY id LIMIT 1'));
$productCategory = (int) (db()->fetchColumn(
    'SELECT id FROM ' . DB_PREFIX . 'product_categories WHERE lang = ? ORDER BY id LIMIT 1',
    [$siteLang]
) ?: db()->fetchColumn('SELECT id FROM ' . DB_PREFIX . 'product_categories ORDER BY id LIMIT 1'));
$downloadCategory = (int) db()->fetchColumn(
    'SELECT id FROM ' . DB_PREFIX . 'download_categories ORDER BY id LIMIT 1'
);

if ($channelList <= 0 || $channelAny <= 0 || $productCategory <= 0 || $downloadCategory <= 0) {
    mysqlSmokeFail('fresh installation is missing fixture rows required by the admin smoke');
}

$fixtures = [
    'channel_list' => $channelList,
    'channel_any' => $channelAny,
    'product_cat' => $productCategory,
    'download_cat' => $downloadCategory,
    'tables' => $tableCount,
    'blox_template' => 0,
    'blox_header_template' => 0,
    'blox_page' => 0,
    'blox_page_url' => '',
];
$encoded = json_encode($fixtures, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents(__DIR__ . '/fixtures.json', $encoded) === false) {
    mysqlSmokeFail('could not write fixtures.json');
}

echo "MYSQL RUNTIME OK: version={$version}, tables={$tableCount}, migrations=" . count(Migrator::loadAll()) . "\n";
