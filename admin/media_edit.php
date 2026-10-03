<?php
/**
 * YikaiCMS - 图片编辑接口（裁剪 / 旋转 / 翻转 / 替代文字；2.0.4）
 *
 * 非破坏性：第一次保存时把原图另存一份（storage/backups/media/originals/{id}.{ext}，不随替换备份轮换），
 * 之后每次保存都从这份原图按操作历史重放，再经 MediaReplacement 写回原路径——网址与全站引用不变，
 * 缩略图与 WebP 随之重建；「恢复原图」把原图写回并清掉历史。
 *
 *   GET  ?action=info&id=        编辑器初始化：原图尺寸、已保存的历史、替代文字
 *   GET  ?action=preview&id=&history=[...]   按历史从原图重放，缩到最长边 900，直接输出图片
 *   POST action=save    id, history, alt
 *   POST action=restore id
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/MediaReplacement.php';
require_once ROOT_PATH . '/includes/media/ImageEditor.php';

checkLogin();
requirePermission('media');

const YK_IMAGE_EDIT_PREVIEW = 900;

$action = (string) ($_REQUEST['action'] ?? '');
$id = (int) ($_REQUEST['id'] ?? 0);
$media = $id > 0 ? mediaModel()->find($id) : null;
if (!$media) error(__('media_replace_source_missing'));
$ext = strtolower((string) ($media['ext'] ?? pathinfo((string) $media['path'], PATHINFO_EXTENSION)));
$current = MediaOptimization::resolveSource($media) ?? '';
if ($current === '' || !is_file($current)) error(__('media_replace_source_missing'));
if (!ImageEditor::supported($ext)) error(__('image_edit_unsupported'));

$originalPath = ROOT_PATH . '/storage/backups/media/originals/' . $id . '.' . $ext;
$source = is_file($originalPath) ? $originalPath : $current;   // 编辑永远从原图开始
[$sw, $sh] = array_map('intval', array_slice((array) (@getimagesize($source) ?: [0, 0]), 0, 2));
if ($sw < 1 || !imageDimensionsWithinPixelLimit($sw, $sh, uploadMaxImageMegapixels() * 1000000)) error(__('image_edit_too_large'));
@ini_set('memory_limit', '512M');   // GD 解码后是未压缩位图：宽 × 高 × 4 字节

/** @return list<mixed> */
$history = static function (): array {
    $raw = (string) ($_REQUEST['history'] ?? '[]');
    $ops = json_decode($raw, true);
    return is_array($ops) && array_is_list($ops) ? $ops : [];
};

try {
    if ($action === 'info') {
        $saved = json_decode((string) getMeta('media', $id, 'edit_history', '[]'), true);
        success([
            'id' => $id, 'url' => (string) $media['url'], 'ext' => $ext,
            'width' => $sw, 'height' => $sh, 'edited' => is_file($originalPath),
            'history' => is_array($saved) ? $saved : [],
            'alt' => (string) getMeta('media', $id, 'alt', ''),
            'animated' => $ext === 'gif' && preg_match_all('/\x00\x21\xF9\x04/', (string) @file_get_contents($source, false, null, 0, 1048576)) > 1,
            'ratios' => array_keys(ImageEditPlan::RATIOS),
        ]);
    }

    if ($action === 'preview') {
        $plan = ImageEditPlan::normalize($sw, $sh, $history());
        $image = ImageEditor::render($source, $ext, $plan['ops'], YK_IMAGE_EDIT_PREVIEW);
        header('Cache-Control: no-store');
        header('X-Image-Width: ' . $plan['width']);
        header('X-Image-Height: ' . $plan['height']);
        // 预览一律出 PNG（无损，保留透明）；保存时才按原格式编码
        header('Content-Type: image/png');
        imagepng($image, null, 3);
        exit;
    }

    if ($action === 'save') {
        $plan = ImageEditPlan::normalize($sw, $sh, $history());
        $alt = trim((string) ($_POST['alt'] ?? ''));
        setMeta('media', $id, 'alt', mb_substr($alt, 0, 300));
        if ($plan['ops'] === [] && !is_file($originalPath)) {
            success(['id' => $id, 'changed' => false, 'alt' => $alt], __('image_edit_saved'));
        }
        if (!is_file($originalPath)) {
            if (!is_dir(dirname($originalPath)) && !@mkdir(dirname($originalPath), 0755, true)) error(__('media_replace_write_failed'));
            if (!@copy($current, $originalPath)) error(__('media_replace_write_failed'));
        }
        $tmp = ROOT_PATH . '/storage/backups/media/originals/edit-' . $id . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        try {
            $image = ImageEditor::render($originalPath, $ext, $plan['ops']);
            if (!ImageEditor::save($image, $tmp, $ext)) error(__('media_replace_write_failed'));
            $result = MediaReplacement::replace($media, $tmp, $ext);
        } finally {
            @unlink($tmp);
        }
        if (!$result['ok']) error(__((string) $result['msg']));
        mediaModel()->updateById($id, $result['meta']);
        if ($plan['ops'] === []) {
            // 回到原样：等同恢复原图
            @unlink($originalPath);
            db()->delete('metas', "owner_type = 'media' AND owner_id = ? AND meta_key = 'edit_history'", [$id]);
        } else {
            setMeta('media', $id, 'edit_history', json_encode($plan['ops'], JSON_UNESCAPED_SLASHES));
        }
        adminLog('media', 'edit', 'Edited image #' . $id . ' (' . count($plan['ops']) . ' steps)');
        success(['id' => $id, 'changed' => true, 'width' => $plan['width'], 'height' => $plan['height'],
            'url' => (string) $media['url'] . '?v=' . time(), 'alt' => $alt], __('image_edit_saved'));
    }

    if ($action === 'restore') {
        if (!is_file($originalPath)) error(__('image_edit_nothing_to_restore'));
        $result = MediaReplacement::replace($media, $originalPath, $ext);
        if (!$result['ok']) error(__((string) $result['msg']));
        mediaModel()->updateById($id, $result['meta']);
        @unlink($originalPath);
        db()->delete('metas', "owner_type = 'media' AND owner_id = ? AND meta_key = 'edit_history'", [$id]);
        adminLog('media', 'edit', 'Restored original image #' . $id);
        success(['id' => $id, 'url' => (string) $media['url'] . '?v=' . time()], __('image_edit_restored'));
    }
} catch (InvalidArgumentException $e) {
    error(__($e->getMessage()));
} catch (RuntimeException $e) {
    error(__('image_edit_failed'));
}

error(__('admin_fail'));
