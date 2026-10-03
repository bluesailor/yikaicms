<?php
declare(strict_types=1);
// 图片编辑 e2e：在一次性测试站放一张 400×200 的图进媒体库；inspect 报告文件尺寸与替代文字；restore 清理。
if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-') || !is_file(ROOT_PATH . '/storage/.smoke-state-backup/manifest.json')) throw new RuntimeException('Disposable site required');
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
$file = ROOT_PATH . '/uploads/images/e2e-image-edit.png';
$state = STORAGE_PATH . '/e2e-image-edit.json';
$action = $argv[1] ?? '';
if ($action === 'setup') {
    if (!is_dir(dirname($file))) mkdir(dirname($file), 0755, true);
    $img = imagecreatetruecolor(400, 200);
    imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));
    imagefilledrectangle($img, 0, 0, 39, 19, (int) imagecolorallocate($img, 255, 0, 0));
    imagepng($img, $file);
    $id = (int) mediaModel()->create(['name' => 'e2e-image-edit.png', 'path' => $file, 'url' => '/uploads/images/e2e-image-edit.png', 'type' => 'image',
        'ext' => 'png', 'mime' => 'image/png', 'size' => (int) filesize($file), 'width' => 400, 'height' => 200, 'md5' => (string) md5_file($file), 'admin_id' => 1, 'created_at' => time()]);
    file_put_contents($state, json_encode(['id' => $id]));
    echo json_encode(['id' => $id]);
} elseif ($action === 'inspect') {
    $id = (int) json_decode((string) file_get_contents($state), true)['id'];
    clearstatcache();
    $size = getimagesize($file);
    $row = mediaModel()->find($id);
    echo json_encode(['w' => $size[0], 'h' => $size[1], 'row' => [(int) $row['width'], (int) $row['height']], 'alt' => (string) getMeta('media', $id, 'alt', ''),
        'original' => is_file(ROOT_PATH . '/storage/backups/media/originals/' . $id . '.png'), 'altFor' => mediaAltFor('/uploads/images/e2e-image-edit.png?v=1')]);
} elseif ($action === 'restore' && is_file($state)) {
    $id = (int) json_decode((string) file_get_contents($state), true)['id'];
    db()->delete('media', 'id = ?', [$id]);
    db()->delete('metas', "owner_type = 'media' AND owner_id = ?", [$id]);
    foreach (glob(ROOT_PATH . '/uploads/images/e2e-image-edit*') ?: [] as $f) @unlink($f);
    @unlink(ROOT_PATH . '/storage/backups/media/originals/' . $id . '.png');
    unlink($state);
} else throw new RuntimeException('Invalid action');
