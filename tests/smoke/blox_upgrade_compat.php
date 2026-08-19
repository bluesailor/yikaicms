<?php
/**
 * Upgrade a real tagged default-theme install to the current Blox-capable code.
 *
 * Usage: php tests/smoke/blox_upgrade_compat.php --from=v1.14.0
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$from = '';
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--from=')) {
        $from = substr((string) $arg, 7);
    }
}
if (preg_match('/^v1\.\d+\.\d+(?:\.\d+)?$/', $from) !== 1) {
    fwrite(STDERR, "Usage: php tests/smoke/blox_upgrade_compat.php --from=v1.x.y\n");
    exit(2);
}

function upgradeFail(string $message): never
{
    fwrite(STDERR, "\nBLOX UPGRADE COMPAT FAILED\n\n{$message}\n");
    exit(1);
}

function upgradeCheck(bool $condition, string $message): void
{
    if (!$condition) {
        upgradeFail($message);
    }
    echo "  [ok] {$message}\n";
}

function taggedInstallSql(string $root, string $tag): string
{
    $command = ['git', '-C', $root, 'show', $tag . ':install/sql/sqlite.sql'];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes);
    if (!is_resource($process)) {
        upgradeFail("Cannot start git for {$tag}");
    }
    fclose($pipes[0]);
    $sql = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    if ($status !== 0 || !is_string($sql) || trim($sql) === '') {
        upgradeFail("Cannot read {$tag} install SQL: " . trim((string) $error));
    }
    return $sql;
}

function settingValue(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare('SELECT value FROM yikai_settings WHERE "key" = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string) $value;
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    foreach ($pdo->query("PRAGMA table_info('{$table}')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((string) ($row['name'] ?? '') === $column) {
            return true;
        }
    }
    return false;
}

$safeTag = preg_replace('/[^a-zA-Z0-9_.-]/', '-', $from) ?: 'legacy';
$dbPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
    . 'yikaicms-blox-upgrade-' . $safeTag . '-' . bin2hex(random_bytes(5)) . '.sqlite';
register_shutdown_function(static function () use ($dbPath): void {
    if (is_file($dbPath)) {
        @unlink($dbPath);
    }
});

echo "Blox upgrade compatibility: {$from} -> current\n";
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec(taggedInstallSql($root, $from));

upgradeCheck(settingValue($pdo, 'current_theme') === 'default', 'legacy site uses the default theme');

$page = $pdo->query(
    "SELECT ch.id AS page_id, ct.id AS content_id
       FROM yikai_channels ch
       JOIN yikai_contents ct ON ct.channel_id = ch.id
      WHERE ch.type = 'page'
        AND ct.status = 1
        AND COALESCE(ct.content_type, 'html') = 'html'
        AND (ct.blocks_data IS NULL OR ct.blocks_data = '')
      ORDER BY ch.id, ct.id
      LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($page)) {
    upgradeFail("{$from} has no published legacy HTML page fixture");
}

$pageId = (int) $page['page_id'];
$contentId = (int) $page['content_id'];
$legacyHtml = '<h2 data-upgrade-marker="' . htmlspecialchars($from, ENT_QUOTES, 'UTF-8')
    . '">Customer page</h2><p>Keep this published HTML during the upgrade.</p>';
$stmt = $pdo->prepare('UPDATE yikai_channels SET content = ? WHERE id = ?');
$stmt->execute([$legacyHtml, $pageId]);
$stmt = $pdo->prepare("UPDATE yikai_contents SET content = ?, content_type = 'html', blocks_data = NULL WHERE id = ?");
$stmt->execute([$legacyHtml, $contentId]);
$homeLayoutBefore = settingValue($pdo, 'home_blocks_config');
$homeDraftBefore = settingValue($pdo, 'home_blox_data');
$homePublishedBefore = settingValue($pdo, 'home_blox_published');
$homeActiveBefore = settingValue($pdo, 'home_blox_active');
$hadPublishedBloxHome = $homeActiveBefore === '1'
    && trim((string) $homePublishedBefore) !== '';
$pdo = null;

define('ROOT_PATH', $root);
define('IK_CLI', true);
define('DEBUG', false);
define('DB_DRIVER', 'sqlite');
define('DB_PATH', $dbPath);
define('DB_PREFIX', 'yikai_');

$config = is_file($root . '/config/config.php')
    ? $root . '/config/config.php'
    : $root . '/config/config.php.example';
set_error_handler(static fn (int $number, string $message): bool => str_contains($message, 'already defined'));
require $config;
restore_error_handler();

require_once $root . '/includes/functions.php';
require_once $root . '/includes/models/autoload.php';
initLang();
require_once $root . '/includes/hooks.php';
require_once $root . '/includes/Migrator.php';

$ran = 0;
foreach (Migrator::loadAll() as $migration) {
    if (Migrator::isApplied($migration)) {
        continue;
    }
    $result = Migrator::runOne($migration);
    if (!$result['ok']) {
        upgradeFail((string) $migration['id'] . ': ' . $result['message']);
    }
    if (!Migrator::isApplied($migration)) {
        upgradeFail((string) $migration['id'] . ': check() remains false after migration');
    }
    $ran++;
}
echo "  migrations applied: {$ran}\n";

$pdo = db()->getPdo();
upgradeCheck(tableExists($pdo, 'yikai_blox_page_drafts'), 'Blox page draft storage exists after migration');
upgradeCheck(columnExists($pdo, 'yikai_blox_page_drafts', 'published_data'), 'Blox channel publication storage exists after migration');
upgradeCheck(settingValue($pdo, 'current_theme') === 'default', 'migration keeps the selected default theme');
upgradeCheck(currentTheme() === 'default', 'runtime resolves the upgraded site to default');
upgradeCheck(is_file(theme_path('layouts/header.php')), 'default header remains available');
upgradeCheck(is_file(theme_path('layouts/footer.php')), 'default footer remains available');
require_once $root . '/includes/builder/bootstrap.php';
upgradeCheck(HomeBloxDocument::hasDraft(), $hadPublishedBloxHome
    ? 'upgrade preserves the existing Blox homepage draft'
    : 'upgrade creates a Blox homepage draft for the default theme');
if ($hadPublishedBloxHome) {
    upgradeCheck(HomeBloxDocument::isActive(), 'upgrade preserves the active Blox homepage');
    upgradeCheck(HomeBloxDocument::hasPublished(), 'upgrade preserves the published Blox homepage');
    upgradeCheck(settingValue($pdo, 'home_blox_data') === $homeDraftBefore, 'upgrade preserves the Blox homepage draft byte-for-byte');
    upgradeCheck(settingValue($pdo, 'home_blox_published') === $homePublishedBefore, 'upgrade preserves the Blox homepage publication byte-for-byte');
    upgradeCheck(settingValue($pdo, 'home_blox_active') === $homeActiveBefore, 'upgrade preserves the Blox homepage activation flag');
} else {
    upgradeCheck(HomeBloxDocument::load()['source'] === 'legacy-import-complete-v2', 'automatic homepage draft records its completed legacy source');
    upgradeCheck(settingValue($pdo, 'home_blox_active') !== '1', 'upgrade does not activate a Blox homepage');
    upgradeCheck(!HomeBloxDocument::hasPublished(), 'upgrade does not publish the Blox homepage');
}
upgradeCheck(settingValue($pdo, 'blox_editor_enabled') === '1', 'upgrade enables the Blox editor by default');
upgradeCheck(bloxPageEditorEnabled(), 'free Blox page editing is available after upgrade');
upgradeCheck(bloxPageEditorEnabled(), 'the Blox editor switch is on without a commercial license');
upgradeCheck(!bloxAdvancedFeaturesEnabled(), 'advanced Blox remains behind the entitlement gate');
upgradeCheck(!bloxEditorEnabled(), 'legacy advanced modules remain behind the entitlement gate');
upgradeCheck(settingValue($pdo, 'home_blocks_config') === $homeLayoutBefore, 'legacy homepage layout data remains unchanged');

$migratedLegacyHtml = $pdo->query('SELECT content FROM yikai_contents WHERE id = ' . $contentId)->fetchColumn();
upgradeCheck($migratedLegacyHtml === $legacyHtml, 'migrations preserve legacy page HTML');

$convertedHome = HomeBloxDocument::createDraftFromLegacy();
if ($hadPublishedBloxHome) {
    upgradeCheck(settingValue($pdo, 'home_blox_data') === $homeDraftBefore, 'legacy conversion leaves the existing Blox draft unchanged');
    upgradeCheck(settingValue($pdo, 'home_blox_published') === $homePublishedBefore, 'legacy conversion leaves the existing Blox publication unchanged');
    upgradeCheck(HomeBloxDocument::isActive(), 'legacy conversion keeps the existing Blox homepage active');
} else {
    upgradeCheck($convertedHome['source'] === 'legacy-import-complete-v2', 'classic homepage draft remains the completed automatic import');
    upgradeCheck(!HomeBloxDocument::isActive(), 'classic conversion does not activate the Blox homepage');
    upgradeCheck(!HomeBloxDocument::hasPublished(), 'classic conversion does not publish the Blox homepage');
    upgradeCheck(settingValue($pdo, 'home_blocks_config') === $homeLayoutBefore, 'classic conversion preserves the legacy homepage configuration');
}

$publishedBeforeEditor = $pdo->query('SELECT content, content_type, blocks_data FROM yikai_contents WHERE id = ' . $contentId)
    ->fetch(PDO::FETCH_ASSOC);

$legacyPageIds = $pdo->query(
    "SELECT DISTINCT ch.id
       FROM yikai_channels ch
       JOIN yikai_contents ct ON ct.channel_id = ch.id
      WHERE ch.type = 'page'
        AND ct.status = 1
        AND ct.deleted_at IS NULL
        AND COALESCE(ct.content_type, 'html') = 'html'
        AND (ct.blocks_data IS NULL OR ct.blocks_data = '')
        AND TRIM(COALESCE(ct.content, '')) <> ''
      ORDER BY ch.id"
)->fetchAll(PDO::FETCH_COLUMN);
$legacyPagesChecked = 0;
foreach ($legacyPageIds as $legacyPageId) {
    $legacyPageId = (int) $legacyPageId;
    $legacyRecord = $pdo->query(
        'SELECT content FROM yikai_contents WHERE channel_id = ' . $legacyPageId
        . ' AND status = 1 AND deleted_at IS NULL ORDER BY is_top DESC, id DESC LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    $legacyState = PageBloxDocument::load($legacyPageId);
    $legacyDocument = BloxDocumentPipeline::decode($legacyState['document_json']);
    $legacyElement = $legacyDocument['sections'][0]['columns'][0]['elements'][0] ?? [];
    if ($legacyState['has_draft']
        || ($legacyElement['type'] ?? '') !== 'text'
        || ($legacyElement['data']['html'] ?? '') !== trim((string) ($legacyRecord['content'] ?? ''))) {
        upgradeFail("legacy page {$legacyPageId} cannot be imported without changing its HTML");
    }
    renderBlocksToHtml($legacyState['document_json']);
    $legacyPagesChecked++;
}
upgradeCheck($legacyPagesChecked > 0, "all {$legacyPagesChecked} legacy HTML pages import and render in Blox");

$state = PageBloxDocument::load($pageId);
$document = BloxDocumentPipeline::decode($state['document_json']);
$firstElement = $document['sections'][0]['columns'][0]['elements'][0] ?? [];
upgradeCheck(!$state['has_draft'], 'opening the legacy page does not create a draft');
upgradeCheck(!$state['has_published'], 'legacy HTML is not falsely marked as published Blox data');
upgradeCheck(($firstElement['type'] ?? '') === 'text', 'legacy HTML is imported as an editable text element');
upgradeCheck(($firstElement['data']['html'] ?? '') === $legacyHtml, 'legacy page HTML is preserved byte-for-byte on import');

$afterOpen = $pdo->query('SELECT content, content_type, blocks_data FROM yikai_contents WHERE id = ' . $contentId)
    ->fetch(PDO::FETCH_ASSOC);
upgradeCheck($afterOpen === $publishedBeforeEditor, 'opening Blox does not change published page data');

$draft = PageBloxDocument::saveDraft($pageId, $state['document_json'], $state['base_revision'], 1);
upgradeCheck(!empty($draft['base_revision']), 'the upgraded page can save a Blox draft');
$afterDraft = $pdo->query('SELECT content, content_type, blocks_data FROM yikai_contents WHERE id = ' . $contentId)
    ->fetch(PDO::FETCH_ASSOC);
upgradeCheck($afterDraft === $publishedBeforeEditor, 'saving a Blox draft does not change the live page');
upgradeCheck(bloxPageDraftModel()->findByPageId($pageId) !== null, 'the draft is stored separately');

$published = PageBloxDocument::saveAndPublish(
    $pageId,
    $state['document_json'],
    (string) $draft['base_revision'],
    1
);
upgradeCheck($published['published'], 'the upgraded legacy page can be explicitly published with Blox');
$afterPublish = $pdo->query('SELECT content, content_type, blocks_data FROM yikai_contents WHERE id = ' . $contentId)
    ->fetch(PDO::FETCH_ASSOC);
upgradeCheck(($afterPublish['content_type'] ?? '') === 'blocks', 'explicit publish switches only that page to Blox');
upgradeCheck(trim((string) ($afterPublish['blocks_data'] ?? '')) !== '', 'explicit publish stores the Blox document');
upgradeCheck(str_contains((string) ($afterPublish['content'] ?? ''), 'Customer page'), 'explicit publish keeps the customer content visible');

$areaCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM yikai_blox_templates WHERE type IN ('header', 'footer') AND status = 1"
)->fetchColumn();
upgradeCheck($areaCount === 0, 'upgrade does not silently replace the default theme header or footer');

echo "BLOX UPGRADE COMPAT OK: {$from}\n";
