<?php
/**
 * Yikai CMS - 表单设计（CF7 风格模板编辑器）
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

        if (empty($name)) error('请输入表单名称');
        if (empty($slug)) error('请输入短码标识');
        if (!preg_match('/^[a-z0-9_-]+$/', $slug)) error('短码只能包含小写字母、数字、下划线和横线');
        if (!formTemplateModel()->isSlugUnique($slug, $id)) error('短码已被使用');

        // 验证模板中至少包含一个字段标签
        $tags = parseFormTags($templateText);
        if (empty($tags)) error('模板中至少需要一个字段标签（如 [text* name "姓名"]）');

        $data = [
            'name'            => $name,
            'slug'            => $slug,
            'fields'          => $templateText,
            'success_message' => $successMessage ?: '提交成功，感谢您的反馈！',
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
            error('默认联系表单不可删除');
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
    <label>姓名 <span class="text-red-500">*</span></label>
    [text* name "请输入您的姓名"]
</div>
<div>
    <label>电话</label>
    [tel phone "请输入您的电话"]
</div>
<div>
    <label>邮箱 <span class="text-red-500">*</span></label>
    [email* email "请输入您的邮箱"]
</div>
<div>
    <label>公司</label>
    [text company "请输入您的公司名称"]
</div>
</div>

<div class="mt-4">
    <label>留言内容 <span class="text-red-500">*</span></label>
    [textarea* content "请输入留言内容"]
</div>

<div class="mt-4">
    [submit "提交"]
</div>';

$pageTitle = '表单设计';
$currentMenu = 'form';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/form.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">表单数据</a>
        <a href="/admin/form_design.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">表单设计</a>
    </div>
</div>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex items-center justify-between">
        <p class="text-sm text-gray-500">使用类似 Contact Form 7 的标签语法设计表单，在页面内容中插入 <code class="bg-gray-100 px-1.5 py-0.5 rounded text-primary">[form-slug]</code> 即可嵌入</p>
        <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            添加表单
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">表单名称</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">短码</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">字段数</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">提交数</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
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
                        <code class="bg-gray-100 text-primary px-2 py-0.5 rounded text-sm cursor-pointer" onclick="copyShortcode(this)" title="点击复制">[form-<?php echo e($item['slug']); ?>]</code>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-600"><?php echo $item['_tag_count']; ?></td>
                    <td class="px-4 py-3 text-center">
                        <a href="/admin/form.php?type=<?php echo urlencode($item['slug']); ?>" class="text-primary hover:underline"><?php echo $submitCount; ?></a>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded cursor-pointer <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-red-100 text-red-600'; ?>">
                            <?php echo $item['status'] ? '启用' : '禁用'; ?>
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
                                class="text-primary hover:underline text-sm mr-2">编辑</button>
                        <?php if ($item['slug'] !== 'contact'): ?>
                        <button onclick="deleteTemplate(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm">删除</button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($templates)): ?>
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">暂无表单模板</td>
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
            <h3 class="font-bold text-gray-800" id="modalTitle">添加表单</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-5">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <!-- 基本信息 -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1 text-sm">表单名称 <span class="text-red-500">*</span></label>
                    <input type="text" name="name" id="editName" required class="w-full border rounded px-3 py-2 text-sm" placeholder="如：联系表单">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm">短码标识 <span class="text-red-500">*</span></label>
                    <div class="flex">
                        <span class="inline-flex items-center px-2 bg-gray-100 border border-r-0 rounded-l text-gray-500 text-xs">[form-</span>
                        <input type="text" name="slug" id="editSlug" required class="flex-1 border px-2 py-2 text-sm min-w-0" placeholder="contact" pattern="[a-z0-9_-]+">
                        <span class="inline-flex items-center px-2 bg-gray-100 border border-l-0 rounded-r text-gray-500 text-xs">]</span>
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1 text-sm">成功提示</label>
                    <input type="text" name="success_message" id="editSuccessMsg" class="w-full border rounded px-3 py-2 text-sm" placeholder="提交成功，感谢您的反馈！">
                </div>
            </div>

            <!-- 标签生成器工具栏 -->
            <div>
                <label class="block text-gray-700 mb-2 text-sm font-medium">表单模板</label>
                <div class="flex flex-wrap gap-1 mb-2 p-2 bg-gray-50 rounded-t border border-b-0">
                    <span class="text-xs text-gray-500 leading-7 mr-1">插入标签：</span>
                    <button type="button" onclick="openTagGen('text')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition">文本</button>
                    <button type="button" onclick="openTagGen('email')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition">邮箱</button>
                    <button type="button" onclick="openTagGen('tel')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition">电话</button>
                    <button type="button" onclick="openTagGen('textarea')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition">多行文本</button>
                    <button type="button" onclick="openTagGen('number')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition">数字</button>
                    <button type="button" onclick="openTagGen('date')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-blue-50 hover:border-blue-300 transition">日期</button>
                    <button type="button" onclick="openTagGen('select')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-green-50 hover:border-green-300 transition">下拉选择</button>
                    <button type="button" onclick="openTagGen('radio')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-green-50 hover:border-green-300 transition">单选</button>
                    <button type="button" onclick="openTagGen('checkbox')" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-green-50 hover:border-green-300 transition">复选</button>
                    <button type="button" onclick="insertSubmit()" class="px-2.5 py-1 text-xs bg-white border rounded hover:bg-orange-50 hover:border-orange-300 transition">提交按钮</button>
                </div>
                <!-- 模板编辑区 -->
                <textarea name="template_text" id="templateEditor"
                    class="w-full border rounded-b px-4 py-3 font-mono text-sm leading-relaxed focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary"
                    rows="18" placeholder="在此输入表单模板，使用标签定义字段..."></textarea>
                <p class="text-xs text-gray-400 mt-1">标签语法：<code>[类型* 字段名 "占位文字"]</code> — 类型后加 <code>*</code> 表示必填。支持 HTML 标签控制布局。</p>
            </div>

            <div class="flex justify-end gap-2 pt-4 border-t">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100 text-sm">取消</button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    保存
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
            <h4 class="font-medium text-gray-800 text-sm" id="tagGenTitle">生成标签</h4>
            <button onclick="closeTagGen()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <div class="p-4 space-y-3">
            <div>
                <label class="block text-gray-600 mb-1 text-xs">字段名（英文）<span class="text-red-500">*</span></label>
                <input type="text" id="tagName" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="如：your-name" pattern="[a-zA-Z0-9_-]+">
            </div>
            <div>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="checkbox" id="tagRequired"> 必填字段
                </label>
            </div>
            <div id="tagPhRow">
                <label class="block text-gray-600 mb-1 text-xs">占位文字</label>
                <input type="text" id="tagPlaceholder" class="w-full border rounded px-3 py-1.5 text-sm" placeholder="如：请输入您的姓名">
            </div>
            <div id="tagOptionsRow" class="hidden">
                <label class="block text-gray-600 mb-1 text-xs" id="tagOptionsLabel">选项（每行一个，第一行为空白提示）</label>
                <textarea id="tagOptions" class="w-full border rounded px-3 py-1.5 text-sm" rows="4" placeholder="请选择&#10;选项1&#10;选项2&#10;选项3"></textarea>
            </div>
            <!-- 预览 -->
            <div class="bg-gray-50 rounded p-2">
                <label class="block text-gray-500 mb-1 text-xs">预览</label>
                <code class="text-sm text-primary break-all" id="tagPreview"></code>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeTagGen()" class="border px-3 py-1.5 rounded text-sm hover:bg-gray-100">取消</button>
                <button type="button" onclick="insertTag()" class="bg-primary text-white px-4 py-1.5 rounded text-sm hover:bg-secondary">插入标签</button>
            </div>
        </div>
    </div>
</div>

<script>
var currentTagType = 'text';
var defaultTemplate = <?php echo json_encode($defaultTemplate, JSON_HEX_TAG | JSON_HEX_AMP); ?>;

var tagTypeNames = {
    'text': '文本', 'email': '邮箱', 'tel': '电话',
    'textarea': '多行文本', 'number': '数字', 'date': '日期',
    'select': '下拉选择', 'radio': '单选', 'checkbox': '复选'
};

function openEditModal(item) {
    var isEdit = !!item;
    document.getElementById('modalTitle').textContent = isEdit ? '编辑表单' : '添加表单';
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
    document.getElementById('tagGenTitle').textContent = '生成' + (tagTypeNames[type] || type) + '标签';
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
            optLabel.textContent = '选项（每行一个，第一行为空白提示）';
            optArea.placeholder = '请选择\n选项1\n选项2\n选项3';
        } else {
            optLabel.textContent = '选项（每行一个）';
            optArea.placeholder = '选项1\n选项2\n选项3';
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
    document.getElementById('tagPreview').textContent = buildTagString() || '[类型 字段名 ...]';
}

// 监听输入更新预览
document.getElementById('tagName').addEventListener('input', updateTagPreview);
document.getElementById('tagRequired').addEventListener('change', updateTagPreview);
document.getElementById('tagPlaceholder').addEventListener('input', updateTagPreview);
document.getElementById('tagOptions').addEventListener('input', updateTagPreview);

function insertTag() {
    var tagStr = buildTagString();
    if (!tagStr) {
        showMessage('请输入字段名', 'error');
        return;
    }
    insertAtCursor(tagStr);
    closeTagGen();
}

function insertSubmit() {
    insertAtCursor('[submit "提交"]');
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
            showMessage('保存成功');
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('请求失败', 'error');
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
            btn.textContent = '启用';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded cursor-pointer bg-red-100 text-red-600';
            btn.textContent = '禁用';
        }
    }
}

async function deleteTemplate(id) {
    if (!confirm('确定要删除该表单模板吗？')) return;
    var formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    try {
        var response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        var data = await safeJson(response);
        if (data.code === 0) {
            showMessage('删除成功');
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch(err) {
        showMessage('请求失败', 'error');
    }
}

function copyShortcode(el) {
    navigator.clipboard.writeText(el.textContent).then(function() { showMessage('已复制短码'); });
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
