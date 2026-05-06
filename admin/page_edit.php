<?php
/**
 * ikaiCMS - 单页编辑（富文本模式）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('content');

$id = getInt('id');

if (!$id) {
    header('Location: /admin/page.php');
    exit;
}

// 获取页面数据
$page = channelModel()->findWhere(['id' => $id, 'type' => 'page']);

if (!$page) {
    header('Location: /admin/page.php');
    exit;
}

// 联系我们栏目跳转到联系设置页
if (($page['slug'] ?? '') === 'contact') {
    header('Location: /admin/setting_contact.php');
    exit;
}

if (($page['slug'] ?? '') === 'history') {
    header('Location: /admin/timeline.php');
    exit;
}

// 检查跳转设置
$redirectType = $page['redirect_type'] ?? 'auto';
$redirectTarget = null;

if ($redirectType === 'auto') {
    $children = channelModel()->getByParent($id, true);
    if (!empty($children)) {
        $redirectTarget = $children[0];
    }
} elseif ($redirectType === 'url' && !empty($page['redirect_url'])) {
    $redirectTarget = ['name' => $page['redirect_url'], '_is_url' => true];
}

// 从 contents 表获取该栏目的内容（与前端一致）
$contentRecord = contentModel()->queryOne(
    'SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? AND status = 1 ORDER BY is_top DESC, id DESC LIMIT 1',
    [$id]
);

$contentType = 'html';

if ($contentRecord) {
    $page['content'] = $contentRecord['content'];
    $contentType = $contentRecord['content_type'] ?? 'html';
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = resolveSlug(post('slug'), post('name'), 'channels', $id);

    $newContent = $_POST['content'] ?? '';

    $channelData = [
        'name' => post('name'),
        'slug' => $slug,
        'description' => post('description'),
        'content' => $newContent,
        'image' => post('image'),
        'seo_title' => post('seo_title'),
        'seo_keywords' => post('seo_keywords'),
        'seo_description' => post('seo_description'),
        'updated_at' => time(),
    ];

    channelModel()->updateById($id, $channelData);

    // 同步到 contents 表（向后兼容）
    if ($contentRecord) {
        contentModel()->updateById((int)$contentRecord['id'], [
            'content' => $newContent,
            'content_type' => 'html',
            'blocks_data' => null,
            'updated_at' => time(),
        ]);
    } else {
        contentModel()->create([
            'channel_id' => $id,
            'title' => post('name'),
            'content' => $newContent,
            'content_type' => 'html',
            'blocks_data' => null,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
    adminLog('page', 'edit', sprintf(__('pe_log_edit'), $channelData['name']));
    success();
}

$pageTitle = sprintf(__('pe_edit_page_title'), $page['name']);
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <a href="/admin/page.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
        <?php echo __('admin_back'); ?>
    </a>
    <a href="/admin/page_edit_advance.php?id=<?php echo $id; ?>" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1 cursor-pointer transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path></svg>
        <?php echo __('page_switch_advance'); ?>
    </a>
</div>

<?php if ($contentType === 'blocks'): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 flex items-start gap-3">
    <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
    <div class="text-sm text-amber-800">
        <p><?php echo __('pe_advance_warning'); ?></p>
        <p class="mt-1"><a href="/admin/page_edit_advance.php?id=<?php echo $id; ?>" class="text-primary hover:underline font-medium"><?php echo __('pe_go_advance'); ?></a></p>
    </div>
</div>
<?php endif; ?>

<?php if ($redirectTarget): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 flex items-start gap-3">
    <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
    <div class="text-sm text-amber-800">
        <?php if (!empty($redirectTarget['_is_url'])): ?>
        <p><?php echo __('pe_redirect_to'); ?><strong><?php echo e($redirectTarget['name']); ?></strong></p>
        <?php else: ?>
        <p><?php echo sprintf(__('pe_redirect_child_intro'), e($page['name']), e($redirectTarget['name'])); ?></p>
        <?php endif; ?>
        <p class="mt-1 text-amber-600"><?php echo sprintf(__('pe_redirect_change_hint'), '<a href="/admin/channel.php?edit=' . $id . '" class="underline hover:text-amber-800">' . __('pe_channel_management') . '</a>'); ?></p>
    </div>
</div>
<?php endif; ?>

<form id="editForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('admin_basic_info'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo __('page_name'); ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?php echo e($page['name']); ?>" required
                           class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1"><?php echo __('admin_slug'); ?> (Slug)</label>
                    <input type="text" name="slug" value="<?php echo e($page['slug']); ?>"
                           class="w-full border rounded px-4 py-2" placeholder="<?php echo __('pe_slug_ph'); ?>">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('label_page_desc'); ?></label>
                <textarea name="description" rows="2" class="w-full border rounded px-4 py-2"><?php echo e($page['description']); ?></textarea>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('label_cover_image'); ?></label>
                <input type="text" name="image" id="imageInput" value="<?php echo e($page['image']); ?>"
                       class="w-full border rounded px-3 py-2 text-sm mb-2">
                <div class="flex gap-2">
                    <button type="button" onclick="uploadImage()"
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        <?php echo __('admin_upload_image'); ?></button>
                    <button type="button" onclick="pickImageFromMedia()"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        <?php echo __('admin_media_library'); ?></button>
                </div>
                <?php if ($page['image']): ?>
                <img src="<?php echo e($page['image']); ?>" id="imagePreview" class="h-24 mt-2 rounded">
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('admin_content'); ?></h2>
        </div>
        <div class="p-6">
            <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
            <div id="editor-container" class="border rounded-b-lg" style="min-height: 400px;"></div>
            <input type="hidden" name="content" id="contentInput">
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('admin_seo_settings'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('admin_seo_title'); ?></label>
                <input type="text" name="seo_title" value="<?php echo e($page['seo_title']); ?>"
                       class="w-full border rounded px-4 py-2" placeholder="<?php echo __('pe_seo_title_ph'); ?>">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('admin_seo_keywords'); ?></label>
                <input type="text" name="seo_keywords" value="<?php echo e($page['seo_keywords']); ?>"
                       class="w-full border rounded px-4 py-2" placeholder="<?php echo __('pe_seo_keywords_ph'); ?>">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo __('admin_seo_description'); ?></label>
                <textarea name="seo_description" rows="2" class="w-full border rounded px-4 py-2"><?php echo e($page['seo_description']); ?></textarea>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <?php echo __('admin_save'); ?>
        </button>
    </div>
</form>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script>
function uploadImage() {
    document.getElementById('imageFileInput').click();
}

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;
    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            document.getElementById('imageInput').value = data.data.url;
            let preview = document.getElementById('imagePreview');
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'imagePreview';
                preview.className = 'h-24 mt-2 rounded';
                document.getElementById('imageInput').parentNode.parentNode.appendChild(preview);
            }
            preview.src = data.data.url;
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
    }
    this.value = '';
});

function pickImageFromMedia() {
    openMediaPicker(function(url) {
        document.getElementById('imageInput').value = url;
        var preview = document.getElementById('imagePreview');
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'imagePreview';
            preview.className = 'h-24 mt-2 rounded';
            document.getElementById('imageInput').parentNode.parentNode.appendChild(preview);
        }
        preview.src = url;
    });
}
</script>

<?php
$pageContent = json_encode($page['content'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var editor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: "' . __('label_content') . '...",
    html: ' . $pageContent . ',
    uploadUrl: "/admin/upload.php",
    onChange: function(editor) {
        document.getElementById("contentInput").value = editor.getHtml();
    }
});

document.getElementById("editForm").addEventListener("submit", async function(e) {
    e.preventDefault();
    document.getElementById("contentInput").value = editor.getHtml();

    const formData = new FormData(this);
    try {
        const response = await fetch("", { method: "POST", body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage("' . __('msg_save_success') . '");
        } else {
            showMessage(data.msg, "error");
        }
    } catch (err) {
        showMessage("' . __('btn_request_failed') . '", "error");
    }
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
