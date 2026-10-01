<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
define('DB_DRIVER', 'sqlite');
define('DB_PATH', ':memory:');
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', true);
function config(string $key, mixed $default = ''): mixed { return settingModel()->get($key, $default); }
function __(string $key, array $params = []): string { return $key; }
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/SiteContentChecks.php';
db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/sqlite.sql'));
foreach (['settings', 'channels', 'contents', 'products', 'blox_templates', 'blox_page_drafts'] as $table) db()->execute('DELETE FROM ' . DB_PREFIX . $table);
settingModel()->clearCache();
function authoringAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function authoringSnapshot(): string
{
    $data = [];
    foreach (['settings', 'channels', 'contents', 'products', 'blox_templates', 'blox_page_drafts'] as $table) $data[$table] = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . $table . ' ORDER BY id');
    return hash('sha256', serialize($data));
}
$section = ['type' => 'section', 'settings' => ['anchor_id' => 'flow'], 'columns' => [['elements' => array_map(
    static fn(string $number): array => ['type' => 'text', 'data' => ['html' => '<p>' . $number . '</p>']], ['01', '02', '03'])]]];
$published = json_encode(['schema' => 1, 'sections' => [$section, $section]], JSON_THROW_ON_ERROR);
$image = ['type' => 'image', 'data' => ['src' => '/uploads/authoring-check-missing.png']];
$draftSection = ['columns' => [['elements' => [$image]]]];
$draft = json_encode(['schema' => 1, 'sections' => [$draftSection]], JSON_THROW_ON_ERROR);
settingModel()->saveBatch(['site_url' => 'https://site.test', 'home_blox_published' => $published, 'home_blox_data' => $draft]);
$pageId = (int) channelModel()->create(['name' => 'Authoring page', 'slug' => 'authoring-page', 'type' => 'page', 'status' => 1, 'lang' => 'en']);
$channelId = (int) channelModel()->create(['name' => 'Authoring list', 'slug' => 'authoring-list', 'type' => 'list', 'status' => 1, 'lang' => 'en']);
$hiddenId = (int) channelModel()->create(['name' => 'Hidden page', 'slug' => 'hidden', 'type' => 'page', 'status' => 0, 'lang' => 'en']);
contentModel()->create(['title' => 'Authoring page', 'channel_id' => $pageId, 'status' => 1, 'lang' => 'en', 'content_type' => 'blocks', 'blocks_data' => $published]);
bloxPageDraftModel()->saveForPage($pageId, $draft);
bloxPageDraftModel()->saveForPage($hiddenId, $draft);
bloxPageDraftModel()->publishForPage($channelId, $published);
$templateId = bloxTemplateModel()->createDraft('header', 'Header probe', $published);
bloxTemplateModel()->publishDraft($templateId);
$before = authoringSnapshot();
$report = SiteContentChecks::run(ROOT_PATH);
authoringAssert($before === authoringSnapshot(), 'Scanner modified stored content');
authoringAssert($report['documents'] === 4 && $report['drafts'] === 2, 'Coverage counts differ');
$kinds = array_column($report['issues'], 'kind');
authoringAssert(in_array('sc_numbered_repeat', $kinds, true), 'Repeated numbering not detected');
authoringAssert(in_array('sc_missing', $kinds, true), 'Draft asset not checked');
authoringAssert(count(array_filter($report['issues'], static fn(array $i): bool => $i['kind'] === 'sc_unpublished')) === 2, 'Incorrect draft comparison');
authoringAssert(!in_array('sc_empty', $kinds, true), 'Published Builder channel reported empty');
foreach ($report['issues'] as $issue) {
    authoringAssert($issue['label'] !== 'Hidden page', 'Disabled channel scanned');
    if ($issue['label'] === 'Authoring page') authoringAssert($issue['url'] === '/admin/blox_editor.php?id=' . $pageId, 'Wrong page editor URL');
    if ($issue['kind'] === 'sc_numbered_repeat') authoringAssert($issue['level'] === 'review', 'Design hint treated as failure');
}
echo "Site authoring checks passed\n";
