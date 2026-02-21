<?php
/**
 * Yikai CMS - 站点设置
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
$groupDefaults = getDefaults($group);

$pageTitle = '站点设置';
$currentMenu = 'setting';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/setting.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'basic' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">基本设置</a>
        <a href="/admin/setting.php?tab=header" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'header' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">页头设置</a>
        <a href="/admin/setting.php?tab=footer" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'footer' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">页脚设置</a>
        <a href="/admin/setting.php?tab=code" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'code' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">代码注入</a>
    </div>
</div>

<form id="settingForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo ['basic'=>'基本设置','header'=>'页头设置','footer'=>'页脚设置','code'=>'代码注入'][$tab] ?? '设置'; ?></h2>
            <button type="button" onclick="restoreAllDefaults()" class="text-xs text-gray-400 hover:text-red-500 transition inline-flex items-center gap-1" title="恢复当前分组所有设置为出厂默认值">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                恢复默认值
            </button>
        </div>
        <div class="p-6 space-y-4">
            <?php foreach ($items as $item): ?>

            <?php if ($item['type'] === 'footer_columns'): ?>
            <!-- 页脚栏目编辑器 -->
            <?php $columnsData = json_decode($item['value'], true) ?: []; ?>
            <div>
                <label class="text-gray-700 font-medium block mb-1">
                    <?php echo e($item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm font-normal ml-2"><?php echo e($item['tip']); ?></span>
                    <?php endif; ?>
                </label>
                <input type="hidden" name="settings[footer_columns]" id="footerColumnsJson">
                <div class="text-xs text-gray-400 mb-2 mt-1">
                    内容支持占位符：<code class="bg-gray-100 px-1 rounded">{{site_description}}</code> 站点简介、
                    <code class="bg-gray-100 px-1 rounded">{{contact_info}}</code> 联系方式、
                    <code class="bg-gray-100 px-1 rounded">{{qrcode}}</code> 二维码，也可输入自定义文字
                </div>
                <div id="footerColumnsEditor" class="space-y-3">
                    <?php for ($ci = 0; $ci < 4; $ci++): ?>
                    <?php $col = $columnsData[$ci] ?? null; ?>
                    <div class="fcol-row p-3 border rounded-lg <?php echo $col ? 'bg-white' : 'bg-gray-50'; ?>" data-index="<?php echo $ci; ?>">
                        <div class="flex gap-3 items-start">
                            <span class="text-gray-300 text-sm pt-2 w-5 flex-shrink-0"><?php echo $ci + 1; ?></span>
                            <div class="flex-1">
                                <label class="text-xs text-gray-400 block mb-1">标题</label>
                                <input type="text" class="fcol-title w-full border rounded px-3 py-1.5 text-sm" placeholder="栏目标题" value="<?php echo e($col['title'] ?? ''); ?>">
                            </div>
                            <div class="w-24 flex-shrink-0">
                                <label class="text-xs text-gray-400 block mb-1">占列</label>
                                <select class="fcol-span w-full border rounded px-2 py-1.5 text-sm">
                                    <option value="1" <?php echo ($col['col_span'] ?? 1) == 1 ? 'selected' : ''; ?>>1列</option>
                                    <option value="2" <?php echo ($col['col_span'] ?? 1) == 2 ? 'selected' : ''; ?>>2列</option>
                                </select>
                            </div>
                            <button type="button" class="fcol-clear text-gray-300 hover:text-red-400 pt-5 flex-shrink-0" title="清空此行">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                        <div class="mt-2 ml-8">
                            <label class="text-xs text-gray-400 block mb-1">内容</label>
                            <textarea class="fcol-content w-full border rounded px-3 py-1.5 text-sm" rows="3" placeholder="文字内容或占位符如 {{contact_info}}"><?php echo e($col['content'] ?? ''); ?></textarea>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <?php else: ?>
            <!-- 普通设置项 -->
            <?php
            $defaultItem = $groupDefaults[$item['key']] ?? null;
            $defaultValue = $defaultItem['value'] ?? '';
            $isModified = $defaultItem !== null && (string)$item['value'] !== (string)$defaultValue;
            ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo e($item['name']); ?>
                    <?php if ($item['tip']): ?>
                    <span class="text-gray-400 text-sm block"><?php echo e($item['tip']); ?></span>
                    <?php endif; ?>
                    <?php if ($isModified && $defaultValue !== ''): ?>
                    <span class="text-gray-300 text-xs block mt-1 truncate" title="<?php echo e($defaultValue); ?>">默认: <?php echo e(mb_strimwidth($defaultValue, 0, 30, '...')); ?></span>
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
                            上传
                        </button>
                        <button type="button" onclick="pickFromMedia('<?php echo e($item['key']); ?>')"
                                class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                            媒体库
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
                                'show_price' => ['0' => '不显示', '1' => '显示'],
                            ];
                            $options = $defaultOptions[$item['key']] ?? ['0' => '否', '1' => '是'];
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
                        <div class="text-xs text-gray-400 mb-2">快速选择配色方案</div>
                        <div class="flex flex-wrap gap-2">
                            <?php
                            $presets = [
                                ['name' => '经典蓝',   'primary' => '#3B82F6', 'secondary' => '#1D4ED8'],
                                ['name' => '翡翠绿',   'primary' => '#10B981', 'secondary' => '#059669'],
                                ['name' => '中国红',   'primary' => '#EF4444', 'secondary' => '#DC2626'],
                                ['name' => '活力橙',   'primary' => '#F97316', 'secondary' => '#EA580C'],
                                ['name' => '优雅紫',   'primary' => '#8B5CF6', 'secondary' => '#7C3AED'],
                                ['name' => '青碧',     'primary' => '#06B6D4', 'secondary' => '#0891B2'],
                                ['name' => '玫瑰粉',   'primary' => '#F43F5E', 'secondary' => '#E11D48'],
                                ['name' => '琥珀金',   'primary' => '#F59E0B', 'secondary' => '#D97706'],
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
                              placeholder="<?php echo e($item['tip']); ?>"><?php echo e($item['value']); ?></textarea>

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
                            title="恢复为默认值: <?php echo e(mb_strimwidth($defaultValue, 0, 50, '...')); ?>">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        恢复默认
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            保存设置
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
            showMessage('上传失败：服务器返回异常', 'error');
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
            showMessage('上传成功');
        } else {
            showMessage(data.msg || '上传失败', 'error');
        }
    } catch (err) {
        console.error('上传错误:', err);
        showMessage('上传失败: ' + err.message, 'error');
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
        showMessage('已恢复默认值，请保存');
    });
});

// 恢复全部默认值
async function restoreAllDefaults() {
    if (!confirm('确定要恢复当前分组所有设置为出厂默认值吗？此操作不可撤销。')) return;
    const formData = new FormData();
    formData.append('action', 'restore_defaults');
    formData.append('group', '<?php echo e($group); ?>');
    const response = await fetch(location.href, { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('已恢复默认值');
        setTimeout(function() { location.reload(); }, 800);
    } else {
        showMessage(data.msg || '恢复失败', 'error');
    }
}

document.getElementById('settingForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    collectFooterColumns();

    const formData = new FormData(this);

    try {
        const response = await fetch(location.href, { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage('保存成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
