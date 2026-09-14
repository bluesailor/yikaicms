<?php
declare(strict_types=1);

// CLI-only probe: real document saves under a simulated licensed policy without the Pro module.
// Uses the PHPUnit in-memory database; never loads site configuration or the real policy file.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$policyFile = tempnam(sys_get_temp_dir(), 'yk-policy-');
if (!$policyFile) {
    throw new RuntimeException('Cannot create probe policy');
}
file_put_contents($policyFile, '<?php return ' . var_export([
    'query_loop' => 'licensed', 'display_conditions' => 'licensed', 'style_presets' => 'licensed',
], true) . ';');
define('YIKAI_BLOX_FEATURE_POLICY_FILE', $policyFile);

require dirname(__DIR__) . '/bootstrap.php';
if (!function_exists('cacheClear')) {
    function cacheClear(): void {}
}
if (!function_exists('do_action')) {
    function do_action(string $hook, mixed ...$args): void {}
}
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

$results = [];
$attempt = static function (string $name, callable $save) use (&$results): void {
    try {
        $save();
        $results[$name] = 'saved';
    } catch (Throwable $error) {
        $results[$name] = match ($error->getMessage()) {
            __('blox_save_conflict') => 'conflict',
            __('blox_protected_fields_changed') => 'protected',
            __('blox_query_loop_license_required') => 'license',
            default => 'error:' . $error->getMessage(),
        };
    }
};
$document = static fn(string $text, array $heading = ['site_field' => 'site_name']): string => json_encode([
    'schema' => 1, 'settings' => [], 'sections' => [[
        'id' => 's1', 'settings' => [], 'columns' => [['id' => 'c1', 'span' => 12, 'elements' => [
            ['id' => 'e1', 'type' => 'heading', 'data' => ['text' => 'Bound', 'level' => 'h2'] + $heading],
            ['id' => 'e2', 'type' => 'text', 'data' => ['html' => '<p>' . $text . '</p>']],
        ]]],
    ]],
], JSON_THROW_ON_ERROR);
$storedHeading = static function (string $json): array {
    return BloxDocumentPipeline::decode($json)['sections'][0]['columns'][0]['elements'][0]['data'] ?? [];
};

try {
    $pdo = db()->getPdo();
    $pdo->exec('CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, parent_id INTEGER NOT NULL DEFAULT 0, lang TEXT NOT NULL, name TEXT NOT NULL, slug TEXT NOT NULL DEFAULT \'\', description TEXT, content TEXT, updated_at INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL, status INTEGER NOT NULL DEFAULT 1, deleted_at INTEGER, is_top INTEGER NOT NULL DEFAULT 0, content_type TEXT NOT NULL DEFAULT \'html\', content TEXT, blocks_data TEXT, updated_at INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE content_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, target_type TEXT NOT NULL, target_id INTEGER NOT NULL, lang TEXT NOT NULL DEFAULT \'\', snapshot TEXT, summary TEXT NOT NULL DEFAULT \'\', admin_id INTEGER NOT NULL DEFAULT 0, admin_name TEXT NOT NULL DEFAULT \'\', created_at INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE blox_page_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL UNIQUE, draft_data TEXT NOT NULL, published_data TEXT, admin_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, published_at INTEGER NOT NULL DEFAULT 0)');
    $pdo->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', "key" TEXT UNIQUE, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)');

    $results['policy_denies_query_loop'] = !BloxFeaturePolicy::allows('query_loop');

    // 单页：旧文档已含站点字段绑定（授权期间或迁移遗留），当前站点无 Pro 模块。
    $pageId = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Protected', 'content' => '']);
    db()->insert('contents', ['channel_id' => $pageId, 'content_type' => 'blocks', 'content' => '', 'blocks_data' => $document('Old')]);
    $state = PageBloxDocument::load($pageId);

    $attempt('page_basic_edit', static fn() => PageBloxDocument::saveDraft($pageId, $document('New'), $state['base_revision']));
    $saved = PageBloxDocument::load($pageId);
    $results['page_binding_preserved'] = ($storedHeading($saved['document_json'])['site_field'] ?? '') === 'site_name';
    $results['page_text_saved'] = str_contains($saved['document_json'], '<p>New</p>');

    $attempt('page_missing_revision', static fn() => PageBloxDocument::saveDraft($pageId, $document('Newer'), ''));
    $attempt('page_stale_revision', static fn() => PageBloxDocument::saveDraft($pageId, $document('Newer'), $state['base_revision']));
    $attempt('page_change_binding', static fn() => PageBloxDocument::saveDraft($pageId, $document('New', ['site_field' => 'contact_email']), $saved['base_revision']));
    $attempt('page_drop_binding', static fn() => PageBloxDocument::saveDraft($pageId, $document('New', []), $saved['base_revision']));
    $results['page_draft_unchanged_after_rejections'] = PageBloxDocument::load($pageId)['base_revision'] === $saved['base_revision'];

    // 新文档不能借同文档保留：没有可信旧绑定时新增即拒绝。
    $freshId = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Fresh', 'content' => '']);
    $fresh = PageBloxDocument::load($freshId);
    $attempt('fresh_page_new_binding', static fn() => PageBloxDocument::saveDraft($freshId, $document('Copy'), $fresh['base_revision']));

    // 首页：可信文档来自已存草稿。
    db()->insert('settings', ['key' => HomeBloxDocument::DATA_KEY, 'value' => $document('Home old'), 'group' => 'home']);
    $GLOBALS['_test_config'][HomeBloxDocument::DATA_KEY] = $document('Home old');
    $homeState = HomeBloxDocument::load();
    $homeJson = json_encode(['schema' => $homeState['schema'], 'settings' => $homeState['settings'], 'sections' => $homeState['sections']], JSON_THROW_ON_ERROR);
    $homeRevision = BloxDocumentPipeline::fingerprint($homeJson);
    $attempt('home_missing_revision', static fn() => HomeBloxDocument::saveDraft($document('Home new'), ''));
    $attempt('home_change_binding', static fn() => HomeBloxDocument::saveDraft($document('Home new', ['site_field' => 'contact_email']), $homeRevision));
    $attempt('home_basic_edit', static fn() => HomeBloxDocument::saveDraft($document('Home new'), $homeRevision));
    $storedHome = (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', [HomeBloxDocument::DATA_KEY]);
    $results['home_binding_preserved'] = ($storedHeading($storedHome)['site_field'] ?? '') === 'site_name'
        && str_contains($storedHome, 'Home new');

    // 自定义循环模板：子元素普通文案可改，字段绑定不可改。
    $loopDocument = static fn(string $intro, string $field = 'title'): string => json_encode([
        'schema' => 1, 'settings' => [], 'sections' => [[
            'id' => 'ls1', 'settings' => [], 'columns' => [['id' => 'lc1', 'span' => 12, 'elements' => [
                ['id' => 'loop1', 'type' => 'list-dynamic', 'data' => ['source' => 'article', 'children' => [
                    ['id' => 'lh1', 'type' => 'heading', 'data' => ['text' => 'Title', 'level' => 'h3', 'loop_field' => $field]],
                    ['id' => 'lt1', 'type' => 'text', 'data' => ['html' => '<p>' . $intro . '</p>']],
                ]]],
            ]]],
        ]],
    ], JSON_THROW_ON_ERROR);
    $loopPageId = (int) db()->insert('channels', ['type' => 'page', 'lang' => 'en', 'name' => 'Loop', 'content' => '']);
    db()->insert('contents', ['channel_id' => $loopPageId, 'content_type' => 'blocks', 'content' => '', 'blocks_data' => $loopDocument('Old intro')]);
    $loopState = PageBloxDocument::load($loopPageId);
    $attempt('loop_child_text_edit', static fn() => PageBloxDocument::saveDraft($loopPageId, $loopDocument('New intro'), $loopState['base_revision']));
    $loopSaved = PageBloxDocument::load($loopPageId);
    $results['loop_child_text_saved'] = str_contains($loopSaved['document_json'], 'New intro')
        && str_contains($loopSaved['document_json'], '"loop_field":"title"');
    $attempt('loop_child_binding_change', static fn() => PageBloxDocument::saveDraft($loopPageId, $loopDocument('New intro', 'summary'), $loopSaved['base_revision']));

    // 历史恢复：同一页面的旧版本仍按当前文档基线检查。
    $contentId = (int) db()->fetchColumn('SELECT id FROM contents WHERE channel_id = ?', [$pageId]);
    $revision = static function (string $blocks) use ($pageId, $contentId): array {
        $id = (int) db()->insert('content_revisions', ['target_type' => 'page', 'target_id' => $pageId, 'lang' => 'en', 'snapshot' => json_encode(['targets' => [
            ['table' => 'channels', 'id' => $pageId, 'fields' => ['content' => '']],
            ['table' => 'contents', 'id' => $contentId, 'fields' => ['content' => '', 'content_type' => $blocks === '' ? 'html' : 'blocks', 'blocks_data' => $blocks]],
        ]], JSON_THROW_ON_ERROR), 'summary' => 'probe']);
        return (array) contentRevisionModel()->getOne($id);
    };
    $attempt('restore_changed_binding', static fn() => PageBloxDocument::restoreRevision($pageId, $revision($document('Restored', ['site_field' => 'contact_email']))));
    $attempt('restore_html_only_drops_binding', static fn() => PageBloxDocument::restoreRevision($pageId, $revision('')));
    $attempt('restore_basic_version', static fn() => PageBloxDocument::restoreRevision($pageId, $revision($document('Restored'))));
    $restored = PageBloxDocument::load($pageId);
    $results['restore_binding_preserved'] = ($storedHeading($restored['document_json'])['site_field'] ?? '') === 'site_name'
        && str_contains($restored['document_json'], '<p>Restored</p>');

    // 模板：可信基线只能是同一模板行的库内草稿；导入/复制/另存不传基线，按新建检查。
    $areaDraft = $document('Area old');
    $attempt('template_basic_edit', static fn() => BloxAreaDocument::process('header', $document('Area new'), 'tpl1', $areaDraft));
    $attempt('template_change_binding', static fn() => BloxAreaDocument::process('header', $document('Area new', ['site_field' => 'contact_email']), 'tpl1', $areaDraft));
    $attempt('template_import_without_baseline', static fn() => BloxAreaDocument::process('header', $areaDraft, 'tpl2'));
    $attempt('popup_basic_edit', static fn() => BloxPopupDocument::process($document('Popup new'), 'tpl3', $document('Popup old')));

    // 预览：与保存同一检查；旧绑定按服务端基线可参与预览，伪造的新绑定和无基线的来源仍拒绝。
    $previewSections = static fn(array $heading = ['site_field' => 'site_name']): array => BloxDocumentPipeline::decode($document('Preview', $heading))['sections'];
    $attempt('preview_basic_edit', static fn() => BloxDocumentPipeline::assertAuthoringAllowed($previewSections(), $saved['document_json']));
    $attempt('preview_change_binding', static fn() => BloxDocumentPipeline::assertAuthoringAllowed($previewSections(['site_field' => 'contact_email']), $saved['document_json']));
    $attempt('preview_without_baseline', static fn() => BloxDocumentPipeline::assertAuthoringAllowed($previewSections(), null));

    echo json_encode($results, JSON_THROW_ON_ERROR) . "\n";
} finally {
    unlink($policyFile);
}
