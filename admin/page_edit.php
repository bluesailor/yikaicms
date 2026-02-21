<?php
/**
 * Yikai CMS - 单页编辑
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

if ($contentRecord) {
    $page['content'] = $contentRecord['content'];
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = resolveSlug(post('slug'), post('name'), 'channels', $id);

    $channelData = [
        'name' => post('name'),
        'slug' => $slug,
        'description' => post('description'),
        'image' => post('image'),
        'seo_title' => post('seo_title'),
        'seo_keywords' => post('seo_keywords'),
        'seo_description' => post('seo_description'),
        'updated_at' => time(),
    ];

    $newContent = $_POST['content'] ?? '';

    if ($contentRecord) {
        // 更新已有内容记录
        contentModel()->updateById((int)$contentRecord['id'], [
            'content' => $newContent,
            'updated_at' => time(),
        ]);
    } else {
        // 没有内容记录则创建一条
        contentModel()->create([
            'channel_id' => $id,
            'title' => post('name'),
            'content' => $newContent,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    channelModel()->updateById($id, $channelData);
    adminLog('page', 'edit', '编辑单页：' . $channelData['name']);
    success();
}

$pageTitle = '编辑单页 - ' . $page['name'];
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <a href="/admin/page.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
        返回单页列表
    </a>
</div>

<?php if ($redirectTarget): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 flex items-start gap-3">
    <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
    <div class="text-sm text-amber-800">
        <?php if (!empty($redirectTarget['_is_url'])): ?>
        <p>前台访问此页面时，将自动跳转到：<strong><?php echo e($redirectTarget['name']); ?></strong></p>
        <?php else: ?>
        <p>前台访问「<?php echo e($page['name']); ?>」时，将自动跳转到子栏目「<strong><?php echo e($redirectTarget['name']); ?></strong>」，此页面内容不会直接展示。</p>
        <?php endif; ?>
        <p class="mt-1 text-amber-600">如需修改跳转行为，请前往 <a href="/admin/channel.php?edit=<?php echo $id; ?>" class="underline hover:text-amber-800">栏目管理</a> 调整「跳转方式」设置。</p>
    </div>
</div>
<?php endif; ?>

<form id="editForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">基本信息</h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm text-gray-700 mb-1">页面名称 <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?php echo e($page['name']); ?>" required
                           class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-sm text-gray-700 mb-1">URL别名 (Slug)</label>
                    <input type="text" name="slug" value="<?php echo e($page['slug']); ?>"
                           class="w-full border rounded px-4 py-2" placeholder="如：about-us，留空自动生成">
                </div>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1">页面描述</label>
                <textarea name="description" rows="2" class="w-full border rounded px-4 py-2"><?php echo e($page['description']); ?></textarea>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1">封面图片</label>
                <input type="text" name="image" id="imageInput" value="<?php echo e($page['image']); ?>"
                       class="w-full border rounded px-3 py-2 text-sm mb-2">
                <div class="flex gap-2">
                    <button type="button" onclick="uploadImage()"
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        上传图片</button>
                    <button type="button" onclick="pickImageFromMedia()"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        媒体库</button>
                </div>
                <?php if ($page['image']): ?>
                <img src="<?php echo e($page['image']); ?>" id="imagePreview" class="h-24 mt-2 rounded">
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">页面内容</h2>
        </div>
        <div class="p-6">
            <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
            <div id="editor-container" class="border rounded-b-lg" style="min-height: 400px;"></div>
            <input type="hidden" name="content" id="contentInput">
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">SEO设置</h2>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm text-gray-700 mb-1">SEO标题</label>
                <input type="text" name="seo_title" value="<?php echo e($page['seo_title']); ?>"
                       class="w-full border rounded px-4 py-2" placeholder="留空使用页面名称">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">SEO关键词</label>
                <input type="text" name="seo_keywords" value="<?php echo e($page['seo_keywords']); ?>"
                       class="w-full border rounded px-4 py-2" placeholder="多个关键词用逗号分隔">
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">SEO描述</label>
                <textarea name="seo_description" rows="2" class="w-full border rounded px-4 py-2"><?php echo e($page['seo_description']); ?></textarea>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            保存
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
            showMessage('上传成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
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
    placeholder: "请输入页面内容...",
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
            showMessage("保存成功");
        } else {
            showMessage(data.msg, "error");
        }
    } catch (err) {
        showMessage("请求失败", "error");
    }
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
