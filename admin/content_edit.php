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

// 翻译创建
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(post('action'), ['translate', 'create_translation']) && $content && isMultiLangEnabled('contents')) {
    $toLang = post('to_lang') ?: post('target_lang');
    $allLangsCheck = availableLanguages();
    if (!isset($allLangsCheck[$toLang])) error('非法语言');
    if ($toLang === ($content['lang'] ?? '')) error('不能翻译为相同语言');

    $groupId = (int)($content['translation_group_id'] ?? 0);
    if ($groupId === 0) {
        $groupId = (int)$content['id'];
        contentModel()->updateById((int)$content['id'], ['translation_group_id' => $groupId]);
    }

    $existing = contentModel()->queryOne("SELECT id FROM " . contentModel()->tableName() . " WHERE translation_group_id = ? AND lang = ?", [$groupId, $toLang]);
    if ($existing) success(['id' => (int)$existing['id'], 'redirect' => '/admin/content_edit.php?id=' . $existing['id']], '翻译已存在');

    $translated = aiTranslateFields($content['title'], $content['summary'] ?? '', $toLang);
    $newData = $content;
    unset($newData['id']);
    $newData['title'] = $translated['title'];
    $newData['summary'] = $translated['summary'];
    $newData['lang'] = $toLang;
    $newData['translation_group_id'] = $groupId;
    $newData['channel_id'] = findTranslatedChannelId((int)$content['channel_id'], $toLang);
    $newData['slug'] = '';
    $newData['status'] = 0;
    $newData['created_at'] = time();
    $newData['updated_at'] = time();
    $newData['admin_id'] = $_SESSION['admin_id'];
    $newId = contentModel()->create($newData);
    adminLog('content', 'translate', "翻译内容 #{$content['id']} → {$toLang} #{$newId}");
    success(['id' => $newId, 'redirect' => '/admin/content_edit.php?id=' . $newId]);
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
        // 案例专用字段
        'client_name'   => post('client_name'),
        'industry'      => post('industry'),
        'duration'      => post('duration'),
        'result_metric' => post('result_metric'),
        'is_top' => postInt('is_top'),
        'is_recommend' => postInt('is_recommend'),
        'is_hot' => postInt('is_hot'),
        'sort_order' => postInt('sort_order'),
        'seo_title' => post('seo_title'),
        'seo_keywords' => post('seo_keywords'),
        'seo_description' => post('seo_description'),
        'status' => postInt('status', 1),
        'publish_time' => strtotime(post('publish_time')) ?: time(),
        'updated_at' => time(),
    ];

    if (isMultiLangEnabled('contents')) {
        $data['lang'] = post('lang', config('site_lang', 'zh-CN'));
    }

    if (empty($data['title'])) {
        error('请输入标题');
    }

    // 过滤器：允许插件在入库前修改数据
    $data = apply_filters('before_save_content', $data, $id);

    $isUpdate = $id > 0;
    if ($isUpdate) {
        contentModel()->updateById($id, $data);
        adminLog('content', 'update', '更新内容：' . $data['title']);
    } else {
        $data['created_at'] = time();
        $data['admin_id'] = $_SESSION['admin_id'];
        $id = (int)contentModel()->create($data);
        adminLog('content', 'create', '创建内容：' . $data['title']);
    }

    // 保存扩展字段 (ext_fields[key] => value)
    if (!empty($_POST['ext_fields']) && is_array($_POST['ext_fields'])) {
        metaModel()->setBatch('content', (int)$id, $_POST['ext_fields']);
    }

    // 动作：同步索引/通知/失效缓存
    do_action('after_save_content', (int)$id, $data, $isUpdate);

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

<?php
$langSwitcher = ['table' => 'contents', 'model' => contentModel(), 'item' => $content, 'edit_url' => '/admin/content_edit.php'];
include __DIR__ . '/includes/lang_switcher_edit.php';
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

            <!-- 案例字段 -->
            <div id="caseFields" class="bg-white rounded-lg shadow p-6 hidden">
                <h3 class="font-bold text-gray-800 mb-4">案例信息</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 mb-1">客户名称</label>
                        <input type="text" name="client_name" value="<?php echo e($content['client_name'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="如：某大型制造企业">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">所属行业</label>
                        <input type="text" name="industry" value="<?php echo e($content['industry'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="如：智能制造">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">项目周期</label>
                        <input type="text" name="duration" value="<?php echo e($content['duration'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="如：2023-06 ~ 2024-02">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1">核心成果</label>
                        <input type="text" name="result_metric" value="<?php echo e($content['result_metric'] ?? ''); ?>"
                               class="w-full border rounded px-4 py-2" placeholder="如：效率提升 30%">
                    </div>
                </div>
            </div>

            <!-- 多图组（案例 / 产品通用） -->
            <div id="galleryFields" class="bg-white rounded-lg shadow p-6 hidden">
                <h3 class="font-bold text-gray-800 mb-4">图片组（画廊）</h3>
                <p class="text-xs text-gray-500 mb-3">除封面外的附加展示图，前台详情页会以画廊（lightbox）形式呈现。</p>
                <input type="hidden" name="images" id="imagesInput" value="<?php echo e($content['images'] ?? ''); ?>">
                <div id="galleryPreview" class="grid grid-cols-3 md:grid-cols-4 gap-2 mb-3"></div>
                <div class="flex gap-2">
                    <button type="button" onclick="addGalleryImage()"
                            class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        上传图片
                    </button>
                    <button type="button" onclick="pickGalleryFromMedia()"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        媒体库
                    </button>
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

            <!-- SEO 设置（折叠） -->
            <details class="bg-white rounded-lg shadow group">
                <summary class="px-6 py-4 cursor-pointer flex items-center justify-between list-none">
                    <h3 class="font-bold text-gray-800">SEO 设置</h3>
                    <svg class="w-4 h-4 text-gray-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="px-6 pb-6 space-y-4 border-t pt-4">
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
            </details>
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

                    <?php if (isMultiLangEnabled('contents')): ?>
                    <div>
                        <label class="block text-gray-700 mb-1">语言</label>
                        <select name="lang" class="w-full border rounded px-4 py-2">
                            <?php $currentLang = $content['lang'] ?? config('site_lang', 'zh-CN'); ?>
                            <?php foreach (availableLanguages() as $lk => $lv): ?>
                            <option value="<?php echo e($lk); ?>" <?php echo $currentLang === $lk ? 'selected' : ''; ?>><?php echo e($lv); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

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

                    <div>
                        <label class="block text-gray-700 mb-1">排序</label>
                        <input type="number" name="sort_order" value="<?php echo (int)($content['sort_order'] ?? 0); ?>" class="w-full border rounded px-4 py-2">
                        <p class="text-xs text-gray-400 mt-1">数字越大越靠前，默认 0</p>
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

    <!-- 底部 sticky 操作栏 -->
    <div class="sticky bottom-0 z-30 -mx-6 -mb-6 mt-8 bg-white border-t shadow-[0_-4px_12px_rgba(0,0,0,0.05)] px-6 py-3">
        <div class="flex gap-3 justify-end items-center">
            <span class="text-xs text-gray-400 mr-auto hidden sm:inline">请确认后保存</span>
            <?php
            // 返回路径：按类型跳对应列表页
            $backUrl = '/admin/content.php';
            if (!empty($content['type']) || !empty($urlType)) {
                $t = $content['type'] ?? $urlType;
                $backMap = ['case' => '/admin/case.php', 'product' => '/admin/product.php', 'download' => '/admin/download.php'];
                $backUrl = $backMap[$t] ?? '/admin/content.php';
            }
            ?>
            <a href="<?php echo e($backUrl); ?>" class="px-5 py-2 border rounded hover:bg-gray-100 transition inline-flex items-center gap-1 text-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                返回
            </a>
            <button type="submit" class="px-8 py-2 bg-primary hover:bg-secondary text-white rounded transition inline-flex items-center gap-1 text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                保存
            </button>
            <?php if ($content && isMultiLangEnabled('contents')): ?>
            <div class="relative" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="px-4 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded transition inline-flex items-center gap-1 text-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"/></svg>
                    翻译为…
                </button>
                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-1 bg-white rounded shadow-lg border py-1 min-w-[120px] z-50">
                    <?php foreach (availableLanguages() as $lk => $lv):
                        if ($lk === ($content['lang'] ?? config('site_lang', 'zh-CN'))) continue;
                    ?>
                    <a href="javascript:void(0)" onclick="translateTo('<?php echo $lk; ?>')" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100"><?php echo e($lv); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
        $extFieldOwnerType = 'content';
        $extFieldOwnerId = (int)$id;
        require __DIR__ . '/includes/extfield_render.php';
    ?>
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
    document.getElementById('caseFields').classList.toggle('hidden', type !== 'case');
    // 画廊：案例 和 产品 都显示
    document.getElementById('galleryFields').classList.toggle('hidden', !(type === 'case' || type === 'product'));
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

// ============ 多图画廊 ============
// images 字段兼容两种格式：JSON 数组 或 换行分隔字符串
function galleryList() {
    var raw = document.getElementById('imagesInput').value || '';
    raw = raw.trim();
    if (!raw) return [];
    try {
        var arr = JSON.parse(raw);
        if (Array.isArray(arr)) return arr.filter(Boolean);
    } catch(e) {}
    return raw.split(/\r?\n/).map(function(s) { return s.trim(); }).filter(Boolean);
}
function galleryWrite(arr) {
    document.getElementById('imagesInput').value = JSON.stringify(arr);
    renderGallery();
}
function renderGallery() {
    var list = galleryList();
    var box = document.getElementById('galleryPreview');
    if (!box) return;
    if (!list.length) {
        box.innerHTML = '<div class="col-span-full text-xs text-gray-400 py-4 text-center border-2 border-dashed rounded">暂无图片</div>';
        return;
    }
    box.innerHTML = list.map(function(url, i) {
        return '<div class="relative group aspect-square bg-gray-100 rounded overflow-hidden">' +
               '  <img src="' + url.replace(/"/g, '&quot;') + '" class="w-full h-full object-cover">' +
               '  <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition flex items-center justify-center gap-2">' +
               (i > 0 ? '    <button type="button" onclick="galleryMove(' + i + ',-1)" class="text-white bg-gray-700 rounded w-7 h-7 text-sm" title="左移">←</button>' : '') +
               (i < list.length - 1 ? '    <button type="button" onclick="galleryMove(' + i + ',1)" class="text-white bg-gray-700 rounded w-7 h-7 text-sm" title="右移">→</button>' : '') +
               '    <button type="button" onclick="galleryRemove(' + i + ')" class="text-white bg-red-500 rounded w-7 h-7 text-sm" title="删除">×</button>' +
               '  </div>' +
               '</div>';
    }).join('');
}
function galleryAdd(url) {
    if (!url) return;
    var list = galleryList();
    list.push(url);
    galleryWrite(list);
}
function galleryRemove(i) {
    var list = galleryList();
    list.splice(i, 1);
    galleryWrite(list);
}
function galleryMove(i, dir) {
    var list = galleryList();
    var j = i + dir;
    if (j < 0 || j >= list.length) return;
    var tmp = list[i];
    list[i] = list[j];
    list[j] = tmp;
    galleryWrite(list);
}
function addGalleryImage() {
    var inp = document.createElement('input');
    inp.type = 'file';
    inp.accept = 'image/*';
    inp.addEventListener('change', async function() {
        if (!this.files[0]) return;
        var fd = new FormData();
        fd.append('file', this.files[0]);
        fd.append('type', 'images');
        try {
            var resp = await fetch('/admin/upload.php', { method: 'POST', body: fd });
            var data = await safeJson(resp);
            if (data.code === 0) galleryAdd(data.data.url);
            else showMessage(data.msg, 'error');
        } catch(e) { showMessage('上传失败', 'error'); }
    });
    inp.click();
}
function pickGalleryFromMedia() {
    openMediaPicker(function(url) { galleryAdd(url); });
}
// 初次渲染
renderGallery();

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

async function translateTo(lang) {
    if (!confirm('Copy and create ' + lang + ' version?')) return;
    var formData = new FormData();
    formData.append('action', 'translate');
    formData.append('target_lang', lang);
    try {
        var response = await fetch('', { method: 'POST', body: formData });
        var data = await safeJson(response);
        if (data.code === 0 && data.data.redirect) {
            showMessage('OK');
            setTimeout(function(){ location.href = data.data.redirect; }, 800);
        } else {
            showMessage(data.msg || 'Error', 'error');
        }
    } catch (e) {
        showMessage('Error', 'error');
    }
}
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
