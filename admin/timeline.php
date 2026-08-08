<?php
/**
 * YikaiCMS - 发展历程管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/HtmlCache.php';
require_once ROOT_PATH . '/includes/blocks/timeline.php';

checkLogin();
requirePermission('edit_timeline');

// 图标选项
$iconOptions = [
    '' => __('tl_icon_none'),
    'flag' => __('tl_icon_flag'),
    'rocket' => __('tl_icon_rocket'),
    'award' => __('tl_icon_award'),
    'users' => __('icon_users'),
    'box' => __('admin_product'),
    'trending-up' => __('tl_icon_growth'),
    'map' => __('tl_icon_map'),
    'handshake' => __('tl_icon_handshake'),
    'building' => __('tl_icon_building'),
    'star' => __('icon_star'),
    'heart' => __('icon_heart'),
    'zap' => __('icon_bolt'),
    'target' => __('tl_icon_target'),
    'globe' => __('icon_globe'),
];

// 颜色选项
$colorOptions = [
    'primary' => __('tl_c_primary'),
    'blue' => __('tl_c_blue'),
    'green' => __('tl_c_green'),
    'yellow' => __('tl_c_yellow'),
    'red' => __('tl_c_red'),
    'purple' => __('tl_c_purple'),
    'cyan' => __('tl_c_cyan'),
    'indigo' => __('tl_c_indigo'),
    'pink' => __('tl_c_pink'),
    'gray' => __('tl_c_gray'),
];

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'year' => postInt('year'),
            'month' => postInt('month'),
            'day' => postInt('day'),
            'title' => post('title'),
            'content' => post('content'),
            'image' => post('image'),
            'icon' => post('icon'),
            'color' => post('color', 'primary'),
            'sort_order' => postInt('sort_order'),
            'status' => postInt('status', 1),
            'updated_at' => time(),
        ];

        if (empty($data['title'])) {
            error(__('tl_title_required'));
        }

        if ($data['year'] < 1900 || $data['year'] > 2100) {
            error(__('tl_year_invalid'));
        }

        if ($id > 0) {
            timelineModel()->updateById($id, $data);
            adminLog('timeline', 'update', "更新时间线ID: $id");
        } else {
            $data['created_at'] = time();
            $id = timelineModel()->create($data);
            adminLog('timeline', 'create', "创建时间线ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        requirePermission('delete_timeline');
        $id = postInt('id');
        timelineModel()->deleteById($id);
        adminLog('timeline', 'delete', "删除时间线ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = timelineModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'sort') {
        $ids = $_POST['ids'] ?? [];
        timelineModel()->updateSort($ids);
        success();
    }

    if ($action === 'save_sort_direction') {
        $val = post('timeline_sort') === 'asc' ? 'asc' : 'desc';
        settingModel()->set('timeline_sort', $val);
        HtmlCache::invalidate();
        adminLog('timeline', 'config', "设置时间线排序方向: $val");
        success(['timeline_sort' => $val]);
    }

    if ($action === 'save_layout') {
        $raw = (string)post('timeline_layout');
        $val = in_array($raw, ['vertical', 'horizontal', 'compact'], true) ? $raw : 'vertical';
        settingModel()->set('timeline_layout', $val);
        HtmlCache::invalidate();
        adminLog('timeline', 'config', "设置时间线布局: $val");
        success(['timeline_layout' => $val]);
    }

    if ($action === 'render_preview') {
        $opts = [];
        $rawLayout = (string)post('layout');
        if (in_array($rawLayout, ['vertical', 'horizontal', 'compact'], true)) {
            $opts['layout'] = $rawLayout;
        }
        $rawSort = (string)post('sort');
        if (in_array($rawSort, ['asc', 'desc'], true)) {
            $opts['sort'] = $rawSort;
        }
        // 预览限制 6 条，避免后台拉太多影响首屏
        $opts['limit'] = 6;
        $html = function_exists('timelineBlock') ? timelineBlock($opts) : '';
        success(['html' => $html]);
    }

    exit;
}

// 当前显示设置
$timelineSort   = config('timeline_sort', 'desc');
$timelineLayoutRaw = (string)config('timeline_layout', 'vertical');
$timelineLayout    = in_array($timelineLayoutRaw, ['vertical', 'horizontal', 'compact'], true) ? $timelineLayoutRaw : 'vertical';

// 视图语言
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();

// 获取列表（按 view-lang 过滤）
$timelines = array_values(array_filter(
    timelineModel()->all(),
    fn($t) => ($t['lang'] ?? $_defaultLang) === $_viewLang
));

$pageTitle = __('admin_timeline');
$currentMenu = 'timeline';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('timelines');
require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php echo renderAdminLangSwitcher($_viewLang, __('tl_lang_tip')); ?>

<!-- Swiper 全局预加载（横向布局预览需要，初始即可用） -->
<link rel="stylesheet" href="/assets/swiper/swiper-bundle.min.css">
<script src="/assets/swiper/swiper-bundle.min.js"></script>

<div x-data="{ tab: 'events' }">

<!-- TAB 导航 -->
<div class="bg-white rounded-lg shadow mb-4">
    <div class="flex border-b">
        <button type="button" @click="tab='events'" :class="tab==='events' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
                class="px-6 py-3 border-b-2 font-medium text-sm transition inline-flex items-center gap-2">
            <i class="ti ti-align-justified text-base"></i>
            <?php echo e(__('tl_events')); ?>
        </button>
        <button type="button" @click="tab='settings'" :class="tab==='settings' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
                class="px-6 py-3 border-b-2 font-medium text-sm transition inline-flex items-center gap-2">
            <i class="ti ti-settings text-base"></i>
            <?php echo e(__('tl_display')); ?>
        </button>
    </div>
</div>

<!-- TAB: 事件管理 -->
<div x-show="tab === 'events'">
<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <div class="text-gray-500 text-sm">
            <?php echo e(__('shome_drag_sort')); ?> · <?php echo str_replace(':n', (string) count($timelines), e(__('admin_total_n'))); ?>
        </div>
        <div class="flex gap-2">
            <a href="/history.php" target="_blank" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <i class="ti ti-eye text-base"></i>
                <?php echo e(__('tl_preview')); ?>
            </a>
            <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-plus text-base"></i>
                <?php echo e(__('tl_add_event')); ?>
            </button>
        </div>
    </div>
</div>

<!-- 时间线列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase w-10"><?php echo __('admin_sort_order'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_created_at'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_title_label'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('tl_summary_col')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('timeline_color'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y" id="sortableList">
                <?php foreach ($timelines as $item): ?>
                <tr class="hover:bg-gray-50" data-id="<?php echo $item['id']; ?>">
                    <td class="px-4 py-3">
                        <span class="cursor-move text-gray-400 hover:text-gray-600">&#9776;</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="font-medium text-primary"><?php echo $item['year']; ?></span>
                        <?php if ($item['month'] > 0): ?>
                        <span class="text-gray-500"><?php echo str_replace(':m', (string) $item['month'], e(__('tl_year_month'))); ?></span>
                        <?php else: ?>
                        <span class="text-gray-500"><?php echo e(__('tl_year_unit')); ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <?php if ($item['image']): ?>
                            <img src="<?php echo e($item['image']); ?>" class="w-10 h-10 object-cover rounded">
                            <?php endif; ?>
                            <span class="font-medium"><?php echo e($item['title']); ?></span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-sm max-w-xs truncate">
                        <?php echo e(cutStr($item['content'] ?? '', 50)); ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php $_cp = timelineColorParts((string)($item['color'] ?? 'primary')); ?>
                        <span class="inline-block w-4 h-4 rounded-full <?php echo $_cp['dotClass']; ?>" style="<?php echo $_cp['dotStyle']; ?>"></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? __('admin_show') : __('admin_hide'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-pencil text-sm"></i>
                            <?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteItem(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-trash text-sm"></i>
                            <?php echo __('admin_delete'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($timelines)): ?>
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500"><?php echo e(__('tl_empty')); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /TAB: events -->

<!-- TAB: 显示设置 -->
<div x-show="tab === 'settings'" x-cloak>
    <!--
        本地化样式：编译版 tailwind.css 没把 `peer-checked:` 那批变体打进来，
        所以选中卡片不会高亮。这里改用 [data-active] 属性 + 独立 CSS 钩子，
        不依赖 Tailwind 是否编译过该变体。
    -->
    <style>
        .layout-card { border: 2px solid #e5e7eb; transition: border-color .15s, background .15s, box-shadow .15s; }
        .layout-card[data-active="1"] {
            border-color: var(--color-primary, #3B6CF5);
            background: #eff6ff;
            box-shadow: 0 0 0 1px var(--color-primary, #3B6CF5) inset;
        }
        .layout-card:not([data-active="1"]):hover { border-color: #9ca3af; }
    </style>
    <div class="bg-white rounded-lg shadow p-6 mb-4">
        <div class="flex items-start justify-between mb-1 gap-3">
            <div>
                <h3 class="font-bold text-gray-800 mb-1"><?php echo e(__('tl_layout')); ?></h3>
                <p class="text-sm text-gray-500 mb-4"><?php echo str_replace(':page', '<code>/about/history.html</code>', e(__('tl_layout_desc'))); ?><span class="text-green-600">点击卡片即自动保存</span>，无需点保存按钮。</p>
            </div>
            <button type="button" onclick="saveTimelineLayout(document.querySelector('input[name=&quot;timeline_layout&quot;]:checked')?.value || 'vertical')" class="cursor-pointer text-sm border px-3 py-1.5 rounded hover:bg-gray-50 inline-flex items-center gap-1 whitespace-nowrap flex-shrink-0">
                <i class="ti ti-device-floppy text-base"></i>
                <?php echo e(__('tl_resave')); ?>
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- 竖向双边卡 -->
            <label class="block cursor-pointer group layout-pick" data-layout="vertical">
                <input type="radio" name="timeline_layout" value="vertical" <?php echo $timelineLayout === 'vertical' ? 'checked' : ''; ?> class="peer sr-only" tabindex="-1">
                <div class="layout-card rounded-lg p-4 h-full" data-active="<?php echo $timelineLayout === 'vertical' ? '1' : '0'; ?>">
                    <!-- mini 预览 -->
                    <div class="w-full h-20 bg-gray-50 rounded border flex items-center justify-center relative mb-3">
                        <div class="absolute left-1/2 -translate-x-1/2 top-2 bottom-2 w-0.5 bg-gray-300"></div>
                        <div class="absolute left-3 top-3 w-12 h-3 bg-primary rounded"></div>
                        <div class="absolute right-3 top-9 w-12 h-3 bg-primary rounded"></div>
                        <div class="absolute left-3 top-14 w-12 h-3 bg-primary rounded"></div>
                        <div class="absolute left-1/2 -translate-x-1/2 top-3 w-1.5 h-1.5 bg-primary rounded-full"></div>
                        <div class="absolute left-1/2 -translate-x-1/2 top-9 w-1.5 h-1.5 bg-primary rounded-full"></div>
                        <div class="absolute left-1/2 -translate-x-1/2 top-14 w-1.5 h-1.5 bg-primary rounded-full"></div>
                    </div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="font-semibold text-gray-800"><?php echo e(__('tl_vertical')); ?></span>
                        <span class="text-xs px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded"><?php echo e(__('slang_default_badge')); ?></span>
                    </div>
                    <p class="text-xs text-gray-500 leading-relaxed"><?php echo e(__('tl_vertical_desc')); ?>。适合企业大事记。</p>
                </div>
            </label>

            <!-- 横向滑块卡 -->
            <label class="block cursor-pointer group layout-pick" data-layout="horizontal">
                <input type="radio" name="timeline_layout" value="horizontal" <?php echo $timelineLayout === 'horizontal' ? 'checked' : ''; ?> class="peer sr-only" tabindex="-1">
                <div class="layout-card rounded-lg p-4 h-full" data-active="<?php echo $timelineLayout === 'horizontal' ? '1' : '0'; ?>">
                    <!-- mini 预览 -->
                    <div class="w-full h-20 bg-gray-50 rounded border flex items-center justify-center relative mb-3">
                        <div class="absolute left-2 right-2 top-5 h-0.5 bg-gray-300"></div>
                        <div class="absolute left-3 top-5 -translate-y-1/2 w-1.5 h-1.5 bg-primary rounded-full"></div>
                        <div class="absolute left-1/3 top-5 -translate-y-1/2 w-1.5 h-1.5 bg-primary rounded-full"></div>
                        <div class="absolute left-2/3 top-5 -translate-y-1/2 w-1.5 h-1.5 bg-primary rounded-full"></div>
                        <div class="absolute right-3 top-5 -translate-y-1/2 w-1.5 h-1.5 bg-primary rounded-full"></div>
                        <div class="absolute left-2 bottom-2 w-12 h-8 bg-primary/40 rounded-sm"></div>
                        <div class="absolute left-1/3 -translate-x-1/4 bottom-2 w-12 h-8 bg-primary/40 rounded-sm"></div>
                        <div class="absolute right-2 bottom-2 w-12 h-8 bg-primary/40 rounded-sm"></div>
                    </div>
                    <div class="font-semibold text-gray-800 mb-1"><?php echo e(__('tl_horizontal')); ?></div>
                    <p class="text-xs text-gray-500 leading-relaxed"><?php echo e(__('tl_horizontal_desc')); ?></p>
                </div>
            </label>

            <!-- 紧凑列表卡 -->
            <label class="block cursor-pointer group layout-pick" data-layout="compact">
                <input type="radio" name="timeline_layout" value="compact" <?php echo $timelineLayout === 'compact' ? 'checked' : ''; ?> class="peer sr-only" tabindex="-1">
                <div class="layout-card rounded-lg p-4 h-full" data-active="<?php echo $timelineLayout === 'compact' ? '1' : '0'; ?>">
                    <!-- mini 预览 -->
                    <div class="w-full h-20 bg-gray-50 rounded border flex items-center relative mb-3 px-3">
                        <div class="absolute left-7 top-3 bottom-3 w-0.5 bg-gray-300"></div>
                        <div class="space-y-1.5 w-full">
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-1.5 bg-primary/60 rounded"></div>
                                <div class="w-1.5 h-1.5 bg-primary rounded-full"></div>
                                <div class="w-12 h-1.5 bg-gray-400 rounded"></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-1.5 bg-primary/60 rounded"></div>
                                <div class="w-1.5 h-1.5 bg-primary rounded-full"></div>
                                <div class="w-16 h-1.5 bg-gray-400 rounded"></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-3 h-1.5 bg-primary/60 rounded"></div>
                                <div class="w-1.5 h-1.5 bg-primary rounded-full"></div>
                                <div class="w-10 h-1.5 bg-gray-400 rounded"></div>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="font-semibold text-gray-800"><?php echo e(__('tl_compact')); ?></span>
                        <span class="text-xs px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded"><?php echo e(__('tl_new_badge')); ?></span>
                    </div>
                    <p class="text-xs text-gray-500 leading-relaxed"><?php echo e(__('tl_compact_desc')); ?></p>
                </div>
            </label>
        </div>

        <!-- 实时预览（只渲染时间线区块，不含页面其它部分） -->
        <div class="mt-6 border-t pt-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <span class="font-medium text-gray-800"><?php echo e(__('tl_live_preview')); ?></span>
                    <span class="text-xs text-gray-500 ml-2"><?php echo e(__('tl_preview_note')); ?></span>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="refreshTimelinePreview()" class="text-sm border px-3 py-1 rounded hover:bg-gray-50 inline-flex items-center gap-1">
                        <i class="ti ti-refresh text-sm"></i>
                        <?php echo e(__('tl_refresh')); ?>
                    </button>
                    <a href="/history.php" target="_blank" class="text-sm border px-3 py-1 rounded hover:bg-gray-50 inline-flex items-center gap-1">
                        <i class="ti ti-external-link text-sm"></i>
                        <?php echo e(__('tl_full_page')); ?>
                    </a>
                </div>
            </div>
            <div id="timelinePreviewBox" class="border rounded-lg p-4 bg-gradient-to-b from-gray-50 to-white max-h-[420px] overflow-auto">
                <?php echo timelineBlock(['limit' => 6]); ?>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-gray-800 mb-1"><?php echo e(__('tl_order')); ?></h3>
        <p class="text-sm text-gray-500 mb-4"><?php echo e(__('tl_order_desc_text')); ?></p>

        <select onchange="saveTimelineSort(this.value)" class="border rounded px-3 py-2 text-sm w-full md:w-auto">
            <option value="desc" <?php echo $timelineSort === 'desc' ? 'selected' : ''; ?>><?php echo e(__('tl_order_desc')); ?></option>
            <option value="asc"  <?php echo $timelineSort === 'asc'  ? 'selected' : ''; ?>><?php echo e(__('tl_order_asc')); ?></option>
        </select>
    </div>
</div><!-- /TAB: settings -->

</div><!-- /x-data root -->

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo e(__('tl_add_event')); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <!-- 时间 -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('tl_year')); ?> <span class="text-red-500">*</span></label>
                    <input type="number" name="year" id="editYear" required min="1900" max="2100"
                           value="<?php echo date('Y'); ?>" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('timeline_month'); ?></label>
                    <select name="month" id="editMonth" class="w-full border rounded px-4 py-2">
                        <option value="0"><?php echo e(__('tl_hide_field')); ?></option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?><?php echo e(__('tl_month_unit')); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('tl_day')); ?></label>
                    <select name="day" id="editDay" class="w-full border rounded px-4 py-2">
                        <option value="0"><?php echo e(__('tl_hide_field')); ?></option>
                        <?php for ($i = 1; $i <= 31; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?><?php echo e(__('tl_day_unit')); ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- 标题 -->
            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('label_title')); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="title" id="editTitle" required class="w-full border rounded px-4 py-2"
                       placeholder="<?php echo e(__('tl_title_ph')); ?>">
            </div>

            <!-- 内容 -->
            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('tl_desc')); ?></label>
                <textarea name="content" id="editContent" rows="3" class="w-full border rounded px-4 py-2"
                          placeholder="<?php echo e(__('tl_desc_ph')); ?>"></textarea>
            </div>

            <!-- 图片 -->
            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('tl_image')); ?></label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2" placeholder="<?php echo e(__('shome_image_url')); ?>">
                    <button type="button" onclick="uploadImage()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                        <i class="ti ti-folder text-base"></i>
                        <?php echo e(__('tl_choose')); ?>
                    </button>
                    <button type="button" onclick="pickImageFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded"><?php echo __('admin_media_library'); ?></button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
            </div>

            <!-- 样式 -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('timeline_icon'); ?></label>
                    <input type="hidden" name="icon" id="editIcon" value="">
                    <div class="flex items-center flex-wrap gap-1.5">
                        <?php foreach ($iconOptions as $key => $label):
                            $emoji = $key === '' ? '' : getTimelineIcon($key);
                        ?>
                        <button type="button" data-icon="<?php echo e($key); ?>" title="<?php echo e($label); ?>"
                                onclick="pickTimelineIcon('<?php echo e($key); ?>')"
                                class="tl-icon w-9 h-9 flex items-center justify-center text-lg rounded-lg border border-gray-200 hover:border-primary cursor-pointer transition">
                            <?php echo $key === '' ? '<span class="text-xs text-gray-400">' . e(__('tl_icon_none')) . '</span>' : $emoji; ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('timeline_color'); ?></label>
                    <input type="hidden" name="color" id="editColor" value="primary">
                    <?php $colorHex = ['primary' => '#3b82f6', 'blue' => '#3b82f6', 'green' => '#22c55e', 'yellow' => '#eab308', 'red' => '#ef4444', 'purple' => '#a855f7', 'cyan' => '#06b6d4', 'indigo' => '#6366f1', 'pink' => '#ec4899', 'gray' => '#6b7280']; ?>
                    <div class="flex items-center flex-wrap gap-2">
                        <?php foreach ($colorOptions as $key => $label): ?>
                        <button type="button" data-color="<?php echo $key; ?>" title="<?php echo e($label); ?>"
                                onclick="pickTimelineColor('<?php echo $key; ?>')"
                                class="tl-swatch w-7 h-7 rounded-full ring-offset-1 ring-primary border border-black/10 cursor-pointer transition"
                                style="background:<?php echo $colorHex[$key] ?? '#3b82f6'; ?>"></button>
                        <?php endforeach; ?>
                        <label class="inline-flex items-center gap-1 text-xs text-gray-500 cursor-pointer ml-1" title="<?php echo __('timeline_color'); ?>">
                            <span><?php echo __('admin_custom'); ?></span>
                            <input type="color" id="editColorCustom" value="#3b82f6" onchange="pickTimelineColor(this.value)"
                                   class="w-7 h-7 p-0 border rounded cursor-pointer bg-white">
                        </label>
                    </div>
                </div>
            </div>

            <!-- 排序和状态 -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('dcat_sort_tip')); ?></p>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo __('admin_show'); ?></option>
                        <option value="0"><?php echo __('admin_hide'); ?></option>
                    </select>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __("btn_save"); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// === 最优先：布局卡片点击保存 ===
// 用 document 级事件委托，确保即使后面任何代码抛错也已经接管点击。
// 同样保证函数在 .layout-pick 元素被点之前 100% 可达（hoisted）。
document.addEventListener('click', function (e) {
    var lbl = e.target.closest('.layout-pick');
    if (!lbl) return;
    var val = lbl.dataset.layout;
    if (!val) return;
    e.preventDefault();
    if (typeof saveTimelineLayout === 'function') {
        saveTimelineLayout(val);
    } else {
        console.error('saveTimelineLayout is not defined; layout=' + val);
        if (typeof showMessage === 'function') {
            showMessage(<?php echo json_encode(__('tl_save_fn_missing'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    }
});

// 拖拽排序（用 try 包起来，避免 Sortable 库未加载或目标元素缺失时把整个脚本 break 掉）
try {
    var _sortableTarget = document.getElementById('sortableList');
    if (typeof Sortable !== 'undefined' && _sortableTarget) {
        new Sortable(_sortableTarget, {
            animation: 150,
            handle: '.cursor-move',
            onEnd: async function() {
                const ids = [...document.querySelectorAll('#sortableList tr[data-id]')].map(el => el.dataset.id);
                const formData = new FormData();
                formData.append('action', 'sort');
                ids.forEach(id => formData.append('ids[]', id));
                await fetch('', { method: 'POST', body: formData });
                showMessage(<?php echo json_encode(__('dl_sort_updated'), JSON_UNESCAPED_UNICODE); ?>);
            }
        });
    }
} catch (e) { console.warn('Sortable init failed:', e); }

// 选择时间线图标：val 为图标 key（''=无图标）
function pickTimelineIcon(val) {
    val = val || '';
    document.getElementById('editIcon').value = val;
    document.querySelectorAll('.tl-icon').forEach(function (b) {
        var on = b.dataset.icon === val;
        b.classList.toggle('border-primary', on);
        b.classList.toggle('ring-2', on);
        b.classList.toggle('ring-primary', on);
        b.classList.toggle('bg-blue-50', on);
    });
}

// 选择时间线颜色：val 为预设名（primary/blue…）或自定义十六进制（#rrggbb）
function pickTimelineColor(val) {
    val = val || 'primary';
    document.getElementById('editColor').value = val;
    var isHex = val.charAt(0) === '#';
    document.querySelectorAll('.tl-swatch').forEach(function (b) {
        var on = !isHex && b.dataset.color === val;
        b.classList.toggle('ring-2', on);
    });
    if (isHex) {
        var ci = document.getElementById('editColorCustom');
        if (ci) ci.value = val;
    }
}

function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? <?php echo json_encode(__('tl_edit_event'), JSON_UNESCAPED_UNICODE); ?> : <?php echo json_encode(__('tl_add_event'), JSON_UNESCAPED_UNICODE); ?>;
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editYear').value = item?.year || new Date().getFullYear();
    document.getElementById('editMonth').value = item?.month || 0;
    document.getElementById('editDay').value = item?.day || 0;
    document.getElementById('editTitle').value = item?.title || '';
    document.getElementById('editContent').value = item?.content || '';
    document.getElementById('editImage').value = item?.image || '';
    pickTimelineIcon(item?.icon || '');
    pickTimelineColor(item?.color || 'primary');
    document.getElementById('editSortOrder').value = item?.sort_order || 0;
    document.getElementById('editStatus').value = item?.status ?? 1;

    const preview = document.getElementById('imagePreview');
    preview.innerHTML = '';
    if (item?.image) {
        const previewImg = document.createElement('img');
        previewImg.src = item.image;
        previewImg.className = 'h-20 rounded';
        preview.appendChild(previewImg);
    }

    document.getElementById('editModal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

document.getElementById('editForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_saved'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
});

async function toggleStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle_status');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = <?php echo json_encode(__('admin_show'), JSON_UNESCAPED_UNICODE); ?>;
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = <?php echo json_encode(__('admin_hide'), JSON_UNESCAPED_UNICODE); ?>;
        }
    }
}

async function deleteItem(id) {
    if (!confirm(<?php echo json_encode(__('tl_del_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg, 'error');
    }
}

function uploadImage() {
    document.getElementById('imageFileInput').click();
}

function pickImageFromMedia() {
    openMediaPicker(function(url) {
        document.getElementById('editImage').value = url;
        var preview = document.getElementById('imagePreview');
        if (preview) {
            preview.innerHTML = '<img src="' + url + '" class="h-20 rounded">';
        }
    });
}

document.getElementById('imageFileInput')?.addEventListener('change', async function() {
    if (!this.files[0]) return;
    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');
    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            document.getElementById('editImage').value = data.data.url;
            document.getElementById('imagePreview').innerHTML = '';
            const uploadedImg = document.createElement('img');
            uploadedImg.src = data.data.url;
            uploadedImg.className = 'h-20 rounded';
            document.getElementById('imagePreview').appendChild(uploadedImg);
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
    }
    this.value = '';
});

async function saveTimelineSort(value) {
    const r = await fetchApi('', { action: 'save_sort_direction', timeline_sort: value });
    if (r.code === 0) {
        showMessage(<?php echo json_encode(__('tl_order_saved'), JSON_UNESCAPED_UNICODE); ?>);
        refreshTimelinePreview();
    } else {
        showMessage(r.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

async function saveTimelineLayout(value) {
    // 立即同步选中卡片的高亮（不等服务端，UI 反应更快）
    document.querySelectorAll('.layout-card').forEach(el => {
        const radio = el.parentElement.querySelector('input[name="timeline_layout"]');
        el.dataset.active = (radio && radio.value === value) ? '1' : '0';
    });
    // 同步原生 checked 状态（用户点的可能是 card 区域，确保 radio 也勾选）
    const targetRadio = document.querySelector('input[name="timeline_layout"][value="' + value + '"]');
    if (targetRadio) targetRadio.checked = true;

    try {
        const r = await fetchApi('', { action: 'save_layout', timeline_layout: value });
        if (r && r.code === 0) {
            const labels = { vertical: <?php echo json_encode(__('tl_vertical'), JSON_UNESCAPED_UNICODE); ?>, horizontal: <?php echo json_encode(__('tl_horizontal'), JSON_UNESCAPED_UNICODE); ?>, compact: <?php echo json_encode(__('tl_compact'), JSON_UNESCAPED_UNICODE); ?> };
            showMessage(<?php echo json_encode(__('tl_saved_as'), JSON_UNESCAPED_UNICODE); ?> + (labels[value] || value));
            refreshTimelinePreview(value);
        } else {
            showMessage((r && r.msg) || <?php echo json_encode(__('tl_save_no_ok'), JSON_UNESCAPED_UNICODE); ?>, 'error');
            console.error('saveTimelineLayout response:', r);
        }
    } catch (err) {
        console.error('saveTimelineLayout error:', err);
        showMessage(<?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?> + ': ' + (err.message || <?php echo json_encode(__('ai_network_error'), JSON_UNESCAPED_UNICODE); ?>), 'error');
    }
}

// 实时预览：AJAX 拉新 HTML 替换 #timelinePreviewBox 内容
async function refreshTimelinePreview(layout) {
    const box = document.getElementById('timelinePreviewBox');
    if (!box) return;

    // 销毁旧 Swiper 实例（避免内存泄漏与样式冲突）
    const oldSwiperEl = box.querySelector('.swiper');
    if (oldSwiperEl && oldSwiperEl.swiper) {
        try { oldSwiperEl.swiper.destroy(true, true); } catch (e) {}
    }

    const fd = new FormData();
    fd.append('action', 'render_preview');
    if (layout) fd.append('layout', layout);
    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await safeJson(r);
        if (d.code !== 0) return;
        box.innerHTML = d.data.html;

        // 后台预览不需要滚入动画 → 立即可见
        box.querySelectorAll('[data-aos]').forEach(el => el.classList.add('aos-animate'));

        // 重新执行内嵌 inline <script>（Swiper 库已在 admin 头部预加载，可直接调用）
        box.querySelectorAll('script').forEach(old => {
            if (old.src) return;
            try { (new Function(old.textContent))(); } catch (e) { console.warn('preview script error', e); }
        });
    } catch (e) {
        showMessage(<?php echo json_encode(__('tl_preview_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

// 首屏：将服务端预渲染的 [data-aos] 立即显示（避免预览框初始空白）
document.querySelectorAll('#timelinePreviewBox [data-aos]').forEach(el => el.classList.add('aos-animate'));

// 把布局相关函数显式挂到 window，方便"重新保存"按钮等外部访问，也避免 hoisting 异常下被 click 委托找不到
try {
    window.saveTimelineLayout = saveTimelineLayout;
    window.saveTimelineSort   = saveTimelineSort;
} catch (e) { /* ignore */ }
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
