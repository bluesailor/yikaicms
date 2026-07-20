<?php
/**
 * YikaiCMS - 内容编辑
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

// 多语言翻译创建器：处理 action=create_translation 的 POST（必须在主 POST 处理前 require）
$langSwitcher = [
    'table' => 'contents',
    'model' => contentModel(),
];
require_once ROOT_PATH . '/admin/includes/translate_action.php';

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

    // 定时发布（以发布时间为准）：
    //  - 选「定时」但时间已过 → 按已发布
    //  - 选「发布」但时间在未来 → 自动转为定时
    if ((int) $data['status'] === 3 && $data['publish_time'] <= time()) {
        $data['status'] = 1;
    } elseif ((int) $data['status'] === 1 && $data['publish_time'] > time()) {
        $data['status'] = 3;
    }

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

    // 扩展字段值存入 metas（owner_type：自定义模型用 model_key，内置内容类型用 'content'）
    $extOwner = resolveExtFieldOwner((string) $data['type']);
    foreach ((array) ($_POST['ext_fields'] ?? []) as $fieldKey => $fieldVal) {
        if (!is_string($fieldKey)) continue;
        setMeta($extOwner, $id, $fieldKey, is_array($fieldVal) ? implode(',', $fieldVal) : (string) $fieldVal);
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
$pageTitle = $content ? __('admin_content_edit') : ($urlType && isset($typeLabels[$urlType]) ? '发布' . $typeLabels[$urlType] : __('admin_add'));
$currentMenu = 'content';

// widget 用：把当前编辑行 + URL 信息塞进早先设的 $langSwitcher
$langSwitcher['item']     = $content;
$langSwitcher['edit_url'] = '/admin/content_edit.php';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php require ROOT_PATH . '/admin/includes/lang_switcher_edit.php'; ?>

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
                        <label class="block text-gray-700 mb-1"><?php echo __('label_subtitle'); ?></label>
                        <input type="text" name="subtitle" value="<?php echo e($content['subtitle'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_summary'); ?></label>
                        <textarea name="summary" rows="3" class="w-full border rounded px-4 py-2"><?php echo e($content['summary'] ?? ''); ?></textarea>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1">内容</label>
                        <input type="hidden" name="content" id="contentInput">
                        <div id="toolbar-container" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
                        <div id="editor-container" class="border rounded-b-lg" style="min-height: 400px;"></div>

                        <!-- 模板标签速查 -->
                        <details class="mt-3 text-sm border rounded-lg bg-gray-50">
                            <summary class="px-4 py-2 cursor-pointer text-gray-600 select-none">
                                <i class="ti ti-code"></i> <?php echo __('tagref_title'); ?>
                            </summary>
                            <div class="px-4 py-3 border-t space-y-2 text-gray-600">
                                <p class="text-gray-500"><?php echo __('tagref_intro'); ?></p>
                                <table class="w-full text-xs">
                                    <tbody class="divide-y divide-gray-200">
                                        <tr><td class="py-1.5 pr-3 font-mono text-primary whitespace-nowrap">{yk:list type=article cat=news limit=6}…{/yk:list}</td><td class="py-1.5 text-gray-500"><?php echo __('tagref_list'); ?></td></tr>
                                        <tr><td class="py-1.5 pr-3 font-mono text-primary whitespace-nowrap">{yk:field name=title /}</td><td class="py-1.5 text-gray-500"><?php echo __('tagref_field'); ?></td></tr>
                                        <tr><td class="py-1.5 pr-3 font-mono text-primary whitespace-nowrap">{yk:nav parent=product}…{/yk:nav}</td><td class="py-1.5 text-gray-500"><?php echo __('tagref_nav'); ?></td></tr>
                                        <tr><td class="py-1.5 pr-3 font-mono text-primary whitespace-nowrap">{yk:channel slug=about field=url /}</td><td class="py-1.5 text-gray-500"><?php echo __('tagref_channel'); ?></td></tr>
                                        <tr><td class="py-1.5 pr-3 font-mono text-primary whitespace-nowrap">{yk:banner group=home /}</td><td class="py-1.5 text-gray-500"><?php echo __('tagref_banner'); ?></td></tr>
                                        <tr><td class="py-1.5 pr-3 font-mono text-primary whitespace-nowrap">{yk:config name=site_name /}</td><td class="py-1.5 text-gray-500"><?php echo __('tagref_config'); ?></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </details>
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
                    <label class="block text-gray-700 mb-1"><?php echo __('label_attachment'); ?></label>
                    <div class="flex gap-2">
                        <input type="text" name="attachment" id="attachmentInput" value="<?php echo e($content['attachment'] ?? ''); ?>"
                               class="flex-1 border rounded px-4 py-2" placeholder="附件URL">
                        <button type="button" onclick="uploadAttachment()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                            <i class="ti ti-folder text-base"></i>
                            <?php echo __('admin_choose_file'); ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- 扩展字段（内置 content 字段 + 自定义模型字段；渲染 ext_fields[<key>]，随表单一起提交保存到 metas）-->
            <?php
            $extFieldOwnerType = resolveExtFieldOwner((string) $lockedType);
            $extFieldOwnerId   = (int) $id;
            require ROOT_PATH . '/admin/includes/extfield_render.php';
            ?>

            <!-- SEO 设置 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('admin_seo_settings'); ?></h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('admin_seo_title'); ?></label>
                        <input type="text" name="seo_title" value="<?php echo e($content['seo_title'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="留空使用标题">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('admin_seo_keywords'); ?></label>
                        <input type="text" name="seo_keywords" value="<?php echo e($content['seo_keywords'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="<?php echo __('pe_seo_keywords_ph'); ?>">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('admin_seo_description'); ?></label>
                        <textarea name="seo_description" rows="2" class="w-full border rounded px-4 py-2"><?php echo e($content['seo_description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- 侧边栏 -->
        <div class="space-y-6">
            <!-- 发布设置 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('label_publish_settings'); ?></h3>
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
                        <label class="block text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                        <select name="status" id="statusSelect" class="w-full border rounded px-4 py-2">
                            <option value="1" <?php echo ($content['status'] ?? 1) == 1 ? 'selected' : ''; ?>><?php echo __('admin_published'); ?></option>
                            <option value="0" <?php echo ($content['status'] ?? 1) == 0 ? 'selected' : ''; ?>><?php echo __('admin_draft'); ?></option>
                            <option value="3" <?php echo ($content['status'] ?? 1) == 3 ? 'selected' : ''; ?>><?php echo __('admin_scheduled'); ?></option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_publish_time'); ?></label>
                        <input type="datetime-local" name="publish_time"
                               value="<?php echo date('Y-m-d\TH:i', ($content['publish_time'] ?? 0) ?: time()); ?>"
                               class="w-full border rounded px-4 py-2">
                        <p id="schedHint" class="text-xs text-orange-500 mt-1" style="display:none;"><?php echo __('admin_scheduled_hint'); ?></p>
                    </div>
                    <script>
                    (function () {
                        var sel = document.getElementById('statusSelect');
                        var hint = document.getElementById('schedHint');
                        function sync() { if (sel && hint) hint.style.display = sel.value === '3' ? 'block' : 'none'; }
                        if (sel) { sel.addEventListener('change', sync); sync(); }
                    })();
                    </script>

                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_top" value="1" <?php echo ($content['is_top'] ?? 0) ? 'checked' : ''; ?> class="mr-2">
                            <?php echo __('admin_top'); ?>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_recommend" value="1" <?php echo ($content['is_recommend'] ?? 0) ? 'checked' : ''; ?> class="mr-2">
                            <?php echo __('admin_recommend'); ?>
                        </label>
                        <label class="flex items-center">
                            <input type="checkbox" name="is_hot" value="1" <?php echo ($content['is_hot'] ?? 0) ? 'checked' : ''; ?> class="mr-2">
                            <?php echo __('admin_hot'); ?>
                        </label>
                    </div>
                </div>

                <div class="mt-6 flex gap-2">
                    <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                        <i class="ti ti-check text-base"></i>
                        <?php echo __('admin_save'); ?>
                    </button>
                    <a href="/admin/content.php" class="px-4 py-2 border rounded hover:bg-gray-100 transition inline-flex items-center gap-1">
                        <i class="ti ti-arrow-left text-base"></i>
                        <?php echo __('admin_back'); ?></a>
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
                        <i class="ti ti-upload text-base"></i>
                        <?php echo __('admin_upload_image'); ?></button>
                    <button type="button" onclick="pickCoverFromMedia()"
                            class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                        <i class="ti ti-photo text-base"></i>
                        <?php echo __('admin_media_library'); ?></button>
                </div>
            </div>

            <!-- 图集（多图） -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-1">图集（多图）</h3>
                <p class="text-xs text-gray-400 mb-3">可选。除封面外的多张图片，前台文章页以图集展示，可拖动排序。</p>
                <input type="hidden" name="images" id="imagesInput" value="<?php echo e(is_string($content['images'] ?? '') ? ($content['images'] ?? '') : ''); ?>">
                <div id="galleryGrid" class="grid grid-cols-3 gap-2 mb-3"></div>
                <div class="flex gap-2">
                    <button type="button" onclick="galleryUpload()"
                            class="flex-1 bg-gray-500 hover:bg-gray-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                        <i class="ti ti-upload text-base"></i>
                        <?php echo __('admin_upload_image'); ?></button>
                    <button type="button" onclick="galleryPick()"
                            class="flex-1 bg-blue-500 hover:bg-blue-600 text-white py-2 rounded text-sm inline-flex items-center justify-center gap-1">
                        <i class="ti ti-photo text-base"></i>
                        <?php echo __('admin_media_library'); ?></button>
                </div>
            </div>

            <!-- 其他信息 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="font-bold text-gray-800 mb-4"><?php echo __('label_other_info'); ?></h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('admin_slug'); ?></label>
                        <input type="text" name="slug" value="<?php echo e($content['slug'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="<?php echo __('optional'); ?>">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_author'); ?></label>
                        <input type="text" name="author" value="<?php echo e($content['author'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_source'); ?></label>
                        <input type="text" name="source" value="<?php echo e($content['source'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo __('label_tags'); ?></label>
                        <input type="text" name="tags" value="<?php echo e($content['tags'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="<?php echo __('label_tags_hint'); ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- 上传文件的隐藏表单 -->
<input type="file" id="fileInput" class="hidden" accept="image/*">
<input type="file" id="attachmentFileInput" class="hidden">
<input type="file" id="galleryFileInput" class="hidden" accept="image/*" multiple>

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
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
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
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
    }

    this.value = '';
});

// ===== 图集（多图）=====
var galleryImages = (function () {
    var raw = (document.getElementById('imagesInput').value || '').trim();
    if (raw === '') return [];
    try {
        var v = JSON.parse(raw);
        if (Array.isArray(v)) return v.filter(function (x) { return typeof x === 'string' && x; });
    } catch (e) {}
    return [raw]; // 兼容历史单值
})();
var _galDrag = null;

function gallerySync() {
    document.getElementById('imagesInput').value = JSON.stringify(galleryImages);
    var g = document.getElementById('galleryGrid');
    if (!galleryImages.length) { g.innerHTML = '<div class="col-span-3 text-xs text-gray-300 py-4 text-center border border-dashed rounded">暂无图片</div>'; return; }
    g.innerHTML = galleryImages.map(function (url, i) {
        return '<div class="relative group border rounded overflow-hidden cursor-move" draggable="true" data-i="' + i + '">'
            + '<img src="' + url.replace(/"/g, '&quot;') + '" class="w-full h-24 object-cover pointer-events-none">'
            + '<button type="button" data-rm="' + i + '" class="absolute top-1 right-1 bg-black/50 hover:bg-red-500 text-white w-5 h-5 rounded-full text-xs leading-5 text-center">&times;</button>'
            + '</div>';
    }).join('');
    Array.prototype.forEach.call(g.children, function (el) {
        var rm = el.querySelector('[data-rm]');
        if (rm) rm.addEventListener('click', function () { galleryImages.splice(+this.getAttribute('data-rm'), 1); gallerySync(); });
        el.addEventListener('dragstart', function () { _galDrag = +this.dataset.i; });
        el.addEventListener('dragover', function (e) { e.preventDefault(); });
        el.addEventListener('drop', function (e) {
            e.preventDefault();
            var to = +this.dataset.i;
            if (_galDrag === null || _galDrag === to) return;
            var m = galleryImages.splice(_galDrag, 1)[0];
            galleryImages.splice(to, 0, m);
            _galDrag = null;
            gallerySync();
        });
    });
}
function galleryUpload() { document.getElementById('galleryFileInput').click(); }
function galleryPick() { openMediaPicker(function (url) { if (url) { galleryImages.push(url); gallerySync(); } }); }
document.getElementById('galleryFileInput').addEventListener('change', async function () {
    for (var i = 0; i < this.files.length; i++) {
        var fd = new FormData();
        fd.append('file', this.files[i]);
        fd.append('type', 'images');
        try {
            var resp = await fetch('/admin/upload.php', { method: 'POST', body: fd });
            var data = await safeJson(resp);
            if (data.code === 0) galleryImages.push(data.data.url);
            else showMessage(data.msg, 'error');
        } catch (err) { showMessage('<?php echo __('admin_fail'); ?>', 'error'); }
    }
    gallerySync();
    this.value = '';
});
gallerySync();
</script>

<?php
$contentHtml = json_encode($content['content'] ?? '', JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$extraJs = '<script>
var editor = initWangEditor("#toolbar-container", "#editor-container", {
    placeholder: "' . __("editor_placeholder") . '",
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
            showMessage("' . __("msg_save_success") . '");
            setTimeout(function() { location.href = "/admin/content.php"; }, 1000);
        } else {
            showMessage(data.msg, "error");
        }
    } catch (err) {
        showMessage("' . __("admin_fail") . '", "error");
    }
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
