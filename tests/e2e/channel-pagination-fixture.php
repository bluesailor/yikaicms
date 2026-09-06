<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
if (DB_DRIVER !== 'sqlite' || parse_url(SITE_URL, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('Local SQLite required');
$action = $argv[1] ?? '';
if (!in_array($action, ['seed', 'cleanup'], true)) throw new RuntimeException('Invalid action');
$ids = [];
foreach (['case' => 'contents', 'download' => 'downloads', 'job' => 'jobs'] as $kind => $table) {
    $slug = 'e2e-paging-' . $kind;
    $old = channelModel()->findWhere(['slug' => $slug]);
    if ($old) {
        db()->delete('settings', '`key` = ?', ['catalog_channel_' . (int) $old['id'] . '_page_size']);
        db()->delete('channels', 'id = ?', [(int) $old['id']]);
    }
    db()->delete($table, 'title LIKE ?', ['E2E Paging ' . $kind . ' %']);
    if ($action === 'cleanup') continue;
    $id = db()->insert('channels', ['name' => 'E2E Paging ' . $kind, 'slug' => $slug, 'type' => $kind, 'lang' => 'zh-CN', 'status' => 1]);
    $ids[$kind] = (int) $id;
    foreach ([0, 1] as $status) {
        for ($i = 1; $i <= 22; $i++) {
            $row = ['title' => 'E2E Paging ' . $kind . ' ' . $status . ' ' . $i, 'lang' => 'zh-CN', 'status' => $status];
            if ($kind === 'case') $row += ['channel_id' => $id, 'type' => 'case', 'slug' => $slug . '-' . $status . '-' . $i, 'content' => '', 'summary' => ''];
            db()->insert($table, $row);
        }
    }
}
echo json_encode($ids, JSON_THROW_ON_ERROR);
