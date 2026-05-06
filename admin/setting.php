<?php
/**
 * ikaiCMS - 站点设置
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    // 恢复默认值
    if ($action === 'restore_defaults') {
        verifyCsrf();
        $restoreGroup = $_POST['group'] ?? '';
        $restoreKey = $_POST['key'] ?? '';
        if ($restoreKey) {
            // 恢复单个设置
            $defaultValue = getDefault($restoreKey, null);
            if ($defaultValue !== null) {
                settingModel()->set($restoreKey, $defaultValue);
                adminLog('setting', 'restore', '恢复默认值: ' . $restoreKey);
                success(['value' => $defaultValue]);
            }
            error('未找到该设置的默认值');
        } elseif ($restoreGroup) {
            // 恢复整个分组
            $groupDefaults = getDefaults($restoreGroup);
            $batch = [];
            foreach ($groupDefaults as $k => $item) {
                $batch[$k] = $item['value'];
            }
            settingModel()->saveBatch($batch);
            adminLog('setting', 'restore', '恢复分组默认值: ' . $restoreGroup);
            success();
        }
        error('参数错误');
    }

    $settings = $_POST['settings'] ?? [];
    settingModel()->saveBatch($settings);

    adminLog('setting', 'update', '更新站点设置');
    success();
}

$tab = $_GET['tab'] ?? 'basic';
$groupMap = [
    'basic'  => 'basic',
    'header' => 'header',
    'footer' => 'footer',
    'code'   => 'code',
];
$group = $groupMap[$tab] ?? 'basic';

$items = settingModel()->getByGroup($group);
$items = array_filter($items, fn($item) => !str_starts_with($item['key'], 'admin_menu_') && !str_starts_with($item['key'], 'ai_') && $item['key'] !== 'current_theme');
$groupDefaults = getDefaults($group);

$pageTitle = __('setting_page_title');
$currentMenu = 'setting';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/setting.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'basic' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_basic'); ?></a>
        <a href="/admin/setting.php?tab=header" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'header' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_header'); ?></a>
        <a href="/admin/setting.php?tab=footer" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'footer' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_footer'); ?></a>
        <a href="/admin/setting.php?tab=code" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'code' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>"><?php echo __('setting_tab_code'); ?></a>
    </div>
</div>

<form id="settingForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo ['basic'=>__('setting_tab_basic'),'header'=>__('setting_tab_header'),'footer'=>__('setting_tab_footer'),'code'=>__('setting_tab_code')][$tab] ?? __('setting_tab_basic'); ?></h2>
            <button type="button" onclick="restoreAllDefaults()" class="text-xs text-gray-400 hover:text-red-500 transition inline-flex items-center gap-1" title="<?php echo __('setting_restore_all_tip'); ?>">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                <?php echo __('setting_restore_defaults'); ?>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <?php foreach ($items as $item): ?>

            <?php if ($item['type'] === 'footer_columns'): ?>
            <!-- 页脚栏目编辑器 -->
            <?php $columnsData = json_decode($item['value'], true) ?: []; ?>
            <div>
                <label class="text-gray-700 font-medium block mb-1">
                    <?php $__lbl = __('setting_' . $item['key']); echo e($__lbl !== 'setting_' . $item['key'] ? $__lbl : $item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php $__tip = __('setting_' . $item['key'] . '_tip'); echo e($__tip !== 'setting_' . $item['key'] . '_tip' ? $__tip : $item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="settings[footer_columns]" id="footerColumnsJson">
                <div class="text-xs text-gray-400 mb-2 mt-1">
                    <?php echo __('setting_footer_placeholder_hint'); ?>
                </div>
                <div id="footerColumnsEditor" class="space-y-3">
                    <?php for ($ci = 0; $ci < 4; $ci++): ?>
                    <?php $col = $columnsData[$ci] ?? null; ?>
                    <div class="fcol-row p-3 border rounded-lg <?php echo $col ? 'bg-white' : 'bg-gray-50'; ?>" data-index="<?php echo $ci; ?>">
                        <div class="flex gap-3 items-start">
                            <span class="text-gray-300 text-sm pt-2 w-5 flex-shrink-0"><?php echo $ci + 1; ?></span>
                            <div class="flex-1">
                                <label class="text-xs text-gray-400 block mb-1"><?php echo __('setting_col_title'); ?></label>
                                <input type="text" class="fcol-title w-full border rounded px-3 py-1.5 text-sm" placeholder="<?php echo __('setting_col_title'); ?>" value="<?php echo e($col['title'] ?? ''); ?>">
                            </div>
                            <div class="w-24 flex-shrink-0">
                                <label class="text-xs text-gray-400 block mb-1"><?php echo __('setting_col_span'); ?></label>
                                <select class="fcol-span w-full border rounded px-2 py-1.5 text-sm">
                                    <option value="1" <?php echo ($col['col_span'] ?? 1) == 1 ? 'selected' : ''; ?>>1<?php echo __('setting_col_unit'); ?></option>
                                    <option value="2" <?php echo ($col['col_span'] ?? 1) == 2 ? 'selected' : ''; ?>>2<?php echo __('setting_col_unit'); ?></option>
                                </select>
                            </div>
                            <button type="button" class="fcol-clear text-gray-300 hover:text-red-400 pt-5 flex-shrink-0" title="<?php echo __('setting_clear_row'); ?>">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                        <div class="mt-2 ml-8">
                            <label class="text-xs text-gray-400 block mb-1"><?php echo __('setting_col_content'); ?></label>
                            <textarea class="fcol-content w-full border rounded px-3 py-1.5 text-sm" rows="3" placeholder="<?php echo __('setting_footer_content_placeholder'); ?>"><?php echo e($col['content'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <?php elseif ($item['type'] === 'footer_nav'): ?>
            <!-- 页脚导航编辑器 -->
            <?php $navData = json_decode($item['value'], true) ?: []; ?>
            <div>
                <label class="text-gray-700 font-medium block mb-1">
                    <?php $__lbl = __('setting_' . $item['key']); echo e($__lbl !== 'setting_' . $item['key'] ? $__lbl : $item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php $__tip = __('setting_' . $item['key'] . '_tip'); echo e($__tip !== 'setting_' . $item['key'] . '_tip' ? $__tip : $item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="settings[footer_nav]" id="footerNavJson">
                <div id="footerNavEditor" class="space-y-3"></div>
                <button type="button" onclick="addFooterNavGroup()" class="mt-3 text-sm text-primary hover:text-secondary cursor-pointer inline-flex items-center gap-1">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    <?php echo __('setting_add_group'); ?>
                </button>
            </div>
            <script>
            var _footerNavData = <?php echo json_encode($navData, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            </script>

            <?php else: ?>
            <!-- 普通设置项 -->
            <?php
            $defaultItem = $groupDefaults[$item['key']] ?? null;
            $defaultValue = $defaultItem['value'] ?? '';
            $isModified = $defaultItem !== null && (string)$item['value'] !== (string)$defaultValue;
            ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php $__lbl = __('setting_' . $item['key']); echo e($__lbl !== 'setting_' . $item['key'] ? $__lbl : $item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm block"><?php $__tip = __('setting_' . $item['key'] . '_tip'); echo e($__tip !== 'setting_' . $item['key'] . '_tip' ? $__tip : $item['tip']); ?></span>
                    <?php endif; ?>
                    <?php if ($isModified && $defaultValue !== ''): ?>
                    <span class="text-gray-300 text-xs block mt-1 truncate" title="<?php echo e($defaultValue); ?>"><?php echo __('setting_default'); ?>: <?php echo e(mb_strimwidth($defaultValue, 0, 30, '...')); ?></span>
                    <?php endif; ?>
                </label>
                <div class="md:col-span-3">
                    <?php if ($item['type'] === 'textarea'): ?>
                    <textarea name="settings[<?php echo e($item['key']); ?>]" rows="3"
                              class="w-full border rounded px-4 py-2"><?php echo e($item['value']); ?></textarea>

                    <?php elseif ($item['type'] === 'image'): ?>
                    <div class="flex gap-2 items-center">
                        <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                               value="<?php echo e($item['value']); ?>"
                               id="input_<?php echo e($item['key']); ?>"
                               class="flex-1 border rounded px-4 py-2">
                        <button type="button" onclick="uploadImage('<?php echo e($item['key']); ?>')"
                                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                            <?php echo __('btn_upload'); ?>
                        </button>
                        <button type="button" onclick="pickFromMedia('<?php echo e($item['key']); ?>')"
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            <?php echo __("admin_media_library"); ?>
                        </button>
                    </div>
                    <?php if ($item['value']): ?>
                    <img src="<?php echo e($item['value']); ?>" class="h-16 mt-2 rounded" id="preview_<?php echo e($item['key']); ?>">
                    <?php endif; ?>

                    <?php elseif ($item['type'] === 'select'): ?>
                    <select name="settings[<?php echo e($item['key']); ?>]" class="w-full border rounded px-4 py-2">
                        <?php
                        $options = json_decode($item['options'] ?? '{}', true) ?: [];
                        if (empty($options)) {
                            $defaultOptions = [
                                'show_price' => ['0' => __('setting_hide'), '1' => __('setting_show')],
                            ];
                            $options = $defaultOptions[$item['key']] ?? ['0' => __('setting_no'), '1' => __('setting_yes')];
                        }
                        foreach ($options as $optKey => $optLabel):
                        ?>
                        <option value="<?php echo e((string)$optKey); ?>" <?php echo $item['value'] === (string)$optKey ? 'selected' : ''; ?>>
                            <?php echo e($optLabel); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <?php elseif ($item['type'] === 'color'): ?>
                    <?php if ($item['key'] === 'primary_color'): ?>
                    <!-- 预设配色方案 -->
                    <div class="mb-3" id="colorPresets">
                        <div class="text-xs text-gray-400 mb-2"><?php echo __('setting_color_presets'); ?></div>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $presets = [
                                ['name' => 'クラシックブルー', 'primary' => '#3B82F6', 'secondary' => '#1D4ED8'],
                                ['name' => 'エメラルド',     'primary' => '#10B981', 'secondary' => '#059669'],
                                ['name' => 'レッド',         'primary' => '#EF4444', 'secondary' => '#DC2626'],
                                ['name' => 'オレンジ',       'primary' => '#F97316', 'secondary' => '#EA580C'],
                                ['name' => 'パープル',       'primary' => '#8B5CF6', 'secondary' => '#7C3AED'],
                                ['name' => 'シアン',         'primary' => '#06B6D4', 'secondary' => '#0891B2'],
                                ['name' => 'ローズ',         'primary' => '#F43F5E', 'secondary' => '#E11D48'],
                                ['name' => 'アンバー',       'primary' => '#F59E0B', 'secondary' => '#D97706'],
                            ];
                            $currentPrimary = config('primary_color', '#3B82F6');
                            $currentSecondary = config('secondary_color', '#1D4ED8');
                            foreach ($presets as $preset):
                                $isActive = strtolower($currentPrimary) === strtolower($preset['primary']);
                            ?>
                            <button type="button"
                                    class="color-preset flex items-center gap-1.5 px-3 py-1.5 border rounded-full text-xs transition <?php echo $isActive ? 'border-gray-800 bg-gray-50 font-medium' : 'border-gray-200 hover:border-gray-400'; ?>"
                                    data-primary="<?php echo $preset['primary']; ?>"
                                    data-secondary="<?php echo $preset['secondary']; ?>">
                                <span class="w-3 h-3 rounded-full flex-shrink-0" style="background: <?php echo $preset['primary']; ?>"></span>
                                <span class="w-3 h-3 rounded-full flex-shrink-0 -ml-2 border border-white" style="background: <?php echo $preset['secondary']; ?>"></span>
                                <?php echo $preset['name']; ?>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="flex gap-2 items-center">
                        <input type="color"
                               value="<?php echo e($item['value'] ?: '#000000'); ?>"
                               class="w-10 h-10 p-1 border rounded cursor-pointer"
                               onchange="this.nextElementSibling.value = this.value">
                        <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                               value="<?php echo e($item['value']); ?>"
                               class="flex-1 border rounded px-4 py-2 font-mono"
                               id="input_<?php echo e($item['key']); ?>"
                               pattern="#[0-9a-fA-F]{6}"
                               placeholder="#000000"
                               onchange="this.previousElementSibling.value = this.value">
                    </div>

                    <?php elseif ($item['type'] === 'code'): ?>
                    <textarea name="settings[<?php echo e($item['key']); ?>]" rows="8"
                              class="w-full border rounded px-4 py-2 font-mono text-sm bg-gray-50"
                              spellcheck="false"
                              placeholder="<?php $__tip = __('setting_' . $item['key'] . '_tip'); echo e($__tip !== 'setting_' . $item['key'] . '_tip' ? $__tip : $item['tip']); ?>"><?php echo e($item['value']); ?></textarea>

                    <?php elseif ($item['type'] === 'number'): ?>
                    <input type="number" name="settings[<?php echo e($item['key']); ?>]"
                           value="<?php echo e($item['value']); ?>"
                           class="w-full border rounded px-4 py-2">

                    <?php else: ?>
                    <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                           value="<?php echo e($item['value']); ?>"
                           class="w-full border rounded px-4 py-2">
                    <?php endif; ?>
                    <?php if ($isModified): ?>
                    <button type="button" class="restore-btn text-xs text-gray-400 hover:text-primary mt-1 inline-flex items-center gap-1 transition"
                            data-key="<?php echo e($item['key']); ?>"
                            data-default="<?php echo e($defaultValue); ?>"
                            title="<?php echo __('setting_restore_to_default'); ?>: <?php echo e(mb_strimwidth($defaultValue, 0, 50, '...')); ?>">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        <?php echo __('setting_restore_default'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 text-center">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <?php echo __('btn_save_settings'); ?>
        </button>
    </div>
</form>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script>
let currentImageKey = '';

function uploadImage(key) {
    currentImageKey = key;
    document.getElementById('imageFileInput').click();
}

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const text = await response.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('上传响应:', text);
            showMessage('<?php echo __('setting_upload_error'); ?>', 'error');
            return;
        }

        if (data.code === 0) {
            document.getElementById('input_' + currentImageKey).value = data.data.url;
            let preview = document.getElementById('preview_' + currentImageKey);
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'preview_' + currentImageKey;
                preview.className = 'h-16 mt-2 rounded';
                document.getElementById('input_' + currentImageKey).parentNode.parentNode.appendChild(preview);
            }
            preview.src = data.data.url;
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg || '<?php echo __('setting_upload_failed'); ?>', 'error');
        }
    } catch (err) {
        console.error('上传错误:', err);
        showMessage('<?php echo __('setting_upload_failed'); ?>: ' + err.message, 'error');
    }

    this.value = '';
});

function pickFromMedia(key) {
    openMediaPicker(function(url) {
        document.getElementById('input_' + key).value = url;
        var preview = document.getElementById('preview_' + key);
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'preview_' + key;
            preview.className = 'h-16 mt-2 rounded';
            document.getElementById('input_' + key).parentNode.parentNode.appendChild(preview);
        }
        preview.src = url;
    });
}

// 预设配色方案点击
document.querySelectorAll('.color-preset').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var primary = this.dataset.primary;
        var secondary = this.dataset.secondary;
        // 更新主题色
        var pInput = document.getElementById('input_primary_color');
        if (pInput) { pInput.value = primary; pInput.previousElementSibling.value = primary; }
        // 更新次要色
        var sInput = document.getElementById('input_secondary_color');
        if (sInput) { sInput.value = secondary; sInput.previousElementSibling.value = secondary; }
        // 更新按钮高亮
        document.querySelectorAll('.color-preset').forEach(function(b) {
            b.classList.remove('border-gray-800', 'bg-gray-50', 'font-medium');
            b.classList.add('border-gray-200');
        });
        this.classList.remove('border-gray-200');
        this.classList.add('border-gray-800', 'bg-gray-50', 'font-medium');
    });
});

// 页脚栏目编辑器 - 收集JSON
function collectFooterColumns() {
    var editor = document.getElementById('footerColumnsEditor');
    if (!editor) return;
    var rows = editor.querySelectorAll('.fcol-row');
    var cols = [];
    rows.forEach(function(row) {
        var title = row.querySelector('.fcol-title').value.trim();
        var content = row.querySelector('.fcol-content').value.trim();
        var colSpan = parseInt(row.querySelector('.fcol-span').value) || 1;
        if (title || content) {
            cols.push({ title: title, content: content, col_span: colSpan });
        }
    });
    document.getElementById('footerColumnsJson').value = JSON.stringify(cols);
}

// 清空按钮
document.querySelectorAll('.fcol-clear').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var row = this.closest('.fcol-row');
        row.querySelector('.fcol-title').value = '';
        row.querySelector('.fcol-content').value = '';
        row.querySelector('.fcol-span').value = '1';
    });
});

// 单项恢复默认值
document.querySelectorAll('.restore-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key = this.dataset.key;
        var defaultVal = this.dataset.default;
        var input = document.querySelector('[name="settings[' + key + ']"]');
        if (input) {
            if (input.tagName === 'SELECT') {
                input.value = defaultVal;
            } else if (input.tagName === 'TEXTAREA') {
                input.value = defaultVal;
            } else {
                input.value = defaultVal;
            }
            // 同步颜色选择器
            var colorPicker = input.previousElementSibling;
            if (colorPicker && colorPicker.type === 'color') {
                colorPicker.value = defaultVal || '#000000';
            }
        }
        this.remove();
        showMessage('<?php echo __('setting_restored_save'); ?>');
    });
});

// 恢复全部默认值
async function restoreAllDefaults() {
    if (!confirm('<?php echo __('setting_restore_all_confirm'); ?>')) return;
    const formData = new FormData();
    formData.append('action', 'restore_defaults');
    formData.append('group', '<?php echo e($group); ?>');
    const response = await fetch(location.href, { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('setting_restored'); ?>');
        setTimeout(function() { location.reload(); }, 800);
    } else {
        showMessage(data.msg || '<?php echo __('setting_restore_failed'); ?>', 'error');
    }
}

document.getElementById('settingForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    collectFooterColumns();
    collectFooterNav();

    const formData = new FormData(this);

    try {
        const response = await fetch(location.href, { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_request_failed'); ?>', 'error');
    }
});

// ========== 页脚导航编辑器 ==========
function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function renderFooterNav(data) {
    var editor = document.getElementById('footerNavEditor');
    if (!editor) return;
    editor.innerHTML = '';
    (data || []).forEach(function(group, gi) {
        editor.insertAdjacentHTML('beforeend', renderNavGroup(group, gi));
    });
}

function renderNavGroup(group, gi) {
    var linksHtml = '';
    (group.links || []).forEach(function(link, li) {
        linksHtml += renderNavLink(link, gi, li);
    });
    return '<div class="fnav-group border rounded-lg p-3 bg-white" data-gi="' + gi + '">' +
        '<div class="flex items-center gap-3 mb-2">' +
            '<input type="text" class="fnav-title flex-1 border rounded px-3 py-1.5 text-sm" placeholder="<?php echo __('setting_nav_group_placeholder'); ?>" value="' + escHtml(group.title) + '">' +
            '<button type="button" onclick="removeFooterNavGroup(' + gi + ')" class="text-gray-300 hover:text-red-400 cursor-pointer" title="<?php echo __('setting_delete_group'); ?>">' +
                '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' +
            '</button>' +
        '</div>' +
        '<div class="fnav-links space-y-2 ml-6">' + linksHtml + '</div>' +
        '<button type="button" onclick="addFooterNavLink(' + gi + ')" class="ml-6 mt-2 text-xs text-primary hover:text-secondary cursor-pointer inline-flex items-center gap-1">' +
            '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg> <?php echo __('setting_add_link'); ?>' +
        '</button>' +
    '</div>';
}

function renderNavLink(link, gi, li) {
    var selSelf = (link.target || '_self') !== '_blank' ? ' selected' : '';
    var selBlank = (link.target || '_self') === '_blank' ? ' selected' : '';
    return '<div class="fnav-link flex items-center gap-2">' +
        '<input type="text" class="fnav-name border rounded px-2 py-1 text-sm w-28" placeholder="<?php echo __('setting_link_name'); ?>" value="' + escHtml(link.name) + '">' +
        '<input type="text" class="fnav-url flex-1 border rounded px-2 py-1 text-sm" placeholder="<?php echo __('setting_link_url_placeholder'); ?>" value="' + escHtml(link.url) + '">' +
        '<select class="fnav-target border rounded px-2 py-1 text-sm w-24">' +
            '<option value="_self"' + selSelf + '><?php echo __('setting_target_self'); ?></option>' +
            '<option value="_blank"' + selBlank + '><?php echo __('setting_target_blank'); ?></option>' +
        '</select>' +
        '<button type="button" onclick="this.closest(\'.fnav-link\').remove()" class="text-gray-300 hover:text-red-400 cursor-pointer">' +
            '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' +
        '</button>' +
    '</div>';
}

function addFooterNavGroup() {
    var editor = document.getElementById('footerNavEditor');
    if (!editor) return;
    var gi = editor.querySelectorAll('.fnav-group').length;
    editor.insertAdjacentHTML('beforeend', renderNavGroup({title: '', links: [{name: '', url: '', target: '_self'}]}, gi));
}

function removeFooterNavGroup(gi) {
    var groups = document.querySelectorAll('#footerNavEditor .fnav-group');
    if (groups[gi]) groups[gi].remove();
}

function addFooterNavLink(gi) {
    var groups = document.querySelectorAll('#footerNavEditor .fnav-group');
    if (!groups[gi]) return;
    var container = groups[gi].querySelector('.fnav-links');
    container.insertAdjacentHTML('beforeend', renderNavLink({name: '', url: '', target: '_self'}, gi, container.children.length));
}

function collectFooterNav() {
    var editor = document.getElementById('footerNavEditor');
    if (!editor) return;
    var groups = [];
    editor.querySelectorAll('.fnav-group').forEach(function(el) {
        var title = el.querySelector('.fnav-title').value.trim();
        var links = [];
        el.querySelectorAll('.fnav-link').forEach(function(lEl) {
            var name = lEl.querySelector('.fnav-name').value.trim();
            var url = lEl.querySelector('.fnav-url').value.trim();
            var target = lEl.querySelector('.fnav-target').value;
            if (name && url) links.push({name: name, url: url, target: target});
        });
        if (title || links.length > 0) groups.push({title: title, links: links});
    });
    document.getElementById('footerNavJson').value = JSON.stringify(groups);
}

// 初始化页脚导航编辑器
if (typeof _footerNavData !== 'undefined' && document.getElementById('footerNavEditor')) {
    renderFooterNav(_footerNavData);
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
