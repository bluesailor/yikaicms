<?php
/**
 * Yikai CMS - 单页排版编辑器（高级模式）
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

$page = channelModel()->findWhere(['id' => $id, 'type' => 'page']);

if (!$page) {
    header('Location: /admin/page.php');
    exit;
}

if (($page['slug'] ?? '') === 'contact') {
    header('Location: /admin/setting_contact.php');
    exit;
}

// 从 contents 表获取内容
$contentRecord = contentModel()->queryOne(
    'SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? AND status = 1 ORDER BY is_top DESC, id DESC LIMIT 1',
    [$id]
);

$contentType = 'html';
$blocksData = '';
$htmlContent = '';

if ($contentRecord) {
    $contentType = $contentRecord['content_type'] ?? 'html';
    $blocksData = $contentRecord['blocks_data'] ?? '';
    $htmlContent = $contentRecord['content'] ?? '';
}

// 如果是从富文本模式进入，自动转换为单区块
$autoConvert = false;
if ($contentType === 'html' && $htmlContent && !$blocksData) {
    $autoConvert = true;
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

    $postBlocksData = $_POST['blocks_data'] ?? '[]';
    $renderedHtml = renderBlocksToHtml($postBlocksData);

    if ($contentRecord) {
        contentModel()->updateById((int)$contentRecord['id'], [
            'content' => $renderedHtml,
            'content_type' => 'blocks',
            'blocks_data' => $postBlocksData,
            'updated_at' => time(),
        ]);
    } else {
        contentModel()->create([
            'channel_id' => $id,
            'title' => post('name'),
            'content' => $renderedHtml,
            'content_type' => 'blocks',
            'blocks_data' => $postBlocksData,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    channelModel()->updateById($id, $channelData);
    adminLog('page', 'edit', '排版编辑单页：' . $channelData['name']);
    success();
}

$pageTitle = '排版编辑 - ' . $page['name'];
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <a href="/admin/page.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
        返回单页列表
    </a>
    <a href="/admin/page_edit.php?id=<?php echo $id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1 cursor-pointer transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
        切换到富文本编辑器
    </a>
</div>

<form id="editForm" class="space-y-6" x-data="pageBuilder()" x-init="init()">
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

    <!-- 排版编辑器 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800">排版内容</h2>
            <span class="text-xs text-gray-400">拖拽区块排序 / 每个区块可设置列数、背景、间距</span>
        </div>
        <div class="p-6">
            <template x-if="sections.length === 0">
                <div class="text-center py-12 text-gray-400">
                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                    <p>暂无区块，点击下方按钮添加</p>
                </div>
            </template>

            <!-- 区块列表 -->
            <div x-ref="sectionsContainer">
                <template x-for="(section, si) in sections" :key="section.id">
                    <div class="border border-gray-200 rounded-lg mb-4 group/section hover:border-blue-300 transition">
                        <!-- 区块工具栏 -->
                        <div class="flex items-center justify-between px-4 py-2 bg-gray-50 rounded-t-lg border-b">
                            <div class="flex items-center gap-2">
                                <span class="section-drag-handle cursor-grab text-gray-300 hover:text-gray-500">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                                </span>
                                <span class="text-sm font-medium text-gray-600" x-text="'区块 ' + (si + 1)"></span>
                                <span class="text-xs text-gray-400" x-text="section.columns.length + ' 列'"></span>
                                <template x-if="section.settings.bg_color">
                                    <span class="inline-block w-3 h-3 rounded-full border" :style="'background:' + section.settings.bg_color"></span>
                                </template>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="moveSection(si, -1)" :disabled="si === 0"
                                        class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="上移">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path></svg>
                                </button>
                                <button type="button" @click="moveSection(si, 1)" :disabled="si === sections.length - 1"
                                        class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="下移">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                                <button type="button" @click="openSettings(si)"
                                        class="p-1 text-gray-400 hover:text-gray-600 cursor-pointer" title="设置">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                </button>
                                <button type="button" @click="removeSection(si)"
                                        class="p-1 text-red-400 hover:text-red-600 cursor-pointer" title="删除">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </div>
                        </div>
                        <!-- 列内容 -->
                        <div class="p-4">
                            <div class="grid gap-4" :class="section.columns.length > 1 ? 'grid-cols-' + section.columns.length : ''">
                                <template x-for="(col, ci) in section.columns" :key="col.id">
                                    <div class="border border-dashed border-gray-300 rounded-lg p-3 min-h-[100px]"
                                         :data-section-index="si" :data-column-index="ci" data-sortable-elements>
                                        <!-- 元素列表 -->
                                        <template x-for="(el, ei) in col.elements" :key="el.id">
                                            <div class="mb-2 bg-white border rounded p-3 group/el relative hover:border-blue-300 transition block-element">
                                                <!-- 元素工具栏 -->
                                                <div class="absolute -top-2 -right-2 flex gap-0.5 z-10 bg-white border rounded shadow-sm px-1 py-0.5">
                                                    <span class="element-drag-handle cursor-grab p-1 text-gray-400 hover:text-gray-600" title="拖拽排序">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8h16M4 16h16"></path></svg>
                                                    </span>
                                                    <button type="button" @click="moveElement(si,ci,ei,-1)" :disabled="ei===0"
                                                            class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="上移">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"></path></svg>
                                                    </button>
                                                    <button type="button" @click="moveElement(si,ci,ei,1)" :disabled="ei===col.elements.length-1"
                                                            class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="下移">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                                    </button>
                                                    <button type="button" @click="removeElement(si,ci,ei)"
                                                            class="p-1 text-red-400 hover:text-red-600 cursor-pointer" title="删除">
                                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                    </button>
                                                </div>

                                                <!-- 标题 -->
                                                <template x-if="el.type === 'heading'">
                                                    <div>
                                                        <div class="flex items-center gap-2 mb-1">
                                                            <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">标题</span>
                                                            <select x-model="el.data.level" class="text-xs border rounded px-1.5 py-0.5">
                                                                <option value="h1">H1</option>
                                                                <option value="h2">H2</option>
                                                                <option value="h3">H3</option>
                                                                <option value="h4">H4</option>
                                                            </select>
                                                        </div>
                                                        <input type="text" x-model="el.data.text" placeholder="输入标题..."
                                                               class="w-full border-0 border-b border-gray-200 font-bold text-lg focus:outline-none focus:border-primary py-1">
                                                    </div>
                                                </template>

                                                <!-- 富文本 -->
                                                <template x-if="el.type === 'text'">
                                                    <div>
                                                        <div class="flex items-center justify-between mb-1">
                                                            <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">富文本</span>
                                                            <button type="button" @click="editText(si,ci,ei)" class="text-xs text-primary hover:underline cursor-pointer">编辑内容</button>
                                                        </div>
                                                        <div @dblclick="editText(si,ci,ei)" class="prose prose-sm max-w-none max-h-32 overflow-hidden text-gray-600 border-t pt-2 cursor-pointer"
                                                             x-html="el.data.html || '<span class=\'text-gray-400 italic\'>双击或点击编辑添加内容</span>'"></div>
                                                    </div>
                                                </template>

                                                <!-- 图片 -->
                                                <template x-if="el.type === 'image'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-1 inline-block">图片</span>
                                                        <div x-show="el.data.src">
                                                            <img :src="el.data.src" class="max-h-40 rounded border">
                                                            <div class="flex items-center gap-2 mt-1">
                                                                <input type="text" x-model="el.data.alt" placeholder="图片描述(alt)"
                                                                       class="flex-1 text-xs border rounded px-2 py-1">
                                                                <button type="button" @click="pickImage(si,ci,ei)" class="text-xs text-primary hover:underline cursor-pointer whitespace-nowrap">更换</button>
                                                            </div>
                                                            <div class="flex items-center gap-2 mt-1.5">
                                                                <select x-model="el.data.click_action" class="text-xs border rounded px-2 py-1">
                                                                    <option value="">点击无动作</option>
                                                                    <option value="lightbox">点击弹出大图</option>
                                                                    <option value="link">点击跳转链接</option>
                                                                </select>
                                                                <template x-if="el.data.click_action === 'link'">
                                                                    <input type="text" x-model="el.data.link_url" placeholder="链接地址"
                                                                           class="flex-1 text-xs border rounded px-2 py-1">
                                                                </template>
                                                                <template x-if="el.data.click_action === 'link'">
                                                                    <label class="flex items-center gap-1 text-xs text-gray-500 cursor-pointer whitespace-nowrap">
                                                                        <input type="checkbox" x-model="el.data.link_new_tab"> 新窗口
                                                                    </label>
                                                                </template>
                                                            </div>
                                                        </div>
                                                        <div x-show="!el.data.src" @click="pickImage(si,ci,ei)"
                                                             class="h-20 bg-gray-50 rounded border-2 border-dashed border-gray-300 flex items-center justify-center text-gray-400 text-sm cursor-pointer hover:border-primary hover:text-primary transition">
                                                            点击选择图片
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- 按钮 -->
                                                <template x-if="el.type === 'button'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-1 inline-block">按钮</span>
                                                        <div class="flex gap-2 mt-1">
                                                            <input type="text" x-model="el.data.text" placeholder="按钮文字"
                                                                   class="border rounded px-2 py-1 text-sm flex-1">
                                                            <input type="text" x-model="el.data.url" placeholder="链接地址"
                                                                   class="border rounded px-2 py-1 text-sm flex-1">
                                                        </div>
                                                        <label class="flex items-center gap-1 mt-1 text-xs text-gray-500 cursor-pointer">
                                                            <input type="checkbox" x-model="el.data.new_tab"> 新窗口打开
                                                        </label>
                                                    </div>
                                                </template>

                                                <!-- 图标 -->
                                                <template x-if="el.type === 'icon'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-2 inline-block">图标</span>
                                                        <div class="flex flex-wrap gap-1.5 mb-2 p-2 border rounded bg-gray-50 max-h-28 overflow-y-auto">
                                                            <template x-for="ic in ['star','heart','check-circle','phone','mail','map-pin','clock','shield','zap','award','globe','users','home','settings','camera','bell','bookmark','calendar','folder','gift','link','lock','search','tag','trending-up','thumbs-up','eye','download','upload','share','code','coffee','feather','flag','info','life-buoy','mic','monitor','music','package','pen-tool','printer','send','server','smile','sun','target','terminal','truck','tv','umbrella','wifi']">
                                                                <button type="button" @click="el.data.icon = ic"
                                                                        class="w-8 h-8 flex items-center justify-center border rounded text-gray-600 hover:bg-primary hover:text-white transition cursor-pointer"
                                                                        :class="el.data.icon === ic ? 'bg-primary text-white border-primary' : 'bg-white'">
                                                                    <svg class="w-4 h-4"><use :href="'/assets/icons/feather-sprite.svg#' + ic"></use></svg>
                                                                </button>
                                                            </template>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-2">
                                                            <div>
                                                                <label class="text-xs text-gray-500">大小</label>
                                                                <select x-model="el.data.size" class="w-full border rounded px-2 py-1 text-sm">
                                                                    <option value="sm">小 (24px)</option>
                                                                    <option value="md">中 (32px)</option>
                                                                    <option value="lg">大 (48px)</option>
                                                                    <option value="xl">超大 (64px)</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label class="text-xs text-gray-500">颜色</label>
                                                                <div class="flex gap-1">
                                                                    <input type="color" x-model="el.data.color" class="w-8 h-8 rounded border cursor-pointer">
                                                                    <input type="text" x-model="el.data.color" placeholder="#主题色" class="flex-1 border rounded px-2 py-1 text-xs">
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="mt-2">
                                                            <input type="text" x-model="el.data.text" placeholder="图标下方文字（可选）" class="w-full border rounded px-2 py-1 text-sm">
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- 分隔线 -->
                                                <template x-if="el.type === 'divider'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-2 inline-block">分隔线</span>
                                                        <div class="flex items-center gap-3">
                                                            <div>
                                                                <label class="text-xs text-gray-500">样式</label>
                                                                <select x-model="el.data.style" class="border rounded px-2 py-1 text-sm">
                                                                    <option value="solid">实线</option>
                                                                    <option value="dashed">虚线</option>
                                                                    <option value="dotted">点线</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label class="text-xs text-gray-500">粗细</label>
                                                                <select x-model="el.data.width" class="border rounded px-2 py-1 text-sm">
                                                                    <option value="1">1px</option>
                                                                    <option value="2">2px</option>
                                                                    <option value="3">3px</option>
                                                                </select>
                                                            </div>
                                                            <div>
                                                                <label class="text-xs text-gray-500">颜色</label>
                                                                <div class="flex gap-1">
                                                                    <input type="color" x-model="el.data.color" class="w-8 h-8 rounded border cursor-pointer">
                                                                    <input type="text" x-model="el.data.color" placeholder="#e5e7eb" class="w-20 border rounded px-2 py-1 text-xs">
                                                                </div>
                                                            </div>
                                                            <div>
                                                                <label class="text-xs text-gray-500">间距</label>
                                                                <select x-model="el.data.spacing" class="border rounded px-2 py-1 text-sm">
                                                                    <option value="sm">小</option>
                                                                    <option value="md">中</option>
                                                                    <option value="lg">大</option>
                                                                </select>
                                                            </div>
                                                        </div>
                                                        <div class="mt-2 px-2" :style="'border-top-style:' + el.data.style + ';border-top-width:' + el.data.width + 'px;border-top-color:' + (el.data.color || '#e5e7eb')"></div>
                                                    </div>
                                                </template>

                                                <!-- 代码/HTML -->
                                                <template x-if="el.type === 'code'">
                                                    <div>
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded mb-1 inline-block">代码/HTML</span>
                                                        <textarea x-model="el.data.html" rows="4" placeholder="输入 HTML 代码、短码 [form-xxx]、iframe 等..."
                                                                  class="w-full border rounded px-3 py-2 text-sm font-mono bg-gray-50 text-gray-800"></textarea>
                                                    </div>
                                                </template>

                                                <!-- 间距 -->
                                                <template x-if="el.type === 'spacer'">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">间距</span>
                                                        <select x-model="el.data.size" class="border rounded px-2 py-1 text-sm">
                                                            <option value="sm">小 (16px)</option>
                                                            <option value="md">中 (32px)</option>
                                                            <option value="lg">大 (64px)</option>
                                                            <option value="xl">超大 (96px)</option>
                                                        </select>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        <!-- 添加元素 -->
                                        <div x-data="{ open: false }" class="relative add-element-btn">
                                            <button type="button" @click="open = !open"
                                                    class="w-full border-2 border-dashed border-gray-300 rounded py-2 text-gray-400 hover:border-primary hover:text-primary transition text-sm cursor-pointer">
                                                + 添加元素
                                            </button>
                                            <div x-show="open" @click.away="open = false" x-cloak
                                                 class="absolute z-10 mt-1 bg-white border rounded-lg shadow-lg py-1 w-36 left-1/2 -translate-x-1/2">
                                                <button type="button" @click="addElement(si,ci,'heading'); open=false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">标题</button>
                                                <button type="button" @click="addElement(si,ci,'text'); open=false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">富文本</button>
                                                <button type="button" @click="addElement(si,ci,'image'); open=false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">图片</button>
                                                <button type="button" @click="addElement(si,ci,'button'); open=false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">按钮</button>
                                                <button type="button" @click="addElement(si,ci,'divider'); open=false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">分隔线</button>
                                                <button type="button" @click="addElement(si,ci,'code'); open=false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">代码/HTML</button>
                                                <button type="button" @click="addElement(si,ci,'spacer'); open=false"
                                                        class="block w-full text-left px-3 py-2 text-sm hover:bg-gray-50 cursor-pointer">间距</button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- 添加区块 -->
            <div x-data="{ showPicker: false }" class="mt-4">
                <button type="button" @click="showPicker = !showPicker"
                        class="w-full border-2 border-dashed border-gray-300 rounded-lg py-4 text-gray-400 hover:border-primary hover:text-primary transition text-sm cursor-pointer">
                    + 添加区块
                </button>
                <div x-show="showPicker" x-cloak class="mt-2 border rounded-lg p-4 bg-gray-50">
                    <p class="text-sm text-gray-600 mb-3">选择列布局：</p>
                    <div class="flex gap-3 justify-center">
                        <button type="button" @click="addSection(1); showPicker=false"
                                class="border-2 rounded-lg p-3 hover:border-primary hover:bg-white transition flex flex-col items-center cursor-pointer">
                            <div class="w-20 h-10 bg-blue-100 rounded"></div>
                            <span class="text-xs mt-1 text-gray-600">1 列</span>
                        </button>
                        <button type="button" @click="addSection(2); showPicker=false"
                                class="border-2 rounded-lg p-3 hover:border-primary hover:bg-white transition flex flex-col items-center cursor-pointer">
                            <div class="flex gap-1 w-20 h-10">
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                            </div>
                            <span class="text-xs mt-1 text-gray-600">2 列</span>
                        </button>
                        <button type="button" @click="addSection(3); showPicker=false"
                                class="border-2 rounded-lg p-3 hover:border-primary hover:bg-white transition flex flex-col items-center cursor-pointer">
                            <div class="flex gap-1 w-20 h-10">
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                            </div>
                            <span class="text-xs mt-1 text-gray-600">3 列</span>
                        </button>
                        <button type="button" @click="addSection(4); showPicker=false"
                                class="border-2 rounded-lg p-3 hover:border-primary hover:bg-white transition flex flex-col items-center cursor-pointer">
                            <div class="flex gap-1 w-20 h-10">
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                                <div class="flex-1 bg-blue-100 rounded"></div>
                            </div>
                            <span class="text-xs mt-1 text-gray-600">4 列</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- hidden -->
    <input type="hidden" name="blocks_data" :value="JSON.stringify(sections)">

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

    <div class="bg-white rounded-lg shadow p-6 flex items-center gap-4">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1 cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            保存
        </button>
        <span id="saveMsg" class="text-sm hidden"></span>
    </div>
</form>

<!-- 区块设置弹窗 -->
<div id="sectionSettingsModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800">区块设置</h3>
            <button type="button" onclick="closeSectionSettings()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-sm text-gray-700 mb-1">列数</label>
                <div class="flex gap-2">
                    <button type="button" onclick="setSectionCols(1)" data-cols="1" class="col-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer">1 列</button>
                    <button type="button" onclick="setSectionCols(2)" data-cols="2" class="col-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer">2 列</button>
                    <button type="button" onclick="setSectionCols(3)" data-cols="3" class="col-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer">3 列</button>
                    <button type="button" onclick="setSectionCols(4)" data-cols="4" class="col-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer">4 列</button>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">内边距</label>
                <select id="settingPadding" class="w-full border rounded px-3 py-2">
                    <option value="none">无</option>
                    <option value="sm">小</option>
                    <option value="md">中（默认）</option>
                    <option value="lg">大</option>
                    <option value="xl">超大</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">最大宽度</label>
                <select id="settingMaxWidth" class="w-full border rounded px-3 py-2">
                    <option value="default">默认 (1152px)</option>
                    <option value="narrow">窄 (896px)</option>
                    <option value="wide">宽 (1280px)</option>
                    <option value="full">全宽</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">垂直对齐</label>
                <div class="flex gap-2">
                    <button type="button" onclick="setAlignItems('start')" data-val="start" class="align-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="4" x2="20" y2="4"/><rect x="6" y="4" width="4" height="10" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="4" width="4" height="6" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">顶部</span>
                    </button>
                    <button type="button" onclick="setAlignItems('center')" data-val="center" class="align-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="12" x2="20" y2="12" stroke-dasharray="2 2"/><rect x="6" y="7" width="4" height="10" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="9" width="4" height="6" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">居中</span>
                    </button>
                    <button type="button" onclick="setAlignItems('end')" data-val="end" class="align-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="20" x2="20" y2="20"/><rect x="6" y="10" width="4" height="10" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="14" width="4" height="6" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">底部</span>
                    </button>
                    <button type="button" onclick="setAlignItems('stretch')" data-val="stretch" class="align-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="4" x2="20" y2="4"/><line x1="4" y1="20" x2="20" y2="20"/><rect x="6" y="4" width="4" height="16" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="4" width="4" height="16" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">拉伸</span>
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">水平对齐</label>
                <div class="flex gap-2">
                    <button type="button" onclick="setJustifyItems('start')" data-val="start" class="justify-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="4" x2="4" y2="20"/><rect x="4" y="6" width="10" height="4" rx="1" fill="currentColor" opacity="0.3"/><rect x="4" y="14" width="6" height="4" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">左对齐</span>
                    </button>
                    <button type="button" onclick="setJustifyItems('center')" data-val="center" class="justify-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="4" x2="12" y2="20" stroke-dasharray="2 2"/><rect x="7" y="6" width="10" height="4" rx="1" fill="currentColor" opacity="0.3"/><rect x="9" y="14" width="6" height="4" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">居中</span>
                    </button>
                    <button type="button" onclick="setJustifyItems('end')" data-val="end" class="justify-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="20" y1="4" x2="20" y2="20"/><rect x="10" y="6" width="10" height="4" rx="1" fill="currentColor" opacity="0.3"/><rect x="14" y="14" width="6" height="4" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">右对齐</span>
                    </button>
                    <button type="button" onclick="setJustifyItems('stretch')" data-val="stretch" class="justify-btn flex-1 py-2 border rounded text-sm hover:bg-gray-50 cursor-pointer flex flex-col items-center gap-0.5">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="4" x2="4" y2="20"/><line x1="20" y1="4" x2="20" y2="20"/><rect x="4" y="6" width="16" height="4" rx="1" fill="currentColor" opacity="0.3"/><rect x="4" y="14" width="16" height="4" rx="1" fill="currentColor" opacity="0.3"/></svg>
                        <span class="text-xs">拉伸</span>
                    </button>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">列间距</label>
                <select id="settingGap" class="w-full border rounded px-3 py-2">
                    <option value="none">无间距</option>
                    <option value="sm">小 (8px)</option>
                    <option value="md">中 (16px)</option>
                    <option value="lg">大 (32px) - 默认</option>
                    <option value="xl">超大 (48px)</option>
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">背景颜色</label>
                <div class="flex gap-2 items-center">
                    <input type="color" id="settingBgColor" value="#ffffff" class="w-10 h-10 rounded border cursor-pointer">
                    <input type="text" id="settingBgColorText" placeholder="如 #f3f4f6 或留空"
                           class="flex-1 border rounded px-3 py-2 text-sm">
                </div>
                <div class="flex items-center gap-2 mt-2">
                    <label class="text-xs text-gray-500 whitespace-nowrap">透明度</label>
                    <input type="range" id="settingBgOpacity" min="0" max="100" value="100" class="flex-1 cursor-pointer">
                    <span id="settingBgOpacityVal" class="text-xs text-gray-500 w-8 text-right">100%</span>
                </div>
            </div>
            <div>
                <label class="block text-sm text-gray-700 mb-1">背景图片</label>
                <div class="flex gap-2">
                    <input type="text" id="settingBgImage" placeholder="图片URL" class="flex-1 border rounded px-3 py-2 text-sm">
                    <button type="button" onclick="uploadSectionBgImage()" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm cursor-pointer" title="上传本地图片">上传</button>
                    <button type="button" onclick="pickSectionBgImage()" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm cursor-pointer" title="从媒体库选择">媒体库</button>
                </div>
                <input type="file" id="sectionBgFileInput" class="hidden" accept="image/*">
            </div>
            <button type="button" onclick="saveSectionSettings()" class="w-full bg-primary hover:bg-secondary text-white py-2 rounded transition cursor-pointer">确定</button>
        </div>
    </div>
</div>

<!-- 富文本编辑弹窗 -->
<div id="textEditorModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4 flex flex-col" style="max-height: calc(100vh - 3rem)">
        <div class="px-6 py-4 border-b flex items-center justify-between shrink-0">
            <h3 class="font-bold text-gray-800">编辑富文本内容</h3>
            <button type="button" onclick="closeTextEditor()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto p-6">
            <div id="modal-toolbar" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
            <div id="modal-editor" class="border rounded-b-lg" style="min-height: 300px;"></div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2 shrink-0">
            <button type="button" onclick="closeTextEditor()" class="px-4 py-2 border rounded hover:bg-gray-100 text-sm cursor-pointer">取消</button>
            <button type="button" onclick="saveTextEditor()" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded text-sm cursor-pointer">确定</button>
        </div>
    </div>
</div>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 封面图片上传
function uploadImage() {
    document.getElementById('imageFileInput').click();
}
document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;
    var formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        var response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        var data = await safeJson(response);
        if (data.code === 0) {
            document.getElementById('imageInput').value = data.data.url;
            var preview = document.getElementById('imagePreview');
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'imagePreview';
                preview.className = 'h-24 mt-2 rounded';
                document.getElementById('imageInput').parentNode.parentNode.appendChild(preview);
            }
            preview.src = data.data.url;
            showMessage('上传成功');
        } else { showMessage(data.msg, 'error'); }
    } catch (err) { showMessage('上传失败', 'error'); }
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

// ===== 区块设置弹窗 =====
var _settingSi = -1;
function closeSectionSettings() {
    var m = document.getElementById('sectionSettingsModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
function pickSectionBgImage() {
    openMediaPicker(function(url) { document.getElementById('settingBgImage').value = url; });
}
function uploadSectionBgImage() {
    document.getElementById('sectionBgFileInput').click();
}
document.getElementById('sectionBgFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;
    var formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        var resp = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        var data = await safeJson(resp);
        if (data.code === 0) {
            document.getElementById('settingBgImage').value = data.data.url;
            showMessage('上传成功');
        } else { showMessage(data.msg || '上传失败', 'error'); }
    } catch (e) { showMessage('上传失败', 'error'); }
    this.value = '';
});
function setSectionCols(n) {
    document.querySelectorAll('.col-btn').forEach(function(b) { b.classList.remove('bg-primary', 'text-white'); });
    document.querySelector('.col-btn[data-cols="' + n + '"]').classList.add('bg-primary', 'text-white');
}
function setAlignItems(val) {
    document.querySelectorAll('.align-btn').forEach(function(b) { b.classList.remove('bg-primary', 'text-white'); });
    var btn = document.querySelector('.align-btn[data-val="' + val + '"]');
    if (btn) btn.classList.add('bg-primary', 'text-white');
}
function setJustifyItems(val) {
    document.querySelectorAll('.justify-btn').forEach(function(b) { b.classList.remove('bg-primary', 'text-white'); });
    var btn = document.querySelector('.justify-btn[data-val="' + val + '"]');
    if (btn) btn.classList.add('bg-primary', 'text-white');
}
function saveSectionSettings() {
    var data = Alpine.$data(document.getElementById('editForm'));
    if (_settingSi < 0 || !data.sections[_settingSi]) return;
    var section = data.sections[_settingSi];
    section.settings.padding = document.getElementById('settingPadding').value;
    section.settings.max_width = document.getElementById('settingMaxWidth').value;
    section.settings.bg_color = document.getElementById('settingBgColorText').value.trim();
    section.settings.bg_opacity = parseInt(document.getElementById('settingBgOpacity').value);
    section.settings.bg_image = document.getElementById('settingBgImage').value.trim();
    section.settings.gap = document.getElementById('settingGap').value;
    var alignBtn = document.querySelector('.align-btn.bg-primary');
    section.settings.align_items = alignBtn ? alignBtn.dataset.val : 'stretch';
    var justifyBtn = document.querySelector('.justify-btn.bg-primary');
    section.settings.justify_items = justifyBtn ? justifyBtn.dataset.val : 'stretch';
    // 列数变更
    var activeBtn = document.querySelector('.col-btn.bg-primary');
    var newCols = activeBtn ? parseInt(activeBtn.dataset.cols) : section.columns.length;
    var curCols = section.columns.length;
    if (newCols > curCols) {
        for (var i = curCols; i < newCols; i++) {
            section.columns.push({ id: data.uid('c'), elements: [] });
        }
    } else if (newCols < curCols) {
        for (var i = newCols; i < curCols; i++) {
            var extra = section.columns[i].elements;
            for (var j = 0; j < extra.length; j++) {
                section.columns[newCols - 1].elements.push(extra[j]);
            }
        }
        section.columns.splice(newCols);
    }
    closeSectionSettings();
    data.$nextTick(function() { data.initSortable(); });
}

// 背景颜色同步
document.getElementById('settingBgColor').addEventListener('input', function() {
    document.getElementById('settingBgColorText').value = this.value === '#ffffff' ? '' : this.value;
});
document.getElementById('settingBgColorText').addEventListener('input', function() {
    if (/^#[0-9a-fA-F]{6}$/.test(this.value.trim())) {
        document.getElementById('settingBgColor').value = this.value.trim();
    }
});
document.getElementById('settingBgOpacity').addEventListener('input', function() {
    document.getElementById('settingBgOpacityVal').textContent = this.value + '%';
});

// ===== 富文本弹窗 =====
var _modalEditor = null;
var _editingPath = null;
function closeTextEditor() {
    var m = document.getElementById('textEditorModal');
    m.classList.add('hidden'); m.classList.remove('flex');
}
function saveTextEditor() {
    if (_modalEditor && _editingPath) {
        var data = Alpine.$data(document.getElementById('editForm'));
        data.sections[_editingPath.si].columns[_editingPath.ci].elements[_editingPath.ei].data.html = _modalEditor.getHtml();
    }
    closeTextEditor();
}
</script>

<?php
// 初始化区块数据
$blocksDataJson = $blocksData ?: '[]';
if ($blocksData && json_decode($blocksData) === null) {
    $blocksDataJson = '[]';
}

// 如果从富文本自动转换
$initBlocks = $blocksDataJson;
if ($autoConvert && $htmlContent) {
    $autoSection = [[
        'id' => 's_auto',
        'settings' => ['bg_color' => '', 'bg_image' => '', 'padding' => 'md', 'max_width' => 'default', 'align_items' => 'stretch', 'justify_items' => 'stretch', 'gap' => 'lg'],
        'columns' => [[
            'id' => 'c_auto',
            'elements' => [[
                'id' => 'e_auto',
                'type' => 'text',
                'data' => ['html' => $htmlContent],
            ]],
        ]],
    ]];
    $initBlocks = json_encode($autoSection, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
}

$extraJs = '<script>
function pageBuilder() {
    return {
        sections: ' . $initBlocks . ',

        uid(prefix) {
            return prefix + "_" + Math.random().toString(36).substr(2, 9);
        },

        init() {
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },

        // === 区块操作 ===
        addSection(colCount) {
            var cols = [];
            for (var i = 0; i < colCount; i++) {
                cols.push({ id: this.uid("c"), elements: [] });
            }
            this.sections.push({
                id: this.uid("s"),
                settings: { bg_color: "", bg_image: "", padding: "md", max_width: "default", align_items: "stretch", justify_items: "stretch", gap: "lg" },
                columns: cols
            });
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },

        removeSection(si) {
            if (!confirm("确定删除此区块？")) return;
            this.sections.splice(si, 1);
        },

        moveSection(si, dir) {
            var ni = si + dir;
            if (ni < 0 || ni >= this.sections.length) return;
            var tmp = this.sections.splice(si, 1)[0];
            this.sections.splice(ni, 0, tmp);
        },

        openSettings(si) {
            _settingSi = si;
            var s = this.sections[si].settings;
            document.getElementById("settingPadding").value = s.padding || "md";
            document.getElementById("settingMaxWidth").value = s.max_width || "default";
            document.getElementById("settingBgColorText").value = s.bg_color || "";
            document.getElementById("settingBgColor").value = s.bg_color || "#ffffff";
            document.getElementById("settingBgImage").value = s.bg_image || "";
            var opacity = s.bg_opacity !== undefined ? s.bg_opacity : 100;
            document.getElementById("settingBgOpacity").value = opacity;
            document.getElementById("settingBgOpacityVal").textContent = opacity + "%";
            document.getElementById("settingGap").value = s.gap || "lg";
            setAlignItems(s.align_items || "stretch");
            setJustifyItems(s.justify_items || "stretch");
            document.querySelectorAll(".col-btn").forEach(function(b) { b.classList.remove("bg-primary", "text-white"); });
            var btn = document.querySelector(".col-btn[data-cols=\"" + this.sections[si].columns.length + "\"]");
            if (btn) btn.classList.add("bg-primary", "text-white");
            var m = document.getElementById("sectionSettingsModal");
            m.classList.remove("hidden"); m.classList.add("flex");
        },

        // === 元素操作 ===
        addElement(si, ci, type) {
            var defaults = {
                heading: { text: "", level: "h2" },
                text: { html: "" },
                image: { src: "", alt: "", click_action: "", link_url: "", link_new_tab: false },
                button: { text: "按钮", url: "", new_tab: false },
                icon: { icon: "star", size: "md", color: "", text: "" },
                divider: { style: "solid", width: "1", color: "#e5e7eb", spacing: "md" },
                code: { html: "" },
                spacer: { size: "md" }
            };
            this.sections[si].columns[ci].elements.push({
                id: this.uid("e"),
                type: type,
                data: JSON.parse(JSON.stringify(defaults[type] || {}))
            });
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
        },

        removeElement(si, ci, ei) {
            this.sections[si].columns[ci].elements.splice(ei, 1);
        },

        moveElement(si, ci, ei, dir) {
            var els = this.sections[si].columns[ci].elements;
            var ni = ei + dir;
            if (ni < 0 || ni >= els.length) return;
            var tmp = els.splice(ei, 1)[0];
            els.splice(ni, 0, tmp);
        },

        editText(si, ci, ei) {
            _editingPath = { si: si, ci: ci, ei: ei };
            var el = this.sections[si].columns[ci].elements[ei];
            var m = document.getElementById("textEditorModal");
            m.classList.remove("hidden"); m.classList.add("flex");
            if (!_modalEditor) {
                _modalEditor = initWangEditor("#modal-toolbar", "#modal-editor", {
                    placeholder: "请输入内容...",
                    html: el.data.html || "",
                    uploadUrl: "/admin/upload.php",
                    onChange: function() {}
                });
            } else {
                _modalEditor.setHtml(el.data.html || "<p><br></p>");
            }
        },

        pickImage(si, ci, ei) {
            var self = this;
            openMediaPicker(function(url) {
                self.sections[si].columns[ci].elements[ei].data.src = url;
            });
        },

        // === SortableJS ===
        initSortable() {
            var self = this;
            var container = this.$refs.sectionsContainer;
            if (container) {
                if (container._sortable) container._sortable.destroy();
                container._sortable = new Sortable(container, {
                    handle: ".section-drag-handle",
                    animation: 150,
                    ghostClass: "opacity-30",
                    onEnd: function(evt) {
                        var item = self.sections.splice(evt.oldIndex, 1)[0];
                        self.sections.splice(evt.newIndex, 0, item);
                    }
                });
            }
            this.$nextTick(function() {
                document.querySelectorAll("[data-sortable-elements]").forEach(function(el) {
                    if (el._sortable) el._sortable.destroy();
                    el._sortable = new Sortable(el, {
                        group: "elements",
                        handle: ".element-drag-handle",
                        animation: 150,
                        ghostClass: "opacity-30",
                        filter: ".add-element-btn",
                        onEnd: function(evt) {
                            var fromSi = parseInt(evt.from.dataset.sectionIndex);
                            var fromCi = parseInt(evt.from.dataset.columnIndex);
                            var toSi = parseInt(evt.to.dataset.sectionIndex);
                            var toCi = parseInt(evt.to.dataset.columnIndex);
                            var item = self.sections[fromSi].columns[fromCi].elements.splice(evt.oldIndex, 1)[0];
                            self.sections[toSi].columns[toCi].elements.splice(evt.newIndex, 0, item);
                        }
                    });
                });
            });
        }
    };
}

// === 表单提交 ===
document.getElementById("editForm").addEventListener("submit", async function(e) {
    e.preventDefault();
    var formData = new FormData(this);
    var msgEl = document.getElementById("saveMsg");
    try {
        var response = await fetch("", { method: "POST", body: formData });
        var result = await safeJson(response);
        if (result.code === 0) {
            msgEl.textContent = "保存成功";
            msgEl.className = "text-sm text-green-600";
        } else {
            msgEl.textContent = result.msg || "保存失败";
            msgEl.className = "text-sm text-red-600";
        }
    } catch (err) {
        msgEl.textContent = "请求失败";
        msgEl.className = "text-sm text-red-600";
    }
    setTimeout(function() { msgEl.className = "text-sm hidden"; }, 3000);
});
</script>';

require_once ROOT_PATH . '/admin/includes/footer.php';
?>
