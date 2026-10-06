<?php
/**
 * 组件（v2.1 RFC-2）e2e 夹具：一个已发布的「产品卡片」母版（标题 / 按钮文字两个属性）与一个放了实例的 Blox 页面。
 * seed → 输出 {page,url,template,uuid}；republish <标题> → 改母版默认标题并发布；restore 删除夹具期间的一切。
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) {
    throw new RuntimeException('Disposable smoke site required');
}
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/hooks.php';
require ROOT_PATH . '/includes/HtmlCache.php';
require ROOT_PATH . '/includes/builder/bootstrap.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') {
    throw new RuntimeException('Local SQLite required');
}

$path = ROOT_PATH . '/storage/components-fixture.json';
$action = (string) ($argv[1] ?? '');
$state = is_file($path) ? json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR) : null;

$master = static fn (string $title): string => json_encode([
    'schema' => 1,
    'settings' => ['component' => ['props' => [
        ['key' => 'title', 'type' => 'text', 'label' => 'Title', 'default' => $title, 'targets' => [['node' => 'cf-title', 'field' => 'text']]],
        ['key' => 'cta', 'type' => 'text', 'label' => 'Button', 'default' => 'Learn more', 'targets' => [['node' => 'cf-button', 'field' => 'text']]],
    ]]],
    'sections' => [[
        'id' => 'cf-s', 'type' => 'section', 'settings' => [],
        'columns' => [['id' => 'cf-c', 'elements' => [[
            'id' => 'cf-root', 'type' => 'container', 'data' => ['children' => [
                ['id' => 'cf-title', 'type' => 'heading', 'data' => ['text' => $title, 'level' => 'h3']],
                ['id' => 'cf-button', 'type' => 'button', 'data' => ['text' => 'Learn more', 'url' => '/contact.html']],
            ]],
        ]]]],
    ]],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

if ($action === 'restore') {
    settingModel()->set('enabled_languages', '');
    if (is_array($state)) {
        db()->delete('contents', 'channel_id = ?', [(int) $state['page']]);
        db()->delete('blox_page_drafts', 'page_id = ?', [(int) $state['page']]);
        if (db()->tableExists('content_revisions')) {
            db()->delete('content_revisions', 'target_type = ? AND target_id = ?', ['page', (int) $state['page']]);
        }
        db()->delete('channels', 'id = ?', [(int) $state['page']]);
        db()->execute('DELETE FROM ' . DB_PREFIX . "blox_templates WHERE type = 'component' AND created_at >= ?", [(int) $state['started']]);
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_component_refs');
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_component_revisions WHERE created_at >= ?', [(int) $state['started']]);
        unlink($path);
    }
    cacheClear();
    HtmlCache::invalidate();
    echo "restored\n";
    exit;
}

if ($action === 'languages') {
    // on：启用中英两种语言（母版里出现按语言填默认值）；off：恢复单语言
    settingModel()->set('enabled_languages', ($argv[2] ?? 'off') === 'on' ? json_encode(['zh-CN', 'en']) : '');
    cacheClear();
    echo "languages
";
    exit;
}

if ($action === 'republish') {
    if (!is_array($state)) {
        throw new RuntimeException('seed first');
    }
    $processed = BloxComponents::processMaster($master((string) ($argv[2] ?? 'Updated title')), 'tpl' . (int) $state['template']);
    bloxTemplateModel()->updateDraft((int) $state['template'], $processed['json'], BloxTemplateImporter::deriveRequirements($processed['sections']));
    bloxTemplateModel()->publishDraft((int) $state['template']);
    cacheClear();
    HtmlCache::invalidate();
    echo "republished\n";
    exit;
}

if ($action !== 'seed') {
    throw new RuntimeException('usage: seed|republish <title>|restore');
}
if ($state !== null) {
    throw new RuntimeException('Restore the previous fixture first');
}

$started = time();
$processed = BloxComponents::processMaster($master('Fixture product'), 'tpl0');
$template = bloxTemplateModel()->createDraft('component', 'Fixture product card', $processed['json']);
bloxTemplateModel()->publishDraft($template);
$row = bloxTemplateModel()->find($template) ?? [];
$uuid = (string) (json_decode((string) ($row['metadata'] ?? ''), true)['component']['uuid'] ?? '');
BloxComponents::resetForTests();

$page = (int) channelModel()->create([
    'name' => 'Components fixture', 'slug' => 'components-fixture',
    'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
    'content' => '', 'created_at' => $started, 'updated_at' => $started,
]);
file_put_contents($path, json_encode(['page' => $page, 'template' => $template, 'started' => $started], JSON_THROW_ON_ERROR));

$json = json_encode([
    'schema' => 1,
    'sections' => [[
        'id' => 'cfp-s', 'settings' => ['padding' => 'lg'],
        'columns' => [['id' => 'cfp-c', 'elements' => [
            ['id' => 'cfp-instance', 'type' => 'component', 'data' => ['component' => $uuid, 'props' => []]],
            ['id' => 'cfp-heading', 'type' => 'heading', 'data' => ['text' => 'Plain heading', 'level' => 'h2']],
        ]]],
    ]],
], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
BloxFeaturePolicy::asTrustedWrite(static function () use ($page, $json): void {
    PageBloxDocument::saveAndPublish($page, $json);
});
cacheClear();
HtmlCache::invalidate();
$channel = channelModel()->find($page) ?? [];
echo json_encode(['page' => $page, 'url' => channelUrl($channel), 'template' => $template, 'uuid' => $uuid], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
