<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')
    || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) {
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
$action = $argv[1] ?? '';
if (!in_array($action, ['seed', 'lifecycle', 'track', 'draft-hide', 'publish-hide', 'disable', 'empty', 'invalid', 'sections-hidden', 'conditional', 'restore'], true)) {
    throw new RuntimeException('Invalid action');
}
$path = ROOT_PATH . '/storage/site-design-state.json';
$state = is_file($path) ? json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR) : null;
if ($action === 'restore') {
    if (!$state) {
        exit;
    }
    foreach ($state['settings'] as $key => $row) {
        if ($row === null) {
            db()->delete('settings', '`key` = ?', [$key]);
        } else {
            settingModel()->saveBatch([$key => $row['value']]);
        }
    }
    foreach ($state['templates'] as $id) {
        db()->delete('blox_templates', 'id = ?', [$id]);
    }
    db()->delete('contents', 'channel_id = ?', [$state['page']]);
    db()->delete('blox_page_drafts', 'page_id = ?', [$state['page']]);
    if (db()->tableExists('content_revisions')) {
        db()->delete('content_revisions', 'target_type = ? AND target_id = ?', ['page', $state['page']]);
    }
    db()->delete('channels', 'id = ?', [$state['page']]);
    unlink($path);
    cacheClear();
    HtmlCache::invalidate();
    exit;
}
if ($action === 'seed') {
    if ($state !== null) {
        throw new RuntimeException('Restore the previous fixture first');
    }
    $state = ['templates' => [], 'settings' => []];
    foreach (['current_theme', 'blox_custom_header_enabled', 'blox_custom_footer_enabled'] as $key) {
        $state['settings'][$key] = db()->fetchOne('SELECT value FROM ' . DB_PREFIX . 'settings WHERE `key` = ?', [$key]);
    }
    $state['page'] = (int) channelModel()->create([
        'name' => 'Site design state fixture', 'slug' => 'site-design-state-fixture',
        'type' => 'page', 'lang' => 'zh-CN', 'status' => 1, 'parent_id' => 0,
        'content' => '<p>Site design body fixture</p>', 'created_at' => time(), 'updated_at' => time(),
    ]);
    settingModel()->saveBatch(['current_theme' => 'default', 'blox_custom_header_enabled' => '1', 'blox_custom_footer_enabled' => '1']);
    $save = static function () use (&$state, $path): void {
        file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
    };
    $save();
    foreach (['header', 'footer'] as $area) {
        foreach (['active', 'other', 'draft'] as $kind) {
            $name = 'TB ' . $area . ' ' . $kind;
            $json = json_encode(['sections' => [['columns' => [['elements' => [
                ['type' => 'heading', 'data' => ['text' => $name, 'level' => 'h2']],
            ]]]]]], JSON_THROW_ON_ERROR);
            $id = bloxTemplateModel()->createDraft($area, $name, $json);
            $state['templates'][] = $id;
            $state[$area . '_' . $kind] = $id;
            $save();
            bloxTemplateModel()->saveConditions($id, [['main' => 'page', 'ids' => [$kind === 'other' ? 999999 : $state['page']], 'langs' => [], 'exclude' => false]]);
            if ($kind !== 'draft') {
                bloxTemplateModel()->publishDraft($id);
            }
        }
    }
    $save();
    echo json_encode($state, JSON_THROW_ON_ERROR);
} elseif (!$state) {
    throw new RuntimeException('Seed required');
} elseif ($action === 'lifecycle') {
    foreach ($state['templates'] as $id) {
        bloxTemplateModel()->saveConditions($id, [['main' => 'page', 'ids' => [999999], 'langs' => [], 'exclude' => false]]);
    }
    if (($argv[2] ?? '') === 'copy') {
        foreach (['header', 'footer'] as $area) {
            bloxTemplateModel()->saveConditions($state[$area . '_active'], [['main' => 'any', 'ids' => [], 'langs' => [], 'exclude' => false]]);
        }
    }
} elseif ($action === 'track') {
    $id = (int) ($argv[2] ?? 0);
    $template = bloxTemplateModel()->find($id);
    if ($id <= max($state['templates']) || !$template || !in_array($template['type'], ['header', 'footer'], true)) {
        throw new RuntimeException('New area template required');
    }
    $state['templates'][] = $id;
    file_put_contents($path, json_encode($state, JSON_THROW_ON_ERROR));
} elseif ($action === 'disable') {
    settingModel()->saveBatch(['blox_custom_header_enabled' => '0', 'blox_custom_footer_enabled' => '0']);
} elseif (in_array($action, ['empty', 'invalid', 'sections-hidden', 'conditional'], true)) {
    $json = match ($action) {
        'empty' => '[]',
        'invalid' => '{"schema":999,"sections":[]}',
        'conditional' => '{"sections":[{"settings":{"_conditions":[{"rules":[{"type":"login","operator":"is","value":"logged_in"}]}]},"columns":[]}]}',
        default => '{"sections":[{"settings":{"hidden":true},"columns":[]}]}',
    };
    foreach (['header', 'footer'] as $area) {
        db()->update('blox_templates', ['published_data' => $json], 'id = ?', [$state[$area . '_active']]);
    }
} else {
    $json = json_encode([
        'schema' => 1,
        'settings' => ['page_header_hidden' => true, 'page_footer_hidden' => true],
        'sections' => [['columns' => [['elements' => [['type' => 'heading', 'data' => ['text' => 'TB page content']]]]]]]],
        JSON_THROW_ON_ERROR
    );
    if ($action === 'draft-hide') {
        PageBloxDocument::saveDraft($state['page'], $json);
    } else {
        PageBloxDocument::saveAndPublish($state['page'], $json);
    }
}
cacheClear();
// Direct fixture writes must invalidate the anonymous page cache as web hooks do.
HtmlCache::invalidate();
