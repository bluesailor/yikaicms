<?php
/**
 * ikaiCMS - 表单设计（CF7 风格模板编辑器）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('form');

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $name = trim(post('name'));
        $slug = trim(post('slug'));
        $successMessage = trim(post('success_message'));
        $templateText = post('template_text');

        if (empty($name)) error(__('fd_err_name_required'));
        if (empty($slug)) error(__('fd_err_slug_required'));
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) error(__('fd_err_slug_pattern'));
        if (!formTemplateModel()->isSlugUnique($slug, $id)) error(__('fd_err_slug_taken'));

        // 验证模板中至少包含一个字段标签
        $tags = parseFormTags($templateText);
        if (empty($tags)) error(__('fd_err_template_empty'));

        $data = [
            'name'            => $name,
            'slug'            => $slug,
            'fields'          => $templateText,
            'success_message' => $successMessage ?: __('fd_default_success_msg'),
        ];

        if ($id > 0) {
            formTemplateModel()->updateById($id, $data);
            adminLog('form_template', 'update', "更新表单模板ID: $id");
        } else {
            $data['status'] = 1;
            $data['created_at'] = time();
            $id = formTemplateModel()->create($data);
            adminLog('form_template', 'create', "创建表单模板ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        $tpl = formTemplateModel()->findById($id);
        if ($tpl && $tpl['slug'] === 'contact') {
            error(__('fd_err_default_undelete'));
        }
        formTemplateModel()->deleteById($id);
        adminLog('form_template', 'delete', "删除表单模板ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = formTemplateModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    exit;
}

$templates = formTemplateModel()->all('id ASC');

// 为编辑时准备模板数据（旧JSON自动转换）
foreach ($templates as &$tpl) {
    $fieldsRaw = $tpl['fields'] ?? '';
    if (isJsonFields($fieldsRaw)) {
        $jsonFields = json_decode($fieldsRaw, true);
        $tpl['_template_text'] = jsonFieldsToTemplate($jsonFields);
        $tpl['_tag_count'] = count($jsonFields);
    } else {
        $tpl['_template_text'] = $fieldsRaw;
        $tpl['_tag_count'] = count(parseFormTags($fieldsRaw));
    }
}
unset($tpl);

$defaultTemplate = '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
<div>
    <label>' . __('fd_field_name') . ' <span class="text-red-500">*</span></label>
    [text* name "' . __('fd_ph_name') . '"]
</div>
<div>
    <label>' . __('fd_field_phone') . '</label>
    [tel phone "' . __('fd_ph_phone') . '"]
</div>
<div>
    <label>' . __('fd_field_email') . ' <span class="text-red-500">*</span></label>
    [email* email "' . __('fd_ph_email') . '"]
</div>
<div>
    <label>' . __('fd_field_company') . '</label>
    [text company "' . __('fd_ph_company') . '"]
</div>
</div>

<div class="mt-4">
    <label>' . __('fd_field_message') . ' <span class="text-red-500">*</span></label>
    [textarea* content "' . __('fd_ph_message') . '"]
</div>

<div class="mt-4">
    [submit "' . __('form_submit') . '"]
</div>';

$pageTitle = __('fd_page_title');
$currentMenu = 'form';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/form.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('fd_tab_data'); ?></a>
        <a href="/admin/form_design.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('fd_tab_design'); ?></a>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex items-center justify-between">
        <p class="text-sm text-gray-500"><?php echo __('fd_intro'); ?></p>
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            <?php echo __('fd_btn_add'); ?>
        </button>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">ID</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('fd_th_name'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('fd_th_slug'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('fd_th_field_count'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('fd_th_submit_count'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($templates as $item):
                    $submitCount = formTemplateModel()->getSubmitCount($item['slug']);
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 text-gray-500"><?php echo $item['id']; ?></td>
                    <td class="px-4 py-3 font-medium"><?php echo e($item['name']); ?></td>
                    <td class="px-4 py-3">
                        <code class="bg-gray-100 text-primary px-2 py-0.5 rounded text-sm cursor-pointer" onclick="copyShortcode(this)" title="<?php echo __('fd_copy_hint'); ?>">[form-<?php echo e($item['slug']); ?>]</code>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-600"><?php echo $item['_tag_count']; ?></td>
                    <td class="px-4 py-3 text-center">
                        <a href="/admin/form.php?type=<?php echo urlencode($item['slug']); ?>" class="text-primary hover:underline"><?php echo $submitCount; ?></a>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                            <?php echo $item['status'] ? __('admin_enabled') : __('admin_disabled'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode([
                            'id'              => $item['id'],
                            'name'            => $item['name'],
                            'slug'            => $item['slug'],
                            'success_message' => $item['success_message'] ?? '',
                            'template_text'   => $item['_template_text'],
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2"><?php echo __('admin_edit'); ?></button>
                        <?php if ($item['slug'] !== 'contact'): ?>
                        <button onclick="deleteTemplate(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm"><?php echo __('admin_delete'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($templates)): ?>
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500"><?php echo __('fd_empty'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden overflow-y-auto">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="relative max-w-4xl mx-auto my-10 bg-white rounded-lg shadow-xl">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white rounded-t-lg z-10">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo __('fd_modal_add'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <!-- 基本信息 -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('fd_label_name'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="editName" required class="w-full border rounded px-3 py-2 text-sm" placeholder="<?php echo __('fd_ph_form_name'); ?>">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('fd_label_slug'); ?> <span class="text-red-500">*</span></label>
                    <div class="flex">
                        <span class="inline-flex items-center px-2 bg-gray-100 border border-r-0 rounded-l text-gray-500 text-xs">[form-</span>
                        <input type="text" name="slug" id="editSlug" required class="flex-1 border px-2 py-2 text-sm min-w-0" placeholder="contact" pattern="[a-z0-9_-]+">
                        <span class="inline-flex items-center px-2 bg-gray-100 border border-l-0 rounded-r text-gray-500 text-xs">]</span>
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm"><?php echo __('fd_label_success_msg'); ?></label>
                    <input type="text" name="success_message" id="editSuccessMsg" class="w-full border rounded px-3 py-2 text-sm" placeholder="<?php echo __('fd_default_success_msg'); ?>">
                </div>
            </div>

            <!-- 标签生成器工具栏 -->
            <div>
                <label class="block text-gray-700 mb-2 text-sm font-medium"><?php echo __('fd_label_template'); ?></label>
                <div class="flex flex-wrap gap-1 mb-2 p-2 bg-gray-50 rounded-t border border-b-0">
                    <span class="text-xs text-gray-500 leading-7 mr-1"><?php echo __('fd_insert_tag'); ?></span>
                    <button type="button" onclick="openTagGen('text')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition"><?php echo __('fd_tag_text'); ?></button>
                    <button type="button" onclick="openTagGen('email')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition"><?php echo __('fd_tag_email'); ?></button>
                    <button type="button" onclick="openTagGen('tel')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition"><?php echo __('fd_tag_tel'); ?></button>
                    <button type="button" onclick="openTagGen('textarea')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition"><?php echo __('fd_tag_textarea'); ?></button>
                    <button type="button" onclick="openTagGen('number')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition"><?php echo __('fd_tag_number'); ?></button>
                    <button type="button" onclick="openTagGen('date')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition"><?php echo __('fd_tag_date'); ?></button>
                    <button type="button" onclick="openTagGen('select')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-green-50 hover:border-green-300 transition"><?php echo __('fd_tag_select'); ?></button>
                    <button type="button" onclick="openTagGen('radio')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-green-50 hover:border-green-300 transition"><?php echo __('fd_tag_radio'); ?></button>
                    <button type="button" onclick="openTagGen('checkbox')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-green-50 hover:border-green-300 transition"><?php echo __('fd_tag_checkbox'); ?></button>
                    <button type="button" onclick="insertSubmit()" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-orange-50 hover:border-orange-300 transition"><?php echo __('fd_tag_submit'); ?></button>
                </div>
                <!-- 模板编辑区 -->
                <textarea name="template_text" id="templateEditor"
                    class="w-full border rounded-b px-4 py-3 font-mono text-sm leading-relaxed focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    rows="18" placeholder="<?php echo __('fd_editor_placeholder'); ?>"></textarea>
                <p class="text-xs text-gray-400 mt-1"><?php echo __('fd_syntax_hint'); ?></p>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100 text-sm"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <?php echo __("btn_save"); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 标签生成器弹窗 -->
<div id="tagGenModal" class="fixed inset-0 hidden" style="z-index: 60">
    <div class="absolute inset-0" onclick="closeTagGen()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-2xl border w-96">
        <div class="px-4 py-3 border-b flex justify-between items-center">
            <h4 class="font-medium text-gray-800 text-sm" id="tagGenTitle"><?php echo __('fd_taggen_title'); ?></h4>
            <button onclick="closeTagGen()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <div class="p-4 space-y-3">
            <div>
                <label class="block text-gray-600 mb-1 text-xs"><?php echo __('fd_taggen_field_name'); ?> <span class="text-red-500">*</span></label>
                <input type="text" id="tagName" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="your-name" pattern="[a-zA-Z0-9_-]+">
            </div>
            <div>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" id="tagRequired"> <?php echo __('fd_taggen_required'); ?>
                </label>
            </div>
            <div id="tagPhRow">
                <label class="block text-gray-600 mb-1 text-xs"><?php echo __('fd_taggen_placeholder'); ?></label>
                <input type="text" id="tagPlaceholder" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="<?php echo __('fd_ph_name'); ?>">
            </div>
            <div id="tagOptionsRow" class="hidden">
                <label class="block text-gray-600 mb-1 text-xs" id="tagOptionsLabel"><?php echo __('fd_taggen_options'); ?></label>
                <textarea id="tagOptions" class="w-full border rounded px-3 py-1.5 text-sm" rows="4" placeholder="<?php echo str_replace('\n', '&#10;', __('fd_taggen_options_ph_select')); ?>"></textarea>
            </div>
            <!-- 预览 -->
            <div class="bg-gray-50 rounded p-2">
                <label class="block text-gray-500 mb-1 text-xs"><?php echo __('btn_preview'); ?></label>
                <code class="text-sm text-primary break-all" id="tagPreview"></code>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeTagGen()" class="border px-3 py-1.5 rounded text-sm hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="button" onclick="insertTag()" class="bg-primary text-white px-4 py-1.5 rounded text-sm hover:bg-secondary"><?php echo __('fd_taggen_btn_insert'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
var currentTagType = 'text';
var defaultTemplate = <?php echo json_encode($defaultTemplate, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

var tagTypeNames = {
    'text': '<?php echo __("fd_tag_text"); ?>', 'email': '<?php echo __("fd_tag_email"); ?>', 'tel': '<?php echo __("fd_tag_tel"); ?>',
    'textarea': '<?php echo __("fd_tag_textarea"); ?>', 'number': '<?php echo __("fd_tag_number"); ?>', 'date': '<?php echo __("fd_tag_date"); ?>',
    'select': '<?php echo __("fd_tag_select"); ?>', 'radio': '<?php echo __("fd_tag_radio"); ?>', 'checkbox': '<?php echo __("fd_tag_checkbox"); ?>'
};

function openEditModal(item) {
    var isEdit = !!item;
    document.getElementById('modalTitle').textContent = isEdit ? '<?php echo __("fd_modal_edit"); ?>' : '<?php echo __("fd_modal_add"); ?>';
    document.getElementById('editId').value = item ? item.id : 0;
    document.getElementById('editName').value = item ? item.name : '';
    document.getElementById('editSlug').value = item ? item.slug : '';
    document.getElementById('editSuccessMsg').value = item ? (item.success_message || '') : '';
    document.getElementById('templateEditor').value = item ? item.template_text : defaultTemplate;
    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

// 标签生成器
function openTagGen(type) {
    currentTagType = type;
    document.getElementById('tagGenTitle').textContent = '<?php echo __("fd_taggen_title"); ?>: ' + (tagTypeNames[type] || type);
    document.getElementById('tagName').value = '';
    document.getElementById('tagRequired').checked = false;
    document.getElementById('tagPlaceholder').value = '';
    document.getElementById('tagOptions').value = '';

    var hasOptions = (type === 'select' || type === 'radio' || type === 'checkbox');
    document.getElementById('tagPhRow').classList.toggle('hidden', hasOptions);
    document.getElementById('tagOptionsRow').classList.toggle('hidden', !hasOptions);
    if (hasOptions) {
        var optLabel = document.getElementById('tagOptionsLabel');
        var optArea = document.getElementById('tagOptions');
        if (type === 'select') {
            optLabel.textContent = '<?php echo __("fd_taggen_options"); ?>';
            optArea.placeholder = '<?php echo str_replace("\n", "\\n", __("fd_taggen_options_ph_select")); ?>';
        } else {
            optLabel.textContent = '<?php echo __("fd_taggen_options_simple"); ?>';
            optArea.placeholder = '<?php echo str_replace("\n", "\\n", __("fd_taggen_options_ph_simple")); ?>';
        }
    }

    updateTagPreview();
    document.getElementById('tagGenModal').classList.remove('hidden');
    document.getElementById('tagName').focus();
}

function closeTagGen() {
    document.getElementById('tagGenModal').classList.add('hidden');
}

function buildTagString() {
    var type = currentTagType;
    var name = document.getElementById('tagName').value.trim();
    var required = document.getElementById('tagRequired').checked;
    if (!name) return '';

    var tag = '[' + type + (required ? '*' : '') + ' ' + name;

    if (type === 'select' || type === 'radio' || type === 'checkbox') {
        var lines = document.getElementById('tagOptions').value.split('\n');
        for (var i = 0; i < lines.length; i++) {
            var line = lines[i].trim();
            if (line) tag += ' "' + line + '"';
        }
    } else {
        var ph = document.getElementById('tagPlaceholder').value.trim();
        if (ph) tag += ' "' + ph + '"';
    }

    tag += ']';
    return tag;
}

function updateTagPreview() {
    document.getElementById('tagPreview').textContent = buildTagString() || '<?php echo __("fd_taggen_preview_empty"); ?>';
}

// 监听输入更新预览
document.getElementById('tagName').addEventListener('input', updateTagPreview);
document.getElementById('tagRequired').addEventListener('change', updateTagPreview);
document.getElementById('tagPlaceholder').addEventListener('input', updateTagPreview);
document.getElementById('tagOptions').addEventListener('input', updateTagPreview);

function insertTag() {
    var tagStr = buildTagString();
    if (!tagStr) {
        showMessage('<?php echo __("fd_taggen_err_name"); ?>', 'error');
        return;
    }
    insertAtCursor(tagStr);
    closeTagGen();
}

function insertSubmit() {
    insertAtCursor('[submit "' . __('form_submit') . '"]');
}

function insertAtCursor(text) {
    var editor = document.getElementById('templateEditor');
    var start = editor.selectionStart;
    var end = editor.selectionEnd;
    var before = editor.value.substring(0, start);
    var after = editor.value.substring(end);
    editor.value = before + text + after;
    editor.selectionStart = editor.selectionEnd = start + text.length;
    editor.focus();
}

// 保存表单
document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    var formData = new FormData(this);
    try {
        var response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        var data = await safeJson(response);
        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('<?php echo __("fd_request_failed"); ?>', 'error');
    }
});

async function toggleStatus(id, btn) {
    var formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    var response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
    var data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-green-100 text-green-600';
            btn.textContent = '<?php echo __('admin_enabled'); ?>';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-red-100 text-red-600';
            btn.textContent = '<?php echo __('admin_disabled'); ?>';
        }
    }
}

async function deleteTemplate(id) {
    if (!confirm('<?php echo __("fd_confirm_delete"); ?>')) return;
    var formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    try {
        var response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        var data = await safeJson(response);
        if (data.code === 0) {
            showMessage('<?php echo __('admin_deleted'); ?>');
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('<?php echo __("fd_request_failed"); ?>', 'error');
    }
}

function copyShortcode(el) {
    navigator.clipboard.writeText(el.textContent).then(function() { showMessage('<?php echo __("fd_copied"); ?>'); });
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
