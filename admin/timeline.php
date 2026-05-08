<?php
/**
 * ikaiCMS - 发展历程管理
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

// 图标选项
$iconOptions = [
    '' => '无图标',
    'flag' => '旗帜',
    'rocket' => '火箭',
    'award' => '奖杯',
    'users' => '团队',
    'box' => '产品',
    'trending-up' => '增长',
    'map' => '地图',
    'handshake' => '合作',
    'building' => '大楼',
    'star' => '星星',
    'heart' => '爱心',
    'zap' => '闪电',
    'target' => '目标',
    'globe' => '全球',
];

// 颜色选项
$colorOptions = [
    'primary' => '主色',
    'blue' => '蓝色',
    'green' => '绿色',
    'yellow' => '黄色',
    'red' => '红色',
    'purple' => '紫色',
    'cyan' => '青色',
    'indigo' => '靛蓝',
    'pink' => '粉色',
    'gray' => '灰色',
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
            error('请输入标题');
        }

        if ($data['year'] < 1900 || $data['year'] > 2100) {
            error('请输入有效年份');
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
        adminLog('timeline', 'config', "设置时间线排序方向: $val");
        success(['timeline_sort' => $val]);
    }

    if ($action === 'save_layout') {
        $raw = (string)post('timeline_layout');
        $val = in_array($raw, ['vertical', 'horizontal', 'compact'], true) ? $raw : 'vertical';
        settingModel()->set('timeline_layout', $val);
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

// 获取列表
$timelines = timelineModel()->all();

$pageTitle = __('admin_timeline');
$currentMenu = 'timeline';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Swiper 全局预加载（横向布局预览需要，初始即可用） -->
<link rel="stylesheet" href="/assets/swiper/swiper-bundle.min.css">
<script src="/assets/swiper/swiper-bundle.min.js"></script>

<div x-data="{ tab: 'events' }">

<!-- TAB 导航 -->
<div class="bg-white rounded-lg shadow mb-4">
    <div class="flex border-b">
        <button type="button" @click="tab='events'" :class="tab==='events' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
                class="px-6 py-3 border-b-2 font-medium text-sm transition inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
            事件管理
        </button>
        <button type="button" @click="tab='settings'" :class="tab==='settings' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700'"
                class="px-6 py-3 border-b-2 font-medium text-sm transition inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
            显示设置
        </button>
    </div>
</div>

<!-- TAB: 事件管理 -->
<div x-show="tab === 'events'">
<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <div class="text-gray-500 text-sm">
            拖拽排序 · 共 <?php echo count($timelines); ?> 条记录
        </div>
        <div class="flex gap-2">
            <a href="/history.php" target="_blank" class="border px-4 py-2 rounded hover:bg-gray-100 inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                预览
            </a>
            <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                添加事件
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
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">内容摘要</th>
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
                        <span class="text-gray-500">年<?php echo $item['month']; ?>月</span>
                        <?php else: ?>
                        <span class="text-gray-500">年</span>
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
                        <?php
                        $colorClass = match($item['color']) {
                            'blue' => 'bg-blue-500',
                            'green' => 'bg-green-500',
                            'yellow' => 'bg-yellow-500',
                            'red' => 'bg-red-500',
                            'purple' => 'bg-purple-500',
                            'cyan' => 'bg-cyan-500',
                            'indigo' => 'bg-indigo-500',
                            'pink' => 'bg-pink-500',
                            'gray' => 'bg-gray-500',
                            default => 'bg-primary',
                        };
                        ?>
                        <span class="inline-block w-4 h-4 rounded-full <?php echo $colorClass; ?>"></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? __('admin_show') : __('admin_hide'); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            <?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteItem(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            <?php echo __('admin_delete'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($timelines)): ?>
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500">暂无数据，点击"添加事件"开始创建</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</div><!-- /TAB: events -->

<!-- TAB: 显示设置 -->
<div x-show="tab === 'settings'" x-cloak>
    <div class="bg-white rounded-lg shadow p-6 mb-4">
        <h3 class="font-bold text-gray-800 mb-1">前台布局</h3>
        <p class="text-sm text-gray-500 mb-4">选择时间线在 <code>/about/history.html</code> 的展示形态。修改后立即生效，无需清缓存（下次访问页面即重新生成）。</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- 竖向双边卡 -->
            <label class="block cursor-pointer group">
                <input type="radio" name="timeline_layout" value="vertical" <?php echo $timelineLayout === 'vertical' ? 'checked' : ''; ?> onchange="saveTimelineLayout('vertical')" class="peer sr-only">
                <div class="border-2 border-gray-200 rounded-lg p-4 transition peer-checked:border-primary peer-checked:bg-blue-50/40 hover:border-gray-300 h-full">
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
                        <span class="font-semibold text-gray-800">竖向双边</span>
                        <span class="text-xs px-1.5 py-0.5 bg-gray-100 text-gray-600 rounded">默认</span>
                    </div>
                    <p class="text-xs text-gray-500 leading-relaxed">中间主线 + 左右交替卡片，PC 双边 / 移动单边。适合企业大事记。</p>
                </div>
            </label>

            <!-- 横向滑块卡 -->
            <label class="block cursor-pointer group">
                <input type="radio" name="timeline_layout" value="horizontal" <?php echo $timelineLayout === 'horizontal' ? 'checked' : ''; ?> onchange="saveTimelineLayout('horizontal')" class="peer sr-only">
                <div class="border-2 border-gray-200 rounded-lg p-4 transition peer-checked:border-primary peer-checked:bg-blue-50/40 hover:border-gray-300 h-full">
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
                    <div class="font-semibold text-gray-800 mb-1">横向滑块</div>
                    <p class="text-xs text-gray-500 leading-relaxed">Swiper 卡片轮播，顶部主线 + 横向排列，响应式 1-4 列，移动端友好。</p>
                </div>
            </label>

            <!-- 紧凑列表卡 -->
            <label class="block cursor-pointer group">
                <input type="radio" name="timeline_layout" value="compact" <?php echo $timelineLayout === 'compact' ? 'checked' : ''; ?> onchange="saveTimelineLayout('compact')" class="peer sr-only">
                <div class="border-2 border-gray-200 rounded-lg p-4 transition peer-checked:border-primary peer-checked:bg-blue-50/40 hover:border-gray-300 h-full">
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
                        <span class="font-semibold text-gray-800">紧凑列表</span>
                        <span class="text-xs px-1.5 py-0.5 bg-amber-100 text-amber-700 rounded">新</span>
                    </div>
                    <p class="text-xs text-gray-500 leading-relaxed">左侧窄列日期 + 主线圆点 + 右侧标题/正文。信息密度高，适合版本日志、新闻速递。</p>
                </div>
            </label>
        </div>

        <!-- 实时预览（只渲染时间线区块，不含页面其它部分） -->
        <div class="mt-6 border-t pt-5">
            <div class="flex items-center justify-between mb-3">
                <div>
                    <span class="font-medium text-gray-800">实时预览</span>
                    <span class="text-xs text-gray-500 ml-2">仅显示时间线区块（最多 6 条），切换布局自动刷新</span>
                </div>
                <div class="flex gap-2">
                    <button type="button" onclick="refreshTimelinePreview()" class="text-sm border px-3 py-1 rounded hover:bg-gray-50 inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a9 9 0 0114.65-4.94M20 15a9 9 0 01-14.65 4.94"/></svg>
                        刷新
                    </button>
                    <a href="/history.php" target="_blank" class="text-sm border px-3 py-1 rounded hover:bg-gray-50 inline-flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        前台完整页
                    </a>
                </div>
            </div>
            <div id="timelinePreviewBox" class="border rounded-lg p-4 bg-gradient-to-b from-gray-50 to-white max-h-[420px] overflow-auto">
                <?php echo timelineBlock(['limit' => 6]); ?>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-gray-800 mb-1">前台显示顺序</h3>
        <p class="text-sm text-gray-500 mb-4">控制事件在前台的排列方向（仅竖向布局严格按此分组；横向滑块按相同方向排序）。</p>

        <select onchange="saveTimelineSort(this.value)" class="border rounded px-3 py-2 text-sm w-full md:w-auto">
            <option value="desc" <?php echo $timelineSort === 'desc' ? 'selected' : ''; ?>>最新在上（年份降序）</option>
            <option value="asc"  <?php echo $timelineSort === 'asc'  ? 'selected' : ''; ?>>最早在上（年份升序）</option>
        </select>
    </div>
</div><!-- /TAB: settings -->

</div><!-- /x-data root -->

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center sticky top-0 bg-white">
            <h3 class="font-bold text-gray-800" id="modalTitle">添加事件</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <!-- 时间 -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1">年份 <span class="text-red-500">*</span></label>
                    <input type="number" name="year" id="editYear" required min="1900" max="2100"
                           value="<?php echo date('Y'); ?>" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('timeline_month'); ?></label>
                    <select name="month" id="editMonth" class="w-full border rounded px-4 py-2">
                        <option value="0">不显示</option>
                        <?php for ($i = 1; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?>月</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1">日期</label>
                    <select name="day" id="editDay" class="w-full border rounded px-4 py-2">
                        <option value="0">不显示</option>
                        <?php for ($i = 1; $i <= 31; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?>日</option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- 标题 -->
            <div>
                <label class="block text-gray-700 mb-1">标题 <span class="text-red-500">*</span></label>
                <input type="text" name="title" id="editTitle" required class="w-full border rounded px-4 py-2"
                       placeholder="例如：公司成立、获得融资、产品发布">
            </div>

            <!-- 内容 -->
            <div>
                <label class="block text-gray-700 mb-1">内容描述</label>
                <textarea name="content" id="editContent" rows="3" class="w-full border rounded px-4 py-2"
                          placeholder="详细描述这个里程碑事件..."></textarea>
            </div>

            <!-- 图片 -->
            <div>
                <label class="block text-gray-700 mb-1">配图</label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2" placeholder="图片URL">
                    <button type="button" onclick="uploadImage()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                        选择
                    </button>
                    <button type="button" onclick="pickImageFromMedia()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded"><?php echo __('admin_media_library'); ?></button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
            </div>

            <!-- 样式 -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('timeline_icon'); ?></label>
                    <select name="icon" id="editIcon" class="w-full border rounded px-4 py-2">
                        <?php foreach ($iconOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('timeline_color'); ?></label>
                    <select name="color" id="editColor" class="w-full border rounded px-4 py-2">
                        <?php foreach ($colorOptions as $key => $label): ?>
                        <option value="<?php echo $key; ?>"><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- 排序和状态 -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                    <p class="text-xs text-gray-400 mt-1">数字越大越靠前</p>
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
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <?php echo __("btn_save"); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 拖拽排序
new Sortable(document.getElementById('sortableList'), {
    animation: 150,
    handle: '.cursor-move',
    onEnd: async function() {
        const ids = [...document.querySelectorAll('#sortableList tr[data-id]')].map(el => el.dataset.id);
        const formData = new FormData();
        formData.append('action', 'sort');
        ids.forEach(id => formData.append('ids[]', id));
        await fetch('', { method: 'POST', body: formData });
        showMessage('排序已保存');
    }
});

function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? '编辑事件' : '添加事件';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editYear').value = item?.year || new Date().getFullYear();
    document.getElementById('editMonth').value = item?.month || 0;
    document.getElementById('editDay').value = item?.day || 0;
    document.getElementById('editTitle').value = item?.title || '';
    document.getElementById('editContent').value = item?.content || '';
    document.getElementById('editImage').value = item?.image || '';
    document.getElementById('editIcon').value = item?.icon || '';
    document.getElementById('editColor').value = item?.color || 'primary';
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

document.getElementById('editForm').addEventListener('submit', async function(e) {
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
            btn.textContent = '显示';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '隐藏';
        }
    }
}

async function deleteItem(id) {
    if (!confirm('确定要删除这条记录吗？')) return;
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

document.getElementById('imageFileInput').addEventListener('change', async function() {
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
        showMessage('已更新前台显示顺序');
        refreshTimelinePreview();
    } else {
        showMessage(r.msg || '保存失败', 'error');
    }
}

async function saveTimelineLayout(value) {
    const r = await fetchApi('', { action: 'save_layout', timeline_layout: value });
    if (r.code === 0) {
        const labels = { vertical: '竖向双边', horizontal: '横向滑块', compact: '紧凑列表' };
        showMessage('已切换为' + (labels[value] || value));
        refreshTimelinePreview(value);
    } else {
        showMessage(r.msg || '保存失败', 'error');
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
        const d = await r.json();
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
        showMessage('预览刷新失败', 'error');
    }
}

// 首屏：将服务端预渲染的 [data-aos] 立即显示（避免预览框初始空白）
document.querySelectorAll('#timelinePreviewBox [data-aos]').forEach(el => el.classList.add('aos-animate'));
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
