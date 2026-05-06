<?php
/**
 * Yikai CMS - 单页编辑（富文本模式）
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

// 默认使用排版编辑器；显式 ?mode=simple 才进入富文本模式
if (get('mode') !== 'simple') {
    header('Location: /admin/page_edit_advance.php?id=' . $id);
    exit;
}

// 获取页面数据
$page = channelModel()->findWhere(['id' => $id, 'type' => 'page']);

if (!$page) {
    header('Location: /admin/page.php');
    exit;
}

// 翻译创建（与 page_edit_advance.php 共用逻辑）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'create_translation') {
    $srcId = postInt('src_id');
    $toLang = post('to_lang');
    $src = channelModel()->find($srcId);
    if (!$src || $src['type'] !== 'page') error('源页面不存在');
    $groupId = (int)($src['translation_group_id'] ?: $srcId);
    if (!$src['translation_group_id']) channelModel()->updateById($srcId, ['translation_group_id' => $srcId]);
    $existing = channelModel()->queryOne("SELECT id FROM " . channelModel()->tableName() . " WHERE translation_group_id = ? AND lang = ?", [$groupId, $toLang]);
    if ($existing) success(['id' => (int)$existing['id']], '翻译已存在');
    $translated = aiTranslateFields($src['name'], $src['description'] ?? '', $toLang);
    $slug = $src['slug'] ? $toLang . '-' . $src['slug'] : '';
    if ($slug) {
        $slugExists = channelModel()->queryOne("SELECT id FROM " . channelModel()->tableName() . " WHERE slug = ?", [$slug]);
        if ($slugExists) { channelModel()->updateById((int)$slugExists['id'], ['name' => $translated['title'], 'lang' => $toLang, 'translation_group_id' => $groupId, 'updated_at' => time()]); success(['id' => (int)$slugExists['id']], '翻译已更新'); }
    }
    $newId = channelModel()->create(['parent_id' => findTranslatedChannelId((int)$src['parent_id'], $toLang) ?: (int)$src['parent_id'], 'name' => $translated['title'], 'slug' => $slug, 'type' => 'page', 'lang' => $toLang, 'translation_group_id' => $groupId, 'description' => $translated['summary'], 'content' => $src['content'], 'image' => $src['image'] ?? '', 'is_nav' => (int)($src['is_nav'] ?? 1), 'status' => (int)($src['status'] ?? 1), 'sort_order' => (int)($src['sort_order'] ?? 0), 'created_at' => time(), 'updated_at' => time()]);
    success(['id' => $newId], '翻译完成');
}

// 特殊页面跳转
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
    adminLog('page', 'edit', '编辑单页：' . $channelData['name']);
    success();
}

$pageTitle = '编辑单页 - ' . $page['name'];
$currentMenu = 'page';

// 兄弟页面导航：所有 type=page 的栏目，按 parent_id 分组（两级）
$allPages = channelModel()->query(
    'SELECT id, parent_id, name, slug FROM ' . channelModel()->tableName() . ' WHERE type = ? ORDER BY parent_id ASC, sort_order ASC, id ASC',
    ['page']
);
$pageTree = [];
foreach ($allPages as $p) {
    if ((int)$p['parent_id'] === 0) {
        $pageTree[(int)$p['id']] = $p + ['children' => []];
    }
}
foreach ($allPages as $p) {
    $pid = (int)$p['parent_id'];
    if ($pid > 0 && isset($pageTree[$pid])) {
        $pageTree[$pid]['children'][] = $p;
    }
}

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php
$langSwitcher = ['table' => 'channels', 'model' => channelModel(), 'item' => $page, 'edit_url' => '/admin/page_edit.php'];
include __DIR__ . '/includes/lang_switcher_edit.php';
?>

<div class="mb-6 flex items-center justify-between">
    <a href="/admin/page.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
        返回单页列表
    </a>
    <a href="/admin/page_edit_advance.php?id=<?php echo $id; ?>" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1 cursor-pointer transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z"></path></svg>
        切换到排版编辑器
    </a>
</div>

<?php if ($contentType === 'blocks'): ?>
<div class="bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6 flex items-start gap-3">
    <svg class="w-5 h-5 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
    <div class="text-sm text-amber-800">
        <p>此页面当前使用<strong>排版编辑器</strong>管理内容。在此处保存将切换为富文本模式，排版布局信息将丢失。</p>
        <p class="mt-1"><a href="/admin/page_edit_advance.php?id=<?php echo $id; ?>" class="text-primary hover:underline font-medium">前往排版编辑器</a></p>
    </div>
</div>
<?php endif; ?>

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

<div class="flex flex-col lg:flex-row gap-6">
    <!-- 左侧兄弟页面导航 -->
    <aside class="w-full lg:w-56 flex-shrink-0">
        <div class="bg-white rounded-lg shadow sticky top-20">
            <div class="px-4 py-3 border-b">
                <h3 class="font-bold text-gray-800 text-sm">所有单页</h3>
            </div>
            <nav class="p-2 max-h-[calc(100vh-10rem)] overflow-y-auto">
                <?php foreach ($pageTree as $top):
                    $isActive = (int)$top['id'] === $id;
                ?>
                <a href="/admin/page_edit.php?id=<?php echo (int)$top['id']; ?>"
                   class="block px-3 py-2 rounded text-sm truncate <?php echo $isActive ? 'bg-primary/10 text-primary font-semibold' : 'text-gray-700 hover:bg-gray-100'; ?>"
                   title="<?php echo e($top['name']); ?>">
                    <?php echo e($top['name']); ?>
                </a>
                <?php if (!empty($top['children'])): ?>
                <div class="ml-3 border-l border-gray-200 pl-2 mb-1">
                    <?php foreach ($top['children'] as $child):
                        $isChildActive = (int)$child['id'] === $id;
                    ?>
                    <a href="/admin/page_edit.php?id=<?php echo (int)$child['id']; ?>"
                       class="block px-3 py-1.5 rounded text-xs truncate <?php echo $isChildActive ? 'bg-primary/10 text-primary font-semibold' : 'text-gray-500 hover:bg-gray-100'; ?>"
                       title="<?php echo e($child['name']); ?>">
                        <?php echo e($child['name']); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php endforeach; ?>
            </nav>
        </div>
    </aside>

    <!-- 右侧编辑表单 -->
    <form id="editForm" class="flex-1 min-w-0 space-y-6">
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

    <details class="bg-white rounded-lg shadow group">
        <summary class="px-6 py-4 border-b cursor-pointer flex items-center justify-between list-none">
            <h2 class="font-bold text-gray-800">SEO 设置</h2>
            <svg class="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
        </summary>
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
    </details>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            保存
        </button>
    </div>
    </form>
</div>

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
