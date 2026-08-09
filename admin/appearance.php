<?php
/**
 * 外观设置 —— 字体（第一个 tab；颜色/页头/页脚随后续批次接入）。
 *
 * 设计要点见 yikaicms-docs/appearance-settings-plan-2026-08-09.md：
 *  - 用户定制进 settings 表，design-tokens.json 只作主题出厂默认（主题升级会覆盖）；
 *  - 字体按语言分设（font_preset_en 等），英文正文观感普遍偏小，可单独调基准字号；
 *  - **未配置时前台不输出任何 style 块**，输出逐字节不变（对拍底线）；
 *  - 只用系统字体栈或自托管上传，绝不引字体 CDN。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/models/autoload.php';
require_once ROOT_PATH . '/includes/font_presets.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/admin/includes/trans_pills.php';

checkLogin();
requirePermission('*');

$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_sfx         = $_viewLang === $_defaultLang ? '' : ('_' . $_viewLang);

const FONT_DIR = ROOT_PATH . '/uploads/fonts';
const FONT_EXT = ['woff2', 'woff', 'ttf', 'otf'];
const FONT_MAX = 5242880;   // 5MB：woff2 中文子集也就 1-2MB，超过多半是没子集化的全量字库

// ── 上传字体 ──
if (($_POST['action'] ?? '') === 'upload_font') {
    verifyCsrf();
    $f = $_FILES['font'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        error(__('appr_font_pick'));
    }
    if ((int) $f['size'] > FONT_MAX) {
        error(__('appr_font_too_large'));
    }
    $ext = strtolower((string) pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, FONT_EXT, true)) {
        error(__('appr_font_ext_only'));
    }
    // 扩展名谁都能改，再核一次文件头：真是字体才收
    //   wOF2=woff2  wOFF=woff  OTTO=otf(CFF)  \0\1\0\0 / true / ttcf=ttf
    $magic = (string) @file_get_contents((string) $f['tmp_name'], false, null, 0, 4);
    if (!in_array($magic, ["wOF2", "wOFF", "OTTO", "\x00\x01\x00\x00", 'true', 'ttcf'], true)) {
        error(__('appr_font_not_font'));
    }
    // 文件名只留安全字符，避免路径穿越与奇怪的 URL 转义
    $base = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) pathinfo((string) $f['name'], PATHINFO_FILENAME));
    $base = trim((string) $base, '-') ?: 'font';
    if (!is_dir(FONT_DIR) && !mkdir(FONT_DIR, 0755, true) && !is_dir(FONT_DIR)) {
        error(__('appr_font_dir_failed'));
    }
    $name = $base . '.' . $ext;
    $i = 1;
    while (is_file(FONT_DIR . '/' . $name)) {
        $name = $base . '-' . (++$i) . '.' . $ext;
    }
    if (!move_uploaded_file((string) $f['tmp_name'], FONT_DIR . '/' . $name)) {
        error(__('admin_upload_failed'));
    }
    adminLog('setting', 'font_upload', '上传字体：' . $name);
    success(['file' => $name]);
}

// ── 删除字体 ──
if (($_POST['action'] ?? '') === 'delete_font') {
    verifyCsrf();
    $file = basename((string) post('file'));
    $path = FONT_DIR . '/' . $file;
    if ($file !== '' && is_file($path)) {
        @unlink($path);
        // 正在使用的被删掉：一并清掉引用，避免前台 @font-face 指向 404
        foreach (['', '_en', '_ja', '_zh-CN'] as $sfx) {
            if ((string) config('font_self_hosted' . $sfx, '') === $file) {
                settingModel()->set('font_self_hosted' . $sfx, '', 'appearance');
            }
        }
        adminLog('setting', 'font_delete', '删除字体：' . $file);
    }
    success();
}

// ── 保存设置 ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verifyCsrf();
    $preset = (string) post('font_preset', '');
    $presets = fontPresetsFor($_viewLang);
    if ($preset !== '' && $preset !== 'custom' && !isset($presets[$preset])) {
        $preset = '';
    }
    settingModel()->set('font_preset' . $_sfx, $preset, 'appearance');
    settingModel()->set('font_body_custom' . $_sfx, mb_substr(trim((string) post('font_body_custom')), 0, 500), 'appearance');
    settingModel()->set('font_heading_custom' . $_sfx, mb_substr(trim((string) post('font_heading_custom')), 0, 500), 'appearance');

    $size = trim((string) post('font_base_size'));
    if ($size !== '' && !preg_match('/^\d{2,3}(px|%)$/', $size)) {
        $size = '';
    }
    settingModel()->set('font_base_size' . $_sfx, $size, 'appearance');
    settingModel()->set('font_self_hosted' . $_sfx, basename((string) post('font_self_hosted')), 'appearance');

    adminLog('setting', 'appearance', '更新外观设置（字体）');
    do_action('data_changed');   // 清前台 HTML 缓存，否则改完看不到
    success();
}

$curPreset   = (string) config('font_preset' . $_sfx, '');
$curBody     = (string) config('font_body_custom' . $_sfx, '');
$curHeading  = (string) config('font_heading_custom' . $_sfx, '');
$curSize     = (string) config('font_base_size' . $_sfx, '');
$curSelf     = (string) config('font_self_hosted' . $_sfx, '');
$presets     = fontPresetsFor($_viewLang);
$fonts       = uploadedFonts();

$pageTitle   = __('appr_title');
$currentMenu = 'appearance';
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <h1 class="text-2xl font-bold text-gray-800"><?php echo e(__('appr_title')); ?></h1>
    <a href="/admin/theme.php" class="text-sm text-gray-500 hover:text-primary"><?php echo e(__('admin_theme')); ?> &raquo;</a>
</div>

<?php echo renderAdminLangSwitcher($_viewLang, __('appr_lang_hint')); ?>

<form id="apprForm" class="bg-white rounded-lg shadow p-6 space-y-6">
    <input type="hidden" name="action" value="save">
    <?php echo csrfField(); ?>

    <!-- 预设 -->
    <div>
        <label class="font-medium text-gray-800"><?php echo e(__('appr_font_preset')); ?></label>
        <p class="text-sm text-gray-500 mt-1 mb-3"><?php echo e(__('appr_font_preset_tip')); ?></p>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
            <label class="border rounded-lg p-3 cursor-pointer hover:border-primary <?php echo $curPreset === '' ? 'border-primary bg-blue-50' : ''; ?>">
                <input type="radio" name="font_preset" value="" class="mr-2" <?php echo $curPreset === '' ? 'checked' : ''; ?>>
                <span class="font-medium text-sm"><?php echo e(__('appr_font_default')); ?></span>
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('appr_font_default_tip')); ?></p>
            </label>
            <?php foreach ($presets as $key => $p): ?>
            <label class="border rounded-lg p-3 cursor-pointer hover:border-primary <?php echo $curPreset === $key ? 'border-primary bg-blue-50' : ''; ?>">
                <input type="radio" name="font_preset" value="<?php echo e($key); ?>" class="mr-2" <?php echo $curPreset === $key ? 'checked' : ''; ?>>
                <span class="font-medium text-sm"><?php echo e($p['label']); ?></span>
                <p class="mt-2 text-base text-gray-700" style="font-family:<?php echo e($p['body']); ?>">Aa 字体 あア</p>
            </label>
            <?php endforeach; ?>
            <label class="border rounded-lg p-3 cursor-pointer hover:border-primary <?php echo $curPreset === 'custom' ? 'border-primary bg-blue-50' : ''; ?>">
                <input type="radio" name="font_preset" value="custom" class="mr-2" <?php echo $curPreset === 'custom' ? 'checked' : ''; ?>>
                <span class="font-medium text-sm"><?php echo e(__('appr_font_custom')); ?></span>
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('appr_font_custom_tip')); ?></p>
            </label>
        </div>
    </div>

    <!-- 自定义字体栈 -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm text-gray-600 mb-1"><?php echo e(__('appr_font_body')); ?></label>
            <input type="text" name="font_body_custom" value="<?php echo e($curBody); ?>"
                   class="w-full border rounded px-4 py-2 font-mono text-xs" placeholder="Inter, Helvetica, Arial, sans-serif">
        </div>
        <div>
            <label class="block text-sm text-gray-600 mb-1"><?php echo e(__('appr_font_heading')); ?></label>
            <input type="text" name="font_heading_custom" value="<?php echo e($curHeading); ?>"
                   class="w-full border rounded px-4 py-2 font-mono text-xs" placeholder="<?php echo e(__('appr_font_heading_ph')); ?>">
        </div>
    </div>

    <hr>

    <!-- 基准字号 -->
    <div>
        <label class="font-medium text-gray-800"><?php echo e(__('appr_font_size')); ?></label>
        <p class="text-sm text-gray-500 mt-1 mb-2"><?php echo e(__('appr_font_size_tip')); ?></p>
        <input type="text" name="font_base_size" value="<?php echo e($curSize); ?>"
               class="w-40 border rounded px-4 py-2" placeholder="<?php echo e(__('appr_font_size_ph')); ?>">
    </div>

    <hr>

    <!-- 自托管字体 -->
    <div>
        <label class="font-medium text-gray-800"><?php echo e(__('appr_font_upload')); ?></label>
        <p class="text-sm text-gray-500 mt-1 mb-3"><?php echo e(__('appr_font_upload_tip')); ?></p>

        <div class="flex items-center gap-3 mb-3">
            <input type="file" id="fontFile" accept=".woff2,.woff,.ttf,.otf" class="text-sm">
            <button type="button" onclick="uploadFont()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded text-sm">
                <?php echo e(__('admin_upload')); ?>
            </button>
        </div>

        <?php if ($fonts === []): ?>
        <p class="text-sm text-gray-400"><?php echo e(__('appr_font_none')); ?></p>
        <?php else: ?>
        <div class="space-y-2">
            <label class="flex items-center gap-2 text-sm">
                <input type="radio" name="font_self_hosted" value="" <?php echo $curSelf === '' ? 'checked' : ''; ?>>
                <span class="text-gray-500"><?php echo e(__('appr_font_none_use')); ?></span>
            </label>
            <?php foreach ($fonts as $f): ?>
            <div class="flex items-center gap-3 border rounded px-3 py-2">
                <label class="flex items-center gap-2 flex-1 text-sm cursor-pointer">
                    <input type="radio" name="font_self_hosted" value="<?php echo e($f['file']); ?>" <?php echo $curSelf === $f['file'] ? 'checked' : ''; ?>>
                    <span class="font-medium"><?php echo e($f['name']); ?></span>
                    <span class="text-xs text-gray-400"><?php echo e(strtoupper((string) pathinfo($f['file'], PATHINFO_EXTENSION))); ?> · <?php echo e(formatFileSize($f['size'])); ?></span>
                </label>
                <button type="button" onclick="deleteFont('<?php echo e($f['file']); ?>')" class="text-red-400 hover:text-red-600 text-sm">
                    <?php echo e(__('admin_delete')); ?>
                </button>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="flex justify-end pt-2">
        <button type="button" onclick="saveAppearance()" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded">
            <?php echo e(__('admin_save_settings')); ?>
        </button>
    </div>
</form>

<script>
// 三个 POST 后端都走 verifyCsrf()；上传/删除不是从表单发的，token 得手动带上
const APPR_CSRF = <?php echo json_encode(csrfToken(), JSON_UNESCAPED_UNICODE); ?>;

async function saveAppearance() {
    const fd = new FormData(document.getElementById('apprForm'));
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) { showMessage(<?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE); ?>); setTimeout(() => location.reload(), 800); }
    else showMessage(d.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
}

async function uploadFont() {
    const inp = document.getElementById('fontFile');
    if (!inp.files || !inp.files[0]) { showMessage(<?php echo json_encode(__('appr_font_pick'), JSON_UNESCAPED_UNICODE); ?>, 'error'); return; }
    const fd = new FormData();
    fd.append('action', 'upload_font');
    fd.append('font', inp.files[0]);
    fd.append(<?php echo json_encode(CSRF_TOKEN_NAME, JSON_UNESCAPED_UNICODE); ?>, APPR_CSRF);
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) { showMessage(<?php echo json_encode(__('admin_upload_success'), JSON_UNESCAPED_UNICODE); ?>); setTimeout(() => location.reload(), 800); }
    else showMessage(d.msg || <?php echo json_encode(__('admin_upload_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
}

async function deleteFont(file) {
    if (!confirm(<?php echo json_encode(__('appr_font_delete_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    const fd = new FormData();
    fd.append('action', 'delete_font');
    fd.append('file', file);
    fd.append(<?php echo json_encode(CSRF_TOKEN_NAME, JSON_UNESCAPED_UNICODE); ?>, APPR_CSRF);
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await safeJson(r);
    if (d.code === 0) { showMessage(<?php echo json_encode(__('admin_deleted'), JSON_UNESCAPED_UNICODE); ?>); setTimeout(() => location.reload(), 800); }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
