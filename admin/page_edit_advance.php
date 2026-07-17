<?php
/**
 * YikaiCMS - 单页排版编辑器（高级模式）
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

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/presets.php';

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

if (($page['slug'] ?? '') === 'history') {
    header('Location: /admin/timeline.php');
    exit;
}

// 父栏目有子页：不再硬跳转到第一个子页（那样父页无法编辑、且无视「不跳转」设置），
// 改为在页面顶部显示醒目横幅（见 parent_page_notice.php），父页本身可直接编辑。
$children = channelModel()->getByParent($id, true);

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

// 实时预览：渲染 blocks_data → 套主题 CSS 的独立 HTML，供构建器 iframe 展示（不落库）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview') {
    $body = renderBlocksToHtml($_POST['blocks_data'] ?? '[]');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="' . htmlspecialchars(siteLang()) . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<link rel="stylesheet" href="/assets/css/tailwind.css">'
        . '<link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">'
        . '<base target="_blank">'
        . '<style>body{margin:0;background:#fff}</style></head><body>'
        . $body
        . '</body></html>';
    exit;
}

// 可复用块库（P2）：保存/列表/取块/删除。表缺失（未跑升级）时容错提示。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_starts_with((string) ($_POST['action'] ?? ''), 'lib_')) {
    $libAction = $_POST['action'];
    try {
        if ($libAction === 'lib_save') {
            $libName = trim(post('lib_name'));
            $libSection = json_decode($_POST['section_data'] ?? '', true);
            if ($libName === '' || !is_array($libSection)) {
                error('参数不完整');
            }
            if (!empty($libSection['library_id'])) {
                error('引用块请先转为副本再存入块库');
            }
            unset($libSection['library_name']);
            $now = time();
            $libId = db()->insert(DB_PREFIX . 'blocks_library', [
                'name' => mb_substr($libName, 0, 100),
                'data' => json_encode($libSection, JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            adminLog('page', 'edit', '保存可复用块：' . $libName);
            success(['id' => (int) $libId]);
        }
        if ($libAction === 'lib_list') {
            $rows = db()->fetchAll(
                'SELECT id, name, updated_at FROM ' . DB_PREFIX . 'blocks_library ORDER BY id DESC'
            );
            success(['items' => $rows]);
        }
        if ($libAction === 'lib_get') {
            $row = db()->fetchOne(
                'SELECT id, name, data FROM ' . DB_PREFIX . 'blocks_library WHERE id = ?',
                [(int) ($_POST['lib_id'] ?? 0)]
            );
            if (!$row) {
                error('块不存在（可能已被删除）');
            }
            success(['item' => $row]);
        }
        if ($libAction === 'lib_delete') {
            $libId = (int) ($_POST['lib_id'] ?? 0);
            db()->delete(DB_PREFIX . 'blocks_library', 'id = ?', [$libId]);
            adminLog('page', 'delete', '删除可复用块 #' . $libId);
            success();
        }
    } catch (\Throwable $e) {
        error('块库不可用，请先在「系统 → 数据库升级」执行升级');
    }
    error('未知操作');
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $slug = resolveSlug(post('slug'), post('name'), 'channels', $id);

    $postBlocksData = $_POST['blocks_data'] ?? '[]';
    $renderedHtml = renderBlocksToHtml($postBlocksData);

    $channelData = [
        'name' => post('name'),
        'slug' => $slug,
        'description' => post('description'),
        'content' => $renderedHtml,
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
            'content' => $renderedHtml,
            'content_type' => 'blocks',
            'blocks_data' => $postBlocksData,
            'updated_at' => time(),
        ]);
    } else {
        contentModel()->create([
            'channel_id' => $id,
            'lang' => siteLang(),
            'title' => post('name'),
            'content' => $renderedHtml,
            'content_type' => 'blocks',
            'blocks_data' => $postBlocksData,
            'status' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
    adminLog('page', 'edit', '排版编辑单页：' . $channelData['name']);
    success();
}

$pageTitle = '排版编辑 - ' . $page['name'];
$currentMenu = 'page';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6 flex items-center justify-between">
    <a href="/admin/page.php" class="text-gray-500 hover:text-primary inline-flex items-center gap-1">
        <i class="ti ti-chevron-left text-base"></i>
        <?php echo __('page_back_to_list'); ?>
    </a>
    <a href="/admin/page_edit.php?id=<?php echo $id; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1 cursor-pointer transition">
        <i class="ti ti-pencil text-base"></i>
        <?php echo __('page_switch_simple'); ?>
    </a>
</div>

<?php $childEditBase = '/admin/page_edit_advance.php'; require ROOT_PATH . '/admin/includes/parent_page_notice.php'; ?>

<form id="editForm" class="space-y-6" x-data="pageBuilder()" x-init="init()">
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
                           class="w-full border rounded px-4 py-2" placeholder="如：about-us，留空自动生成">
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
                        <i class="ti ti-upload text-base"></i>
                        <?php echo __('admin_upload_image'); ?></button>
                    <button type="button" onclick="pickImageFromMedia()"
                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded text-sm inline-flex items-center gap-1">
                        <i class="ti ti-photo text-base"></i>
                        <?php echo __("admin_media_library"); ?></button>
                </div>
                <?php if ($page['image']): ?>
                <img src="<?php echo e($page['image']); ?>" id="imagePreview" class="h-24 mt-2 rounded">
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 排版编辑器 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b flex items-center justify-between gap-3">
            <h2 class="font-bold text-gray-800"><?php echo __('label_layout_content'); ?></h2>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-400 hidden md:inline">拖拽区块排序 / 每个区块可设置列数、背景、间距</span>
                <button type="button" @click="togglePreview()"
                        class="text-sm border px-3 py-1.5 rounded inline-flex items-center gap-1 cursor-pointer transition whitespace-nowrap"
                        :class="showPreview ? 'border-primary bg-primary text-white' : 'border-gray-300 text-gray-600 hover:border-primary hover:text-primary'">
                    <i class="ti ti-eye text-base"></i>实时预览
                </button>
                <button type="button" @click="showPresets = true"
                        class="text-sm border border-primary text-primary hover:bg-primary hover:text-white px-3 py-1.5 rounded inline-flex items-center gap-1 cursor-pointer transition whitespace-nowrap">
                    <i class="ti ti-layout-collage text-base"></i>预设库
                </button>
            </div>
        </div>
        <div class="p-6">
            <template x-if="sections.length === 0">
                <div class="text-center py-12 text-gray-400">
                    <i class="ti ti-lock text-5xl mx-auto mb-3 text-gray-300"></i>
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
                                    <i class="ti ti-menu-2 text-base"></i>
                                </span>
                                <span class="text-sm font-medium text-gray-600" x-text="'区块 ' + (si + 1)"></span>
                                <span class="text-xs text-gray-400" x-show="!section.library_id" x-text="section.columns.length + ' 列'"></span>
                                <template x-if="section.library_id">
                                    <span class="text-xs text-purple-600 bg-purple-50 px-1.5 py-0.5 rounded inline-flex items-center gap-1">
                                        <i class="ti ti-link"></i><span x-text="'引用：' + (section.library_name || ('#' + section.library_id))"></span>
                                    </span>
                                </template>
                                <template x-if="section.settings.bg_color">
                                    <span class="inline-block w-3 h-3 rounded-full border" :style="'background:' + section.settings.bg_color"></span>
                                </template>
                            </div>
                            <div class="flex items-center gap-1">
                                <button type="button" @click="moveSection(si, -1)" :disabled="si === 0"
                                        class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="上移">
                                    <i class="ti ti-chevron-up text-base"></i>
                                </button>
                                <button type="button" @click="moveSection(si, 1)" :disabled="si === sections.length - 1"
                                        class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="下移">
                                    <i class="ti ti-chevron-down text-base"></i>
                                </button>
                                <template x-if="!section.library_id">
                                    <button type="button" @click="saveToLibrary(si)"
                                            class="p-1 text-gray-400 hover:text-purple-600 cursor-pointer" title="存为可复用块">
                                        <i class="ti ti-bookmark-plus text-base"></i>
                                    </button>
                                </template>
                                <template x-if="section.library_id">
                                    <button type="button" @click="detachLibRef(si)"
                                            class="p-1 text-gray-400 hover:text-purple-600 cursor-pointer" title="转为独立副本（不再跟随块库更新）">
                                        <i class="ti ti-unlink text-base"></i>
                                    </button>
                                </template>
                                <template x-if="!section.library_id">
                                    <button type="button" @click="openSettings(si)"
                                            class="p-1 text-gray-400 hover:text-gray-600 cursor-pointer" title="设置">
                                        <i class="ti ti-settings text-base"></i>
                                    </button>
                                </template>
                                <button type="button" @click="removeSection(si)"
                                        class="p-1 text-red-400 hover:text-red-600 cursor-pointer" title="<?php echo __('admin_delete'); ?>">
                                    <i class="ti ti-trash text-base"></i>
                                </button>
                            </div>
                        </div>
                        <!-- 引用块：内容在块库中维护，此处只显示占位 -->
                        <template x-if="section.library_id">
                            <div class="p-4">
                                <div class="text-center py-6 text-sm text-purple-500 bg-purple-50/50 rounded border border-dashed border-purple-200">
                                    <i class="ti ti-library text-2xl block mb-1"></i>
                                    引用块内容在块库中统一维护，修改后所有引用页面同步生效
                                    <div class="text-xs text-purple-400 mt-1">如需单独编辑此页版本，点右上 <i class="ti ti-unlink"></i> 转为独立副本</div>
                                </div>
                            </div>
                        </template>
                        <!-- 列内容 -->
                        <div class="p-4" x-show="!section.library_id">
                            <div class="grid gap-4" :class="section.columns.length > 1 ? 'grid-cols-' + section.columns.length : ''">
                                <template x-for="(col, ci) in section.columns" :key="col.id">
                                    <div class="border rounded-lg p-3 min-h-[100px]"
                                         :class="(section.settings && section.settings.col_card) ? 'border-gray-200 bg-white shadow-sm text-center' : 'border-dashed border-gray-300'"
                                         :data-section-index="si" :data-column-index="ci" data-sortable-elements>
                                        <!-- 元素列表 -->
                                        <template x-for="(el, ei) in col.elements" :key="el.id">
                                            <div class="mb-2 bg-white border rounded p-3 group/el relative hover:border-blue-300 transition block-element">
                                                <!-- 元素工具栏 -->
                                                <div class="absolute -top-2 -right-2 flex gap-0.5 z-10 bg-white border rounded shadow-sm px-1 py-0.5">
                                                    <span class="element-drag-handle cursor-grab p-1 text-gray-400 hover:text-gray-600" title="拖拽排序">
                                                        <i class="ti ti-menu-2 text-base"></i>
                                                    </span>
                                                    <button type="button" @click="moveElement(si,ci,ei,-1)" :disabled="ei===0"
                                                            class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="上移">
                                                        <i class="ti ti-chevron-up text-base"></i>
                                                    </button>
                                                    <button type="button" @click="moveElement(si,ci,ei,1)" :disabled="ei===col.elements.length-1"
                                                            class="p-1 text-gray-400 hover:text-gray-600 disabled:opacity-30 cursor-pointer" title="下移">
                                                        <i class="ti ti-chevron-down text-base"></i>
                                                    </button>
                                                    <button type="button" @click="removeElement(si,ci,ei)"
                                                            class="p-1 text-red-400 hover:text-red-600 cursor-pointer" title="<?php echo __('admin_delete'); ?>">
                                                        <i class="ti ti-x text-base"></i>
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
                                                            <template x-for="ic in ['star','heart','circle-check','phone','mail','map-pin','clock','shield','bolt','award','world','users','home','settings','camera','bell','bookmark','calendar','folder','gift','link','lock','search','tag','trending-up','thumb-up','eye','download','upload','share','code','coffee','feather','flag','info-circle','lifebuoy','microphone','device-desktop','music','package','pencil','printer','send','server','mood-smile','sun','target','terminal','truck','device-tv','umbrella','wifi']">
                                                                <button type="button" @click="el.data.icon = ic"
                                                                        class="w-8 h-8 flex items-center justify-center border rounded text-gray-600 hover:bg-primary hover:text-white transition cursor-pointer"
                                                                        :class="el.data.icon === ic ? 'bg-primary text-white border-primary' : 'bg-white'">
                                                                    <i class="ti text-base" :class="'ti-' + ic"></i>
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

                                                <!-- 间距（支持响应式三档） -->
                                                <template x-if="el.type === 'spacer'">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded">间距</span>
                                                        <select x-effect="$el.value = respGet(el, 'size', 'md')"
                                                                @change="respSet(el, 'size', $event.target.value, 'md')"
                                                                class="border rounded px-2 py-1 text-sm">
                                                            <option value="sm">小 (16px)</option>
                                                            <option value="md">中 (32px)</option>
                                                            <option value="lg">大 (64px)</option>
                                                            <option value="xl">超大 (96px)</option>
                                                        </select>
                                                        <div class="flex gap-0.5">
                                                            <button type="button" @click="setRespDev(el, 'd')" title="桌面"
                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                    :class="respTab(el) === 'd' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-desktop"></i></button>
                                                            <button type="button" @click="setRespDev(el, 't')" title="平板"
                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                    :class="respTab(el) === 't' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-tablet"></i></button>
                                                            <button type="button" @click="setRespDev(el, 'm')" title="手机"
                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                    :class="respTab(el) === 'm' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-mobile"></i></button>
                                                        </div>
                                                        <span x-show="respIsSplit(el, 'size')" class="text-[10px] text-primary bg-blue-50 px-1 py-0.5 rounded">已分档</span>
                                                    </div>
                                                </template>

                                                <!-- 动态列表 -->
                                                <template x-if="el.type === 'list-dynamic'">
                                                    <div class="space-y-2">
                                                        <div class="flex items-center gap-2">
                                                            <span class="text-xs text-primary bg-blue-50 px-1.5 py-0.5 rounded">动态列表</span>
                                                            <span class="text-xs text-gray-400">发布后按栏目/模型拉实时数据</span>
                                                        </div>
                                                        <div class="grid grid-cols-2 gap-2">
                                                            <label class="text-xs text-gray-500 block">类型
                                                                <input x-model="el.data.source_type" placeholder="article/case/product/模型key" class="w-full border rounded px-2 py-1 text-sm">
                                                            </label>
                                                            <label class="text-xs text-gray-500 block">栏目 slug/id
                                                                <input x-model="el.data.cat" placeholder="留空=全部" class="w-full border rounded px-2 py-1 text-sm">
                                                            </label>
                                                            <label class="text-xs text-gray-500 block">数量
                                                                <input type="number" x-model="el.data.limit" class="w-full border rounded px-2 py-1 text-sm">
                                                            </label>
                                                            <label class="text-xs text-gray-500 block">网格列数 (1-4)
                                                                <input type="number" min="1" max="4" x-model="el.data.columns" class="w-full border rounded px-2 py-1 text-sm">
                                                            </label>
                                                        </div>
                                                        <div class="flex flex-wrap gap-3 text-xs text-gray-600 pt-1">
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="el.data.show_image"> 封面</label>
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="el.data.show_title"> 标题</label>
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="el.data.show_summary"> 摘要</label>
                                                            <label class="inline-flex items-center gap-1"><input type="checkbox" x-model="el.data.show_date"> 日期</label>
                                                        </div>
                                                    </div>
                                                </template>

                                                <!-- 轮播图 -->
                                                <template x-if="el.type === 'banner'">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-xs text-primary bg-blue-50 px-1.5 py-0.5 rounded">轮播图</span>
                                                        <input x-model="el.data.group" placeholder="轮播分组标识 (banner group)" class="flex-1 border rounded px-2 py-1 text-sm">
                                                    </div>
                                                </template>

                                                <!-- 导航菜单 -->
                                                <template x-if="el.type === 'nav'">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="text-xs text-primary bg-blue-50 px-1.5 py-0.5 rounded">导航</span>
                                                        <input x-model="el.data.parent" placeholder="父栏目 slug/id，空=顶级" class="flex-1 border rounded px-2 py-1 text-sm">
                                                        <label class="inline-flex items-center gap-1 text-xs text-gray-600"><input type="checkbox" x-model="el.data.nav_only"> 仅导航栏目</label>
                                                    </div>
                                                </template>

                                                <!-- 通用 schema 表单：无手写 UI 的元素（新/插件元素）据 controls() 自动生成设置 -->
                                                <template x-if="!hasCustomUI(el.type)">
                                                    <div class="space-y-2">
                                                        <span class="text-xs text-gray-400 bg-gray-100 px-1.5 py-0.5 rounded inline-block" x-text="elementLabel(el.type)"></span>
                                                        <template x-for="ctrl in elementControls(el.type)" :key="ctrl.key">
                                                            <div>
                                                                <template x-if="ctrl.type !== 'checkbox'">
                                                                    <label class="text-xs text-gray-500 block mb-0.5" x-text="ctrl.label"></label>
                                                                </template>
                                                                <template x-if="ctrl.type === 'text'">
                                                                    <input type="text" x-model="el.data[ctrl.key]" :placeholder="ctrl.placeholder||''" class="w-full border rounded px-2 py-1 text-sm">
                                                                </template>
                                                                <template x-if="ctrl.type === 'textarea'">
                                                                    <textarea x-model="el.data[ctrl.key]" :placeholder="ctrl.placeholder||''" :rows="ctrl.rows||3" class="w-full border rounded px-2 py-1 text-sm"></textarea>
                                                                </template>
                                                                <template x-if="ctrl.type === 'number'">
                                                                    <input type="number" x-model="el.data[ctrl.key]" :min="ctrl.min" :max="ctrl.max" class="w-full border rounded px-2 py-1 text-sm">
                                                                </template>
                                                                <template x-if="ctrl.type === 'select' && !ctrl.responsive">
                                                                    <select x-model="el.data[ctrl.key]" class="w-full border rounded px-2 py-1 text-sm">
                                                                        <template x-for="(lbl,val) in ctrl.options" :key="val"><option :value="val" x-text="lbl"></option></template>
                                                                    </select>
                                                                </template>
                                                                <!-- responsive 控件：可按 桌面/平板/手机 分档存值（schema 加 'responsive'=>true 即启用） -->
                                                                <template x-if="ctrl.type === 'select' && ctrl.responsive">
                                                                    <div class="flex items-center gap-1.5">
                                                                        <select x-effect="$el.value = respGet(el, ctrl.key, ctrl.default || '')"
                                                                                @change="respSet(el, ctrl.key, $event.target.value, ctrl.default || '')"
                                                                                class="flex-1 border rounded px-2 py-1 text-sm">
                                                                            <template x-for="(lbl,val) in ctrl.options" :key="val"><option :value="val" x-text="lbl"></option></template>
                                                                        </select>
                                                                        <div class="flex gap-0.5">
                                                                            <button type="button" @click="setRespDev(el, 'd')" title="桌面"
                                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                                    :class="respTab(el) === 'd' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-desktop"></i></button>
                                                                            <button type="button" @click="setRespDev(el, 't')" title="平板"
                                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                                    :class="respTab(el) === 't' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-tablet"></i></button>
                                                                            <button type="button" @click="setRespDev(el, 'm')" title="手机"
                                                                                    class="px-1.5 py-0.5 border rounded text-xs cursor-pointer"
                                                                                    :class="respTab(el) === 'm' ? 'bg-primary text-white border-primary' : 'text-gray-400 hover:text-gray-600'"><i class="ti ti-device-mobile"></i></button>
                                                                        </div>
                                                                        <span x-show="respIsSplit(el, ctrl.key)" class="text-[10px] text-primary bg-blue-50 px-1 py-0.5 rounded">已分档</span>
                                                                    </div>
                                                                </template>
                                                                <template x-if="ctrl.type === 'color'">
                                                                    <input type="color" x-model="el.data[ctrl.key]" class="border rounded h-8 w-16">
                                                                </template>
                                                                <template x-if="ctrl.type === 'checkbox'">
                                                                    <label class="inline-flex items-center gap-1 text-sm text-gray-600"><input type="checkbox" x-model="el.data[ctrl.key]"> <span x-text="ctrl.label"></span></label>
                                                                </template>
                                                            </div>
                                                        </template>
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
                                            <!-- palette 由 BuilderRegistry 元数据生成，按分类分组；加元素类即自动出现 -->
                                            <div x-show="open" @click.away="open = false" x-cloak
                                                 class="absolute z-10 mt-1 bg-white border rounded-lg shadow-lg py-1 w-40 left-1/2 -translate-x-1/2 max-h-80 overflow-y-auto">
                                                <template x-for="(els, cat) in elementsByCategory()" :key="cat">
                                                    <div>
                                                        <div class="px-3 py-1 text-[10px] text-gray-400 uppercase" x-text="categoryLabel(cat)"></div>
                                                        <template x-for="meta in els" :key="meta.type">
                                                            <button type="button" @click="addElement(si,ci,meta.type); open=false"
                                                                    :class="meta.dynamic ? 'text-primary hover:bg-blue-50' : 'hover:bg-gray-50'"
                                                                    class="block w-full text-left px-3 py-2 text-sm cursor-pointer" x-text="meta.label"></button>
                                                        </template>
                                                    </div>
                                                </template>
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
                <button type="button" @click="showPicker = !showPicker; showPicker && libRefresh()"
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
                    <!-- 从块库插入（P2 可复用块）：引用=改库全站生效；副本=独立编辑 -->
                    <div class="mt-4 border-t pt-3">
                        <p class="text-sm text-gray-600 mb-2">或从块库插入 <span class="text-xs text-gray-400">引用块随块库更新全站生效；副本插入后独立编辑</span></p>
                        <template x-if="libItems.length === 0">
                            <p class="text-xs text-gray-400">块库为空——在任意区块工具栏点 <i class="ti ti-bookmark-plus"></i> 可把该区块存入块库</p>
                        </template>
                        <div class="space-y-1">
                            <template x-for="item in libItems" :key="item.id">
                                <div class="flex items-center gap-2 bg-white border rounded px-3 py-1.5">
                                    <i class="ti ti-library text-purple-400"></i>
                                    <span class="text-sm text-gray-700 flex-1" x-text="item.name"></span>
                                    <button type="button" @click="insertLibRef(item); showPicker = false"
                                            class="text-xs text-purple-600 hover:underline cursor-pointer">引用插入</button>
                                    <button type="button" @click="insertLibCopy(item); showPicker = false"
                                            class="text-xs text-primary hover:underline cursor-pointer">副本插入</button>
                                    <button type="button" @click="libDelete(item)"
                                            class="text-xs text-red-400 hover:text-red-600 cursor-pointer" title="从块库删除">
                                        <i class="ti ti-trash"></i>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 实时预览面板 -->
    <div x-show="showPreview" x-cloak class="bg-white rounded-lg shadow">
        <div class="px-6 py-3 border-b flex items-center justify-between gap-3">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2"><i class="ti ti-eye"></i>实时预览</h2>
            <div class="flex items-center gap-2">
                <template x-for="d in previewDevices" :key="d.key">
                    <button type="button" @click="previewDevice = d.key"
                            class="text-xs px-2.5 py-1 rounded border cursor-pointer transition inline-flex items-center gap-1"
                            :class="previewDevice === d.key ? 'border-primary text-primary' : 'border-gray-200 text-gray-400 hover:text-gray-600'">
                        <i class="ti" :class="d.icon"></i><span x-text="d.label"></span>
                    </button>
                </template>
                <span class="text-xs text-gray-300" x-show="previewLoading">刷新中…</span>
            </div>
        </div>
        <div class="p-4 bg-gray-100 flex justify-center overflow-auto">
            <iframe x-ref="previewFrame" class="bg-white shadow-sm border-0 transition-all duration-300"
                    :style="'width:' + previewWidth() + ';height:70vh'"></iframe>
        </div>
    </div>

    <!-- 预设库弹窗（P2）：区块预设 + 整页模板一键插入；插件可用 builder_presets 过滤器扩展 -->
    <div x-show="showPresets" x-cloak @click.self="showPresets = false" @keydown.escape.window="showPresets = false"
         class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl mx-4 max-h-[80vh] flex flex-col">
            <div class="px-6 py-4 border-b flex items-center justify-between shrink-0">
                <h3 class="font-bold text-gray-800 inline-flex items-center gap-2"><i class="ti ti-layout-collage"></i>预设库</h3>
                <button type="button" @click="showPresets = false" class="text-gray-400 hover:text-gray-600 cursor-pointer">
                    <i class="ti ti-x text-xl"></i>
                </button>
            </div>
            <div class="p-6 overflow-y-auto space-y-6">
                <div>
                    <p class="text-xs text-gray-400 uppercase tracking-wide mb-2">区块预设 · 插入单个区块</p>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                        <template x-for="p in presetSections()" :key="p.key">
                            <button type="button" @click="insertPreset(p)"
                                    class="border rounded-lg p-3 text-left hover:border-primary hover:bg-blue-50/40 transition cursor-pointer">
                                <i class="ti text-xl text-primary" :class="'ti-' + (p.icon || 'square')"></i>
                                <div class="text-sm font-medium text-gray-700 mt-1" x-text="p.label"></div>
                                <div class="text-xs text-gray-400 mt-0.5" x-text="p.desc || ''"></div>
                            </button>
                        </template>
                    </div>
                </div>
                <div>
                    <p class="text-xs text-gray-400 uppercase tracking-wide mb-2">整页模板 · 插入整套区块</p>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                        <template x-for="p in presetPages()" :key="p.key">
                            <button type="button" @click="insertPreset(p)"
                                    class="border rounded-lg p-3 text-left hover:border-primary hover:bg-blue-50/40 transition cursor-pointer">
                                <i class="ti text-xl text-primary" :class="'ti-' + (p.icon || 'file')"></i>
                                <div class="text-sm font-medium text-gray-700 mt-1" x-text="p.label"></div>
                                <div class="text-xs text-gray-400 mt-0.5" x-text="p.desc || ''"></div>
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- hidden -->
    <input type="hidden" name="blocks_data" :value="JSON.stringify(sections)">

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

    <div class="bg-white rounded-lg shadow p-6 flex items-center gap-4">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1 cursor-pointer">
            <i class="ti ti-check text-base"></i>
            <?php echo __("btn_save"); ?>
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
                <i class="ti ti-x text-xl"></i>
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
            <div class="flex items-center justify-between gap-2">
                <label class="text-sm text-gray-700">设备分档 <span class="text-xs text-gray-400">内边距/列间距可按设备分别设置</span></label>
                <div class="flex gap-1">
                    <button type="button" onclick="setSettingDevice('d')" data-dev="d" class="dev-btn px-2 py-1 border rounded text-xs cursor-pointer hover:bg-gray-50 inline-flex items-center gap-1"><i class="ti ti-device-desktop"></i>桌面</button>
                    <button type="button" onclick="setSettingDevice('t')" data-dev="t" class="dev-btn px-2 py-1 border rounded text-xs cursor-pointer hover:bg-gray-50 inline-flex items-center gap-1"><i class="ti ti-device-tablet"></i>平板</button>
                    <button type="button" onclick="setSettingDevice('m')" data-dev="m" class="dev-btn px-2 py-1 border rounded text-xs cursor-pointer hover:bg-gray-50 inline-flex items-center gap-1"><i class="ti ti-device-mobile"></i>手机</button>
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
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="settingColCard" class="rounded border-gray-300 text-primary focus:ring-primary">
                    <span class="text-sm text-gray-700">每列显示为卡片（白底/边框/阴影，适合多列特性区）</span>
                </label>
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
                    <button type="button" onclick="pickSectionBgImage()" class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm cursor-pointer" title="从媒体库选择"><?php echo __('admin_media_library'); ?></button>
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
                <i class="ti ti-x text-xl"></i>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto p-6">
            <div id="modal-toolbar" class="border border-b-0 rounded-t-lg bg-gray-50"></div>
            <div id="modal-editor" class="border rounded-b-lg" style="min-height: 300px;"></div>
        </div>
        <div class="px-6 py-3 border-t flex justify-end gap-2 shrink-0">
            <button type="button" onclick="closeTextEditor()" class="px-4 py-2 border rounded hover:bg-gray-100 text-sm cursor-pointer"><?php echo __('admin_cancel'); ?></button>
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
            showMessage('<?php echo __('admin_success'); ?>');
        } else { showMessage(data.msg, 'error'); }
    } catch (err) { showMessage('<?php echo __('admin_fail'); ?>', 'error'); }
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

// 响应式三档：设置值标量=全设备统一，{d,t,m}=按 桌面/平板/手机 分档（渲染出 md:/lg: 前缀）
var _settingDev = 'd';
var _respVals = { padding: null, gap: null };
function normResp(v, dflt) {
    if (v && typeof v === 'object') {
        var d = v.d || dflt;
        return { d: d, t: v.t || d, m: v.m || v.t || d };
    }
    v = v || dflt;
    return { d: v, t: v, m: v };
}
function collapseResp(v) {
    return (v.d === v.t && v.t === v.m) ? v.d : v;
}
function _commitSettingDevice() {
    if (!_respVals.padding) return;
    _respVals.padding[_settingDev] = document.getElementById('settingPadding').value;
    _respVals.gap[_settingDev] = document.getElementById('settingGap').value;
}
function setSettingDevice(dev, skipCommit) {
    if (!skipCommit) _commitSettingDevice();
    _settingDev = dev;
    document.querySelectorAll('.dev-btn').forEach(function(b) {
        var on = b.dataset.dev === dev;
        b.classList.toggle('bg-primary', on);
        b.classList.toggle('text-white', on);
        b.classList.toggle('border-primary', on);
    });
    document.getElementById('settingPadding').value = _respVals.padding[dev];
    document.getElementById('settingGap').value = _respVals.gap[dev];
}
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
            showMessage('<?php echo __('admin_success'); ?>');
        } else { showMessage(data.msg || '上传失败', 'error'); }
    } catch (e) { showMessage('<?php echo __('admin_fail'); ?>', 'error'); }
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
    _commitSettingDevice();
    section.settings.padding = collapseResp(_respVals.padding);
    section.settings.max_width = document.getElementById('settingMaxWidth').value;
    section.settings.bg_color = document.getElementById('settingBgColorText').value.trim();
    section.settings.bg_opacity = parseInt(document.getElementById('settingBgOpacity').value);
    section.settings.bg_image = document.getElementById('settingBgImage').value.trim();
    section.settings.gap = collapseResp(_respVals.gap);
    section.settings.col_card = document.getElementById('settingColCard').checked;
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
// 预设库（区块预设 + 整页模板，见 includes/builder/presets.php；插件可用 builder_presets 过滤器扩展）
var BUILDER_PRESETS = ' . json_encode(builderPresets(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . ';
// 元素元数据（由 BuilderRegistry 生成）：palette + 新元素设置表单据此驱动，加元素即插即用
var BUILDER_ELEMENTS = ' . json_encode(BuilderRegistry::meta(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';
// 已有手写设置 UI 的元素（保留其精细编辑器）；其余（新/插件元素）走通用 schema 表单
var BUILDER_CUSTOM_UI = ["heading","text","image","button","icon","divider","code","spacer","list-dynamic","banner","nav"];
function pageBuilder() {
    return {
        sections: ' . $initBlocks . ',

        // === 实时预览 ===
        showPreview: false,
        previewLoading: false,
        previewDevice: "desktop",
        previewDevices: [
            { key: "desktop", label: "桌面", icon: "ti-device-desktop" },
            { key: "tablet", label: "平板", icon: "ti-device-tablet" },
            { key: "mobile", label: "手机", icon: "ti-device-mobile" }
        ],
        _previewTimer: null,
        previewWidth() {
            return ({ desktop: "100%", tablet: "768px", mobile: "390px" })[this.previewDevice] || "100%";
        },
        togglePreview() {
            this.showPreview = !this.showPreview;
            if (this.showPreview) { var self = this; this.$nextTick(function() { self.refreshPreview(); }); }
        },
        schedulePreview() {
            if (!this.showPreview) return;
            var self = this;
            clearTimeout(this._previewTimer);
            this._previewTimer = setTimeout(function() { self.refreshPreview(); }, 600);
        },
        refreshPreview() {
            var frame = this.$refs.previewFrame;
            if (!frame) return;
            this.previewLoading = true;
            var self = this;
            var body = new URLSearchParams();
            body.set("action", "preview");
            body.set("blocks_data", JSON.stringify(this.sections));
            // 传 URLSearchParams 实例（勿 toString）：footer 的 fetch 包装器只给实例自动附加 CSRF _token
            fetch(window.location.href, {
                method: "POST",
                body: body
            }).then(function(r) { return r.text(); })
              .then(function(html) { frame.srcdoc = html; })
              .catch(function() {})
              .finally(function() { self.previewLoading = false; });
        },

        uid(prefix) {
            return prefix + "_" + Math.random().toString(36).substr(2, 9);
        },

        // === 元素元数据（schema 驱动） ===
        elementTypes() { return Object.keys(BUILDER_ELEMENTS); },
        elementsByCategory() {
            var groups = {};
            for (var t of Object.keys(BUILDER_ELEMENTS)) {
                var c = BUILDER_ELEMENTS[t].category || "basic";
                (groups[c] = groups[c] || []).push(BUILDER_ELEMENTS[t]);
            }
            return groups;
        },
        categoryLabel(c) {
            return ({ basic: "基础", media: "媒体", layout: "布局", advanced: "高级", dynamic: "动态" })[c] || c;
        },
        hasCustomUI(type) { return BUILDER_CUSTOM_UI.indexOf(type) !== -1; },
        elementControls(type) { return (BUILDER_ELEMENTS[type] || {}).controls || []; },
        elementLabel(type) { return (BUILDER_ELEMENTS[type] || {}).label || type; },

        // === 响应式三档（元素级）：值标量=全设备统一，{d,t,m}=分档；respDev 记录每个元素当前编辑档 ===
        respDev: {},
        respTab(el) { return this.respDev[el.id] || "d"; },
        setRespDev(el, dev) { this.respDev[el.id] = dev; },
        respIsSplit(el, key) { var v = el.data[key]; return !!v && typeof v === "object"; },
        respGet(el, key, dflt) {
            var v = el.data[key];
            if (v && typeof v === "object") return v[this.respTab(el)] || v.d || dflt;
            return v || dflt;
        },
        respSet(el, key, val, dflt) {
            var v = el.data[key];
            if (!v || typeof v !== "object") { v = { d: v || dflt, t: v || dflt, m: v || dflt }; }
            else { v = { d: v.d || dflt, t: v.t || dflt, m: v.m || dflt }; }
            v[this.respTab(el)] = val;
            // 全档一致折叠回标量，保持旧数据形态与渲染输出
            el.data[key] = (v.d === v.t && v.t === v.m) ? v.d : v;
        },

        // === 可复用块库（P2）：引用块 {library_id} 渲染时展开，改库一处全站生效 ===
        libItems: [],
        libPost(params) {
            var body = new URLSearchParams();
            for (var k in params) body.set(k, params[k]);
            return fetch(window.location.href, { method: "POST", body: body })
                .then(function(r) { return safeJson(r); });
        },
        libRefresh() {
            var self = this;
            this.libPost({ action: "lib_list" }).then(function(res) {
                if (res.code === 0) self.libItems = (res.data && res.data.items) || [];
            }).catch(function() {});
        },
        saveToLibrary(si) {
            var name = prompt("块名称（存入块库后可在其他页面复用）：");
            if (!name) return;
            var self = this;
            this.libPost({
                action: "lib_save",
                lib_name: name,
                section_data: JSON.stringify(this.sections[si])
            }).then(function(res) {
                if (res.code === 0) { showMessage("已存入块库"); self.libRefresh(); }
                else showMessage(res.msg, "error");
            }).catch(function() { showMessage("请求失败", "error"); });
        },
        insertLibRef(item) {
            this.sections.push({ id: this.uid("s"), library_id: item.id, library_name: item.name, settings: {}, columns: [] });
        },
        insertLibCopy(item) {
            var self = this;
            this.libPost({ action: "lib_get", lib_id: item.id }).then(function(res) {
                if (res.code !== 0) { showMessage(res.msg, "error"); return; }
                var sec = JSON.parse(res.data.item.data);
                self.sections.push(self.freshSection(sec));
                self.$nextTick(function() { self.initSortable(); });
            }).catch(function() { showMessage("请求失败", "error"); });
        },
        detachLibRef(si) {
            var s = this.sections[si];
            if (!s.library_id) return;
            var self = this;
            this.libPost({ action: "lib_get", lib_id: s.library_id }).then(function(res) {
                if (res.code !== 0) { showMessage(res.msg, "error"); return; }
                var sec = JSON.parse(res.data.item.data);
                self.sections.splice(si, 1, self.freshSection(sec));
                self.$nextTick(function() { self.initSortable(); });
            }).catch(function() { showMessage("请求失败", "error"); });
        },
        libDelete(item) {
            if (!confirm("从块库删除「" + item.name + "」？已引用它的页面该区块将不再显示")) return;
            var self = this;
            this.libPost({ action: "lib_delete", lib_id: item.id }).then(function(res) {
                if (res.code === 0) { showMessage("已删除"); self.libRefresh(); }
                else showMessage(res.msg, "error");
            }).catch(function() { showMessage("请求失败", "error"); });
        },

        // 深拷贝 section 并重生成各级 id（块库副本/模板插入共用）
        freshSection(s) {
            var self = this;
            s = JSON.parse(JSON.stringify(s));
            return {
                id: self.uid("s"),
                settings: s.settings || {},
                columns: (s.columns || []).map(function(c) {
                    return {
                        id: self.uid("c"),
                        elements: (c.elements || []).map(function(e) {
                            return { id: self.uid("e"), type: e.type, data: e.data };
                        })
                    };
                })
            };
        },

        init() {
            var self = this;
            this.$nextTick(function() { self.initSortable(); });
            // 区块变化 → 防抖刷新预览
            this.$watch("sections", function() { self.schedulePreview(); });
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

        // === 预设库：区块预设/整页模板一键插入（重生成各级 id，避免与现有内容 key 冲突） ===
        showPresets: false,
        presetSections() { return BUILDER_PRESETS.sections || []; },
        presetPages() { return BUILDER_PRESETS.pages || []; },
        insertPreset(p) {
            var tpl = p && p.sections;
            if (!tpl || !tpl.length) return;
            var self = this;
            var fresh = tpl.map(function(s) { return self.freshSection(s); });
            if (this.sections.length > 0 && fresh.length > 1) {
                if (!confirm("将整页模板追加到当前内容末尾？")) return;
            }
            this.sections = this.sections.concat(fresh);
            this.showPresets = false;
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
            // 响应式三档：标量→全档同值展开；保存时全档一致会折叠回标量
            _respVals.padding = normResp(s.padding, "md");
            _respVals.gap = normResp(s.gap, "lg");
            setSettingDevice("d", true);
            document.getElementById("settingMaxWidth").value = s.max_width || "default";
            document.getElementById("settingBgColorText").value = s.bg_color || "";
            document.getElementById("settingBgColor").value = s.bg_color || "#ffffff";
            document.getElementById("settingBgImage").value = s.bg_image || "";
            var opacity = s.bg_opacity !== undefined ? s.bg_opacity : 100;
            document.getElementById("settingBgOpacity").value = opacity;
            document.getElementById("settingBgOpacityVal").textContent = opacity + "%";
            document.getElementById("settingColCard").checked = !!s.col_card;
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
            // 默认 data 来自元素 schema（BuilderRegistry），新增元素无需在此登记
            var meta = BUILDER_ELEMENTS[type] || {};
            var data = JSON.parse(JSON.stringify(meta.defaults || {}));
            this.sections[si].columns[ci].elements.push({
                id: this.uid("e"),
                type: type,
                data: data
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
