<?php
/**
 * Yikai CMS - 内容编辑
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
$content = $id > 0 ? contentModel()->find($id) : null;

// 联系我们单页跳转到专用设置页
if ($content) {
    $editChannel = getChannel((int)$content['channel_id']);
    if ($editChannel && $editChannel['slug'] === 'contact') {
        header('Location: /admin/setting_contact.php');
        exit;
    }
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'channel_id' => postInt('channel_id'),
        'type' => post('type', 'article'),
        'title' => post('title'),
        'subtitle' => post('subtitle'),
        'slug' => post('slug'),
        'cover' => post('cover'),
        'images' => post('images'),
        'summary' => post('summary'),
        'content' => $_POST['content'] ?? '',
        'author' => post('author'),
        'source' => post('source'),
        'tags' => post('tags'),
        'attachment' => post('attachment'),
        'price' => (float) post('price', '0'),
        'specs' => post('specs'),
        'is_top' => postInt('is_top'),
        'is_recommend' => postInt('is_recommend'),
        'is_hot' => postInt('is_hot'),
        'seo_title' => post('seo_title'),
        'seo_keywords' => post('seo_keywords'),
        'seo_description' => post('seo_description'),
        'status' => postInt('status', 1),
        'publish_time' => strtotime(post('publish_time')) ?: time(),
        'updated_at' => time(),
    ];

    if (empty($data['title'])) {
        error('请输入标题');
    }

    if ($id > 0) {
        contentModel()->updateById($id, $data);
        adminLog('content', 'update', '更新内容：' . $data['title']);
    } else {
        $data['created_at'] = time();
        $data['admin_id'] = $_SESSION['admin_id'];
        $id = contentModel()->create($data);
        adminLog('content', 'create', '创建内容：' . $data['title']);
    }

    success(['id' => $id]);
}

// 获取栏目列表
$channels = channelModel()->query(
    'SELECT id, parent_id, name, type FROM ' . channelModel()->tableName() . ' WHERE type != "link" ORDER BY sort_order DESC'
);

// URL传入的类型参数（如 ?type=job）
$urlType = get('type', '');

// 确定锁定的类型和栏目
$lockedType = '';
$lockedChannelId = 0;
$filteredChannels = $channels; // 栏目下拉选项

if ($content) {
    // 编辑模式：锁定类型和栏目
    $lockedType = $content['type'];
    $lockedChannelId = (int)$content['channel_id'];
} elseif ($urlType !== '') {
    // 新建模式 + URL指定类型：锁定类型，筛选同类型栏目
    $lockedType = $urlType;
    $typeChannels = array_values(array_filter($channels, fn($ch) => $ch['type'] === $urlType));

    if (count($typeChannels) === 1) {
        // 只有一个同类型栏目，直接锁定
        $lockedChannelId = (int)$typeChannels[0]['id'];
    } elseif (count($typeChannels) > 1) {
        // 多个同类型栏目：下拉只显示同类型，优先选子栏目（parent_id>0）
        $filteredChannels = $typeChannels;
        // 优先选子栏目（更具体），否则选第一个
        $defaultChannel = null;
        foreach ($typeChannels as $ch) {
            if ((int)($ch['parent_id'] ?? 0) > 0) {
                $defaultChannel = $ch;
                break;
            }
        }
        $lockedChannelId = 0; // 不锁定，允许用户在同类型间切换
    }
}

$typeLabels = ['article' => '文章', 'product' => '产品', 'case' => '案例', 'download' => '下载', 'faq' => 'FAQ'];
$pageTitle = $content ? '编辑内容' : ($urlType && isset($typeLabels[$urlType]) ? '发布' . $typeLabels[$urlType] : '添加内容');
$currentMenu = 'content';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<form id="contentForm" class="space-y-6">
    <input type="hidden" name="id" value="<?php echo $id; ?>">

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- 主要内容 -->
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">标题 <span class="text-red-500">*</span></label>
                        <input type="text" name="title" value="<?php echo e($content['title'] ?? ''); ?>" required
                               class="w-full border rounded px-4 py-2 text-lg">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">副标题</label>
                        <input type="text" name="subtitle" value="<?php echo e($content['subtitle'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">摘要</label>
                        <textarea name="summary" rows="3" class="w-full border rounded px-4 py-2"><?php echo e($content['summary'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">内容</label>
                        <input type="hidden" name="content" id="contentInput">
                        <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                        <div id="editor-container" class="border rounded-b-lg" style="min-height: 400px;"></div>
                    </div>
                </div>
            </div>

            <!-- 产品字段 -->
            <div id="productFields" class="bg-white rounded-lg shadow p-6 hidden">
                <h3 class="font-bold text-gray-800 mb-4">产品信息</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 mb-1">价格</label>
                        <input type="number" name="price" value="<?php echo $content['price'] ?? ''; ?>" step="0.01"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">规格参数 (JSON)</label>
                        <input type="text" name="specs" value="<?php echo e($content['specs'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder='{"key":"value"}'>
                    </div>
                </div>
            </div>

            <!-- 下载字段 -->
            <div id="downloadFields" class="bg-white rounded-lg shadow p-6 hidden">
                <h3 class="font-bold text-gray-800 mb-4">下载信息</h3>
                <div>
                    <label class="block text-gray-700 mb-1">附件</label>
                    <div class="flex gap-2">
                        <input type="text" name="attachment" id="attachmentInput" value="<?php echo e($content['attachment'] ?? ''); ?>"
                               class="flex-1 border rounded px-4 py-2" placeholder="附件URL">
                        <button type="button" onclick="uploadAttachment()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                            <?php echo __('admin_choose_file'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- SEO 设置 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">SEO设置</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">SEO标题</label>
                        <input type="text" name="seo_title" value="<?php echo e($content['seo_title'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="留空使用标题">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">SEO关键词</label>
                        <input type="text" name="seo_keywords" value="<?php echo e($content['seo_keywords'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="多个关键词用逗号分隔">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">SEO描述</label>
                        <textarea name="seo_description" rows="2" class="w-full border rounded px-4 py-2"><?php echo e($content['seo_description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- 侧边栏 -->
        <div class="space-y-6">
            <!-- 发布设置 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">发布设置</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">栏目 <span class="text-red-500">*</span></label>
                        <?php if ($lockedChannelId): ?>
                        <input type="hidden" name="channel_id" value="<?php echo $lockedChannelId; ?>">
                        <?php endif; ?>
                        <?php
                        // 确定预选的栏目ID
                        $selectedChId = $lockedChannelId ?: ($content['channel_id'] ?? 0);
                        if (!$selectedChId && isset($defaultChannel)) {
                            $selectedChId = (int)$defaultChannel['id'];
                        }
                        ?>
                        <select id="channelSelect" <?php echo $lockedChannelId ? '' : 'name="channel_id"'; ?> class="w-full border rounded px-4 py-2 <?php echo $lockedChannelId ? 'bg-gray-100 text-gray-500' : ''; ?>" <?php echo $lockedChannelId ? 'disabled' : 'required'; ?>>
                            <?php if (!$lockedType): ?>
                            <option value="">请选择栏目</option>
                            <?php endif; ?>
                            <?php foreach ($filteredChannels as $ch): ?>
                            <option value="<?php echo $ch['id']; ?>" data-type="<?php echo $ch['type']; ?>"
                                    <?php echo (int)$selectedChId === (int)$ch['id'] ? 'selected' : ''; ?>>
                                <?php echo e($ch['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">内容类型</label>
                        <?php if ($lockedType): ?>
                        <input type="hidden" name="type" value="<?php echo e($lockedType); ?>">
                        <?php endif; ?>
                        <select id="typeSelect" <?php echo $lockedType ? '' : 'name="type"'; ?> class="w-full border rounded px-4 py-2 <?php echo $lockedType ? 'bg-gray-100 text-gray-500' : ''; ?>" <?php echo $lockedType ? 'disabled' : ''; ?>>
                            <option value="article" <?php echo ($lockedType ?: ($content['type'] ?? '')) === 'article' ? 'selected' : ''; ?>>文章</option>
                            <option value="product" <?php echo ($lockedType ?: ($content['type'] ?? '')) === 'product' ? 'selected' : ''; ?>>产品</option>
                            <option value="case" <?php echo ($lockedType ?: ($content['type'] ?? '')) === 'case' ? 'selected' : ''; ?>>案例</option>
                            <option value="download" <?php echo ($lockedType ?: ($content['type'] ?? '')) === 'download' ? 'selected' : ''; ?>>下载</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">状态</label>
                        <select name="status" class="w-full border rounded px-4 py-2">
                            <option value="1" <?php echo ($content['status'] ?? 1) == 1 ? 'selected' : ''; ?>>发布</option>
                            <option value="0" <?php echo ($content['status'] ?? 1) == 0 ? 'selected' : ''; ?>>草稿</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">发布时间</label>
                        <input type="datetime-local" name="publish_time"
                               value="<?php echo date('Y-m-d\TH:i', ($content['publish_time'] ?? 0) ?: time()); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_top" value="1" <?php echo ($content['is_top'] ?? 0) ? 'checked' : ''; ?> class="mr-2">
                            置顶
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_recommend" value="1" <?php echo ($content['is_recommend'] ?? 0) ? 'checked' : ''; ?> class="mr-2">
                            推荐
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_hot" value="1" <?php echo ($content['is_hot'] ?? 0) ? 'checked' : ''; ?> class="mr-2">
                            热门
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex gap-2">
                    <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        保存
                    </button>
                    <a href="/admin/content.php" class="px-4 py-2 border rounded hover:bg-gray-100 transition inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                        返回</a>
                </div>
            </div>

            <!-- 封面图 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">封面图</h3>
                <div id="coverPreview" class="mb-4 <?php echo empty($content['cover']) ? 'hidden' : ''; ?>">
                    <img src="<?php echo e($content['cover'] ?? ''); ?>" class="w-full rounded">
                </div>
                <input type="hidden" name="cover" id="coverInput" value="<?php echo e($content['cover'] ?? ''); ?>">
                <div class="flex gap-2">
                    <button type="button" onclick="uploadCover()"
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        上传图片</button>
                    <button type="button" onclick="pickCoverFromMedia()"
                            class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        媒体库</button>
                </div>
            </div>

            <!-- 其他信息 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4">其他信息</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1">URL别名</label>
                        <input type="text" name="slug" value="<?php echo e($content['slug'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="可选">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">作者</label>
                        <input type="text" name="author" value="<?php echo e($content['author'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">来源</label>
                        <input type="text" name="source" value="<?php echo e($content['source'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">标签</label>
                        <input type="text" name="tags" value="<?php echo e($content['tags'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="多个标签用逗号分隔">
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- 上传文件的隐藏表单 -->
<input type="file" id="fileInput" class="hidden" accept="image/*">
<input type="file" id="attachmentFileInput" class="hidden">

<script>
var isLocked = <?php echo $lockedType ? 'true' : 'false'; ?>;

// 类型切换
function updateTypeFields() {
    const type = document.getElementById('typeSelect').value;
    document.getElementById('productFields').classList.toggle('hidden', type !== 'product');
    document.getElementById('downloadFields').classList.toggle('hidden', type !== 'download');
}

if (!isLocked) {
    document.getElementById('typeSelect').addEventListener('change', updateTypeFields);

    document.getElementById('channelSelect').addEventListener('change', function() {
        var option = this.options[this.selectedIndex];
        if (!option) return;
        var type = option.dataset.type;
        if (type && type !== 'list' && type !== 'page') {
            document.getElementById('typeSelect').value = type;
        }
        updateTypeFields();
    });
}

// 页面加载时显示对应字段
updateTypeFields();

// 上传封面
function uploadCover() {
    document.getElementById('fileInput').click();
}

document.getElementById('fileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            document.getElementById('coverInput').value = data.data.url;
            document.getElementById('coverPreview').classList.remove('hidden');
            document.getElementById('coverPreview').querySelector('img').src = data.data.url;
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
    }

    this.value = '';
});

// 从媒体库选择封面
function pickCoverFromMedia() {
    openMediaPicker(function(url) {
        document.getElementById('coverInput').value = url;
        document.getElementById('coverPreview').classList.remove('hidden');
        document.getElementById('coverPreview').querySelector('img').src = url;
    });
}

// 上传附件
function uploadAttachment() {
    document.getElementById('attachmentFileInput').click();
}

document.getElementById('attachmentFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'files');

    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            document.getElementById('attachmentInput').value = data.data.url;
            showMessage('上传成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
    }

    this.value = '';
});
</script>

<?php
$contentHtml = json_encode($content['content'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var editor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: "请输入内容...",
    html: ' . $contentHtml . ',
    uploadUrl: "/admin/upload.php",
    onChange: function(editor) {
        document.getElementById("contentInput").value = editor.getHtml();
    }
});

document.getElementById("contentForm").addEventListener("submit", async function(e) {
    e.preventDefault();
    document.getElementById("contentInput").value = editor.getHtml();

    const formData = new FormData(this);

    try {
        const response = await fetch("", { method: "POST", body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage("保存成功");
            setTimeout(function() { location.href = "/admin/content.php"; }, 1000);
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
