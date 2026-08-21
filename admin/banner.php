<?php
/**
 * YikaiCMS - 轮播图管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('banner');
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

// 处理 AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $parseSchedule = static function (string $value): ?int {
            $value = trim($value);
            if ($value === '') {
                return 0;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2})?$/', $value) !== 1) {
                return null;
            }
            $timestamp = strtotime($value);
            return $timestamp === false ? null : $timestamp;
        };
        $startTime = $parseSchedule(post('start_time'));
        $endTime = $parseSchedule(post('end_time'));
        if ($startTime === null || $endTime === null) {
            error(__('bn_schedule_invalid'));
        }
        if ($startTime > 0 && $endTime > 0 && $endTime <= $startTime) {
            error(__('bn_schedule_order_invalid'));
        }
        $contentMotion = post('content_motion', 'inherit');
        $backgroundMotion = post('background_motion', 'inherit');
        $data = [
            'title' => post('title'),
            'subtitle' => post('subtitle'),
            'image' => post('image'),
            'image_mobile' => post('image_mobile'),
            'btn1_text' => post('btn1_text'),
            // safeUrl：拒 javascript: 等伪协议（HTML 转义防不了），与友链同一套「存取双校验」
            'btn1_url' => safeUrl((string) post('btn1_url')),
            'btn2_text' => post('btn2_text'),
            'btn2_url' => safeUrl((string) post('btn2_url')),
            'link_url' => safeUrl((string) post('link_url')),
            'link_target' => post('link_target', '_self'),
            'position' => post('position', 'home'),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'content_motion' => in_array($contentMotion, ['inherit', 'none', 'fade-up', 'slide-left', 'slide-right', 'zoom-in', 'clip-reveal', 'blur-up', 'pop-in'], true)
                ? $contentMotion
                : 'inherit',
            'background_motion' => in_array($backgroundMotion, ['inherit', 'none', 'zoom-in', 'zoom-out'], true)
                ? $backgroundMotion
                : 'inherit',
            'sort_order' => postInt('sort_order', 0),
            'status' => postInt('status', 1),
        ];

        if ($id > 0) {
            bannerModel()->updateById($id, $data);
            adminLog('banner', 'update', "更新轮播图ID: $id");
        } else {
            $data['created_at'] = time();
            $id = bannerModel()->create($data);
            adminLog('banner', 'create', "创建轮播图ID: $id");
        }

        success(['id' => $id]);
    }

    if ($action === 'delete') {
        $id = postInt('id');
        bannerModel()->deleteById($id);
        adminLog('banner', 'delete', "删除轮播图ID: $id");
        success();
    }

    if ($action === 'toggle_status') {
        $id = postInt('id');
        $newStatus = bannerModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    if ($action === 'sort') {
        $ids = $_POST['ids'] ?? [];
        bannerModel()->updateSort($ids);
        success();
    }

    if ($action === 'save_settings') {
        $heightPc = postInt('banner_height_pc', 650);
        $heightMobile = postInt('banner_height_mobile', 300);

        $heightPc = max(200, min(1000, $heightPc));
        $heightMobile = max(150, min(600, $heightMobile));

        settingModel()->set('banner_height_pc', (string)$heightPc);
        settingModel()->set('banner_height_mobile', (string)$heightMobile);

        adminLog('banner', 'settings', "更新轮播图设置: PC={$heightPc}px, 移动端={$heightMobile}px");
        success();
    }

    // ── 分组管理 ──

    if ($action === 'group_save') {
        $id = postInt('id');
        $name = post('name');
        $slug = post('slug');
        $oldGroup = $id > 0 ? bannerGroupModel()->find($id) : null;
        $effect = post('effect', 'fade');
        $contentMotion = post('content_motion', 'none');
        $backgroundMotion = post('background_motion', 'none');
        $heightMode = post('height_mode', '');
        if (!in_array($heightMode, ['fixed', 'screen', 'cover-header'], true)) {
            $heightMode = post('fullscreen', '0') === '1' ? 'screen' : 'fixed';
        }
        $autoplaySeconds = array_key_exists('autoplay_seconds', $_POST)
            ? postInt('autoplay_seconds', 5)
            : (int) round(postInt('autoplay_delay', 5000) / 1000);

        if (!$name || !$slug) {
            error(__('bn_group_required'));
        }

        if ($id > 0 && !$oldGroup) {
            error(__('bn_group_missing'));
        }

        if ($oldGroup && (string) $oldGroup['slug'] !== $slug) {
            error(__('bn_slug_immutable'));
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
            error(__('bn_slug_format'));
        }

        if (!bannerGroupModel()->isSlugUnique($slug, $id)) {
            error(__('bn_slug_exists'));
        }

        $data = [
            'name'           => $name,
            'slug'           => $slug,
            'height_pc'      => max(200, min(1600, postInt('height_pc', 500))),
            'height_mobile'  => max(180, min(1200, postInt('height_mobile', 250))),
            'height_mode'    => $heightMode,
            // 兼容尚未识别 height_mode 的旧前台：两种首屏模式至少维持满屏高度。
            'fullscreen'     => $heightMode === 'fixed' ? 0 : 1,
            'autoplay_delay' => max(0, min(30, $autoplaySeconds)) * 1000,
            'effect'         => in_array($effect, ['fade', 'slide'], true) ? $effect : 'fade',
            'speed'          => max(200, min(2000, postInt('speed', 700))),
            'content_motion' => in_array($contentMotion, ['none', 'fade-up', 'slide-left', 'slide-right', 'zoom-in', 'clip-reveal', 'blur-up', 'pop-in'], true)
                ? $contentMotion
                : 'none',
            'background_motion' => in_array($backgroundMotion, ['none', 'zoom-in', 'zoom-out'], true)
                ? $backgroundMotion
                : 'none',
            'stagger'        => max(0, min(600, postInt('stagger', 120))),
            'navigation'     => (isset($_POST['navigation']) && $_POST['navigation'] === '1') ? 1 : 0,
            'pagination'     => (isset($_POST['pagination']) && $_POST['pagination'] === '1') ? 1 : 0,
            'pause_hover'    => (isset($_POST['pause_hover']) && $_POST['pause_hover'] === '1') ? 1 : 0,
        ];

        if ($id > 0) {
            bannerGroupModel()->updateById($id, $data);
            adminLog('banner', 'group_update', "更新轮播图分组: {$name}");
        } else {
            $data['status'] = 1;
            $data['created_at'] = time();
            $id = bannerGroupModel()->create($data);
            adminLog('banner', 'group_create', "创建轮播图分组: {$name}");
        }

        success(['id' => $id]);
    }

    if ($action === 'group_delete') {
        $id = postInt('id');
        $group = bannerGroupModel()->find($id);
        if (!$group) error(__('bn_group_missing'));

        $count = bannerGroupModel()->getBannerCount($group['slug']);
        if ($count > 0) {
            error(str_replace(':n', (string) $count, __('bn_group_has_items')));
        }

        bannerGroupModel()->deleteById($id);
        adminLog('banner', 'group_delete', "删除轮播图分组: {$group['name']}");
        success();
    }

    if ($action === 'group_toggle') {
        $id = postInt('id');
        $newStatus = bannerGroupModel()->toggle($id, 'status');
        success(['status' => $newStatus]);
    }

    exit;
}

// 当前 Tab
$tab = get('tab', 'list');

// 动态分组
$groups = [];
try {
    $groups = bannerGroupModel()->all();
} catch (\Throwable $e) {
    // 表不存在时降级
}
$positions = [];
foreach ($groups as $g) {
    $positions[$g['slug']] = $g['name'];
}
if (empty($positions)) {
    $positions = ['home' => __('nav_home'), 'about' => __('shome_blk_about'), 'product' => __('admin_product'), 'case' => __('bn_pos_case')];
}
$editGroupSlug = trim(get('edit', ''));
$editGroup = null;
if ($editGroupSlug !== '') {
    foreach ($groups as $candidateGroup) {
        if ((string) ($candidateGroup['slug'] ?? '') === $editGroupSlug) {
            $editGroup = $candidateGroup;
            break;
        }
    }
}

// 编辑分组时显示当前首页首个可见区块状态；判断线上已发布结构，而不是编辑器草稿。
$homeFirstVisibleIsBanner = false;
try {
    $activeHomeDocument = null;
    if (HomeLayoutDocument::isActive() && HomeLayoutDocument::hasPublished()) {
        $activeHomeDocument = HomeLayoutDocument::loadPublished();
    } elseif (HomeBloxDocument::isActive() && HomeBloxDocument::hasPublished()) {
        $activeHomeDocument = HomeBloxDocument::loadPublished();
    }

    if (is_array($activeHomeDocument)) {
        $homeFirstVisibleIsBanner = HomeBloxRenderer::startsWithVisibleBanner($activeHomeDocument['sections']);
    } else {
        $legacyHomeBlocks = json_decode((string) config('home_blocks_config', ''), true);
        if (!is_array($legacyHomeBlocks) || $legacyHomeBlocks === []) {
            $legacyHomeBlocks = [['type' => 'banner', 'enabled' => true]];
        }
        $homeFirstVisibleIsBanner = HomeBloxRenderer::legacyStartsWithVisibleBanner($legacyHomeBlocks);
    }
} catch (\Throwable $e) {
    // 老站结构异常时仅隐藏肯定提示，不阻断轮播图管理。
}

// 轮播图列表数据（list Tab 用）
$position = get('position', '');
$page = max(1, getInt('page', 1));
$perPage = 20;

$conditions = [];
if ($position) {
    $conditions['position'] = $position;
}

// 视图语言（?lang=en/ja 切换：banners 表有 lang 列）
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_langLabels  = availableLanguages();
$conditions['lang'] = $_viewLang;

$result = bannerModel()->paginate($page, $perPage, $conditions, 'sort_order ASC, id DESC');
$total = $result['total'];
$banners = $result['items'];

// 全局设置
$bannerHeightPc = (int)config('banner_height_pc', 650);
$bannerHeightMobile = (int)config('banner_height_mobile', 300);

$pageTitle = __('admin_banner');
$currentMenu = 'banner';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('banners');
require_once ROOT_PATH . '/admin/includes/header.php';

echo renderAdminLangSwitcher($_viewLang, __('bn_lang_tip'));
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-2 flex flex-wrap gap-2" role="navigation" aria-label="<?php echo e(__('admin_banner')); ?>">
        <a href="/admin/banner.php<?php echo $_lang['qs'] ?? ''; ?>" class="px-4 py-2.5 text-sm font-medium rounded inline-flex items-center gap-2 <?php echo $tab === 'list' ? 'bg-primary text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'; ?>">
            <i class="ti ti-photo text-base"></i>
            <?php echo e(__('bn_tab_list')); ?>
        </a>
        <a href="/admin/banner.php?tab=groups<?php echo $_lang['qsAmp'] ?? ''; ?>" class="px-4 py-2.5 text-sm font-medium rounded inline-flex items-center gap-2 <?php echo $tab === 'groups' ? 'bg-primary text-white shadow-sm' : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'; ?>">
            <i class="ti ti-adjustments-horizontal text-base"></i>
            <?php echo e(__('bn_tab_groups')); ?>
        </a>
    </div>
</div>

<?php if ($tab === 'list'): ?>
<!-- ========== 轮播图列表 ========== -->
<link rel="stylesheet" href="<?php echo e(assetVer('/assets/css/blox-banner.css')); ?>">

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <select name="position" class="border rounded px-3 py-2">
                <option value=""><?php echo __('filter_all_groups'); ?></option>
                <?php foreach ($positions as $k => $v): ?>
                <option value="<?php echo $k; ?>" <?php echo $position === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-search text-base"></i>
                <?php echo __('admin_filter'); ?>
            </button>
        </form>

        <div class="flex flex-wrap gap-2">
            <?php if ($position !== '' && isset($positions[$position])): ?>
            <a href="/admin/banner.php?tab=groups&amp;edit=<?php echo rawurlencode($position); ?><?php echo $_lang['qsAmp'] ?? ''; ?>"
               class="border border-primary text-primary hover:bg-blue-50 px-4 py-2 rounded inline-flex items-center gap-2">
                <i class="ti ti-adjustments-horizontal text-base"></i>
                <?php echo e(str_replace(':name', (string) $positions[$position], __('bn_edit_named_group_settings'))); ?>
            </a>
            <?php else: ?>
            <a href="/admin/banner.php?tab=groups<?php echo $_lang['qsAmp'] ?? ''; ?>"
               class="border border-primary text-primary hover:bg-blue-50 px-4 py-2 rounded inline-flex items-center gap-2">
                <i class="ti ti-adjustments-horizontal text-base"></i>
                <?php echo e(__('bn_tab_groups')); ?>
            </a>
            <?php endif; ?>
            <button onclick="openSettingsModal()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-ruler text-base"></i>
                <?php echo e(__('bn_default_size')); ?>
            </button>
            <button onclick="openEditModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <i class="ti ti-plus text-base"></i>
                <?php echo __('admin_add'); ?>
            </button>
        </div>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_sort_order'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_image'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_title_label'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('label_group'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_translate')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y" id="sortableList">
                <?php foreach ($banners as $item):
                    $now = time();
                    $startAt = (int) ($item['start_time'] ?? 0);
                    $endAt = (int) ($item['end_time'] ?? 0);
                    $itemGroupUrl = '/admin/banner.php?position=' . rawurlencode((string) $item['position']) . ($_lang['qsAmp'] ?? '');
                    $scheduleKey = $startAt > $now
                        ? 'bn_schedule_waiting'
                        : ($endAt > 0 && $endAt < $now ? 'bn_schedule_expired' : 'bn_schedule_active');
                ?>
                <tr class="hover:bg-gray-50" data-id="<?php echo $item['id']; ?>">
                    <td class="px-4 py-3">
                        <span class="cursor-move text-gray-400">&#9776;</span>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($item['image']): ?>
                        <div class="relative inline-block">
                            <img src="<?php echo e($item['image']); ?>" class="h-12 w-20 object-cover rounded">
                            <?php if (!empty($item['image_mobile'])): ?>
                            <span class="absolute -right-1 -bottom-1 w-5 h-5 rounded-full bg-gray-800 text-white inline-flex items-center justify-center" title="<?php echo e(__('bn_mobile_image_ready')); ?>">
                                <i class="ti ti-device-mobile text-xs"></i>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium"><?php echo e($item['title'] ?: __('bn_untitled')); ?></div>
                        <?php if ($item['subtitle']): ?>
                        <div class="text-sm text-gray-500"><?php echo e($item['subtitle']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <div class="inline-flex items-center gap-1">
                            <a href="<?php echo e($itemGroupUrl); ?>"
                               class="inline-flex items-center gap-1 text-xs bg-blue-100 text-blue-600 hover:bg-blue-200 px-2 py-1 rounded"
                               title="<?php echo e(__('bn_open_group')); ?>">
                                <?php echo e($positions[$item['position']] ?? $item['position']); ?>
                            </a>
                            <a href="/admin/banner.php?tab=groups&amp;edit=<?php echo rawurlencode((string) $item['position']); ?><?php echo $_lang['qsAmp'] ?? ''; ?>"
                               class="w-7 h-7 inline-flex items-center justify-center text-gray-400 hover:text-primary hover:bg-blue-50 rounded"
                               title="<?php echo e(__('bn_edit_group_settings')); ?>" aria-label="<?php echo e(__('bn_edit_group_settings')); ?>">
                                <i class="ti ti-adjustments-horizontal text-sm"></i>
                            </a>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleStatus(<?php echo $item['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $item['status'] ? __('admin_show') : __('admin_hide'); ?>
                        </button>
                        <?php if (!empty($item['status']) && ($startAt > 0 || $endAt > 0)): ?>
                        <div class="text-xs mt-1 <?php echo $scheduleKey === 'bn_schedule_active' ? 'text-green-600' : 'text-amber-600'; ?>">
                            <?php echo e(__($scheduleKey)); ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $_pillSrcId = (int) ($item['translation_group_id'] ?? $item['id']);
                        echo renderTransPills($_pillSrcId, $transStatus, '/admin/banner.php');
                        ?>
                    </td>
                    <td class="px-4 py-3 text-center whitespace-nowrap">
                        <button onclick='openEditModal(<?php echo json_encode($item, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-2 inline-flex items-center gap-1">
                            <i class="ti ti-pencil text-sm"></i>
                            <?php echo __('admin_edit'); ?></button>
                        <button onclick="deleteBanner(<?php echo $item['id']; ?>)"
                                class="text-red-600 hover:underline text-sm inline-flex items-center gap-1">
                            <i class="ti ti-trash text-sm"></i>
                            <?php echo __('admin_delete'); ?></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($banners)): ?>
                <tr>
                    <td colspan="7" class="px-4 py-8 text-center text-gray-500"><?php echo __('admin_no_data'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-3xl max-h-[calc(100vh-2rem)] overflow-y-auto">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="modalTitle"><?php echo __('banner_add'); ?></h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="editForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" id="editId" value="0">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('label_title'); ?></label>
                <input type="text" name="title" id="editTitle" class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('label_subtitle'); ?></label>
                <input type="text" name="subtitle" id="editSubtitle" class="w-full border rounded px-4 py-2">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_btn1_text'); ?></label>
                    <input type="text" name="btn1_text" id="editBtn1Text" class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('bn_ph_btn1')); ?>">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_btn1_url'); ?></label>
                    <input type="text" name="btn1_url" id="editBtn1Url" class="w-full border rounded px-4 py-2" placeholder="/about.html">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_btn2_text'); ?></label>
                    <input type="text" name="btn2_text" id="editBtn2Text" class="w-full border rounded px-4 py-2" placeholder="<?php echo e(__('bn_ph_btn2')); ?>">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_btn2_url'); ?></label>
                    <input type="text" name="btn2_url" id="editBtn2Url" class="w-full border rounded px-4 py-2" placeholder="/contact.html">
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('label_image'); ?></label>
                <div class="flex gap-2">
                    <input type="text" name="image" id="editImage" class="flex-1 border rounded px-4 py-2">
                    <button type="button" onclick="uploadImage('editImage')" class="bg-gray-500 hover:bg-gray-600 text-white w-10 h-10 rounded inline-flex items-center justify-center" title="<?php echo e(__('admin_choose_file')); ?>"><i class="ti ti-upload"></i></button>
                    <button type="button" onclick="pickImageFromMedia('editImage')" class="bg-blue-500 hover:bg-blue-600 text-white w-10 h-10 rounded inline-flex items-center justify-center" title="<?php echo e(__('admin_media_library')); ?>"><i class="ti ti-photo"></i></button>
                </div>
                <div id="imagePreview" class="mt-2"></div>
                <p class="text-xs text-gray-400 mt-1"><?php echo str_replace(':size', '1920 x ' . $bannerHeightPc . 'px', e(__('bn_suggest_size'))); ?></p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_mobile_image')); ?></label>
                <div class="flex gap-2">
                    <input type="text" name="image_mobile" id="editImageMobile" class="flex-1 border rounded px-4 py-2" placeholder="<?php echo e(__('bn_mobile_image_placeholder')); ?>">
                    <button type="button" onclick="uploadImage('editImageMobile')" class="bg-gray-500 hover:bg-gray-600 text-white w-10 h-10 rounded inline-flex items-center justify-center" title="<?php echo e(__('admin_choose_file')); ?>"><i class="ti ti-upload"></i></button>
                    <button type="button" onclick="pickImageFromMedia('editImageMobile')" class="bg-blue-500 hover:bg-blue-600 text-white w-10 h-10 rounded inline-flex items-center justify-center" title="<?php echo e(__('admin_media_library')); ?>"><i class="ti ti-photo"></i></button>
                </div>
                <div id="mobileImagePreview" class="mt-2"></div>
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_mobile_image_tip')); ?></p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_link_url'); ?></label>
                    <input type="text" name="link_url" id="editLinkUrl" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_link_target'); ?></label>
                    <select name="link_target" id="editLinkTarget" class="w-full border rounded px-4 py-2">
                        <option value="_self"><?php echo __('label_target_self'); ?></option>
                        <option value="_blank"><?php echo __('label_target_blank'); ?></option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('blox_home_banner_content_motion')); ?></label>
                    <select name="content_motion" id="editContentMotion" class="w-full border rounded px-4 py-2">
                        <option value="inherit"><?php echo e(__('blox_banner_motion_inherit')); ?></option>
                        <option value="none"><?php echo e(__('blox_banner_motion_none')); ?></option>
                        <option value="fade-up"><?php echo e(__('blox_banner_motion_fade_up')); ?></option>
                        <option value="slide-left"><?php echo e(__('blox_banner_motion_slide_left')); ?></option>
                        <option value="slide-right"><?php echo e(__('blox_banner_motion_slide_right')); ?></option>
                        <option value="zoom-in"><?php echo e(__('blox_banner_motion_zoom_in')); ?></option>
                        <option value="clip-reveal"><?php echo e(__('blox_banner_motion_clip_reveal')); ?></option>
                        <option value="blur-up"><?php echo e(__('blox_banner_motion_blur_up')); ?></option>
                        <option value="pop-in"><?php echo e(__('blox_banner_motion_pop_in')); ?></option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo e(__('blox_banner_background_motion')); ?></label>
                    <select name="background_motion" id="editBackgroundMotion" class="w-full border rounded px-4 py-2">
                        <option value="inherit"><?php echo e(__('blox_banner_motion_inherit')); ?></option>
                        <option value="none"><?php echo e(__('blox_banner_motion_none')); ?></option>
                        <option value="zoom-in"><?php echo e(__('blox_banner_background_zoom_in')); ?></option>
                        <option value="zoom-out"><?php echo e(__('blox_banner_background_zoom_out')); ?></option>
                    </select>
                </div>
            </div>

            <div>
                <h4 class="font-medium text-gray-800 mb-3"><?php echo e(__('bn_schedule')); ?></h4>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo e(__('bn_start_time')); ?></label>
                        <input type="datetime-local" name="start_time" id="editStartTime" class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo e(__('bn_end_time')); ?></label>
                        <input type="datetime-local" name="end_time" id="editEndTime" class="w-full border rounded px-4 py-2">
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_schedule_tip')); ?></p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_group'); ?></label>
                    <select name="position" id="editPosition" class="w-full border rounded px-4 py-2">
                        <?php foreach ($positions as $k => $v): ?>
                        <option value="<?php echo e($k); ?>"><?php echo e($v); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_sort_order'); ?></label>
                    <input type="number" name="sort_order" id="editSortOrder" value="0" class="w-full border rounded px-4 py-2">
                </div>
                <div>
                    <label class="block text-gray-700 mb-1"><?php echo __('label_status'); ?></label>
                    <select name="status" id="editStatus" class="w-full border rounded px-4 py-2">
                        <option value="1"><?php echo __('admin_show'); ?></option>
                        <option value="0"><?php echo __('admin_hide'); ?></option>
                    </select>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-2">
                    <label class="font-medium text-gray-800"><?php echo e(__('bn_live_preview')); ?></label>
                    <div class="inline-flex border rounded overflow-hidden" role="group" aria-label="<?php echo e(__('bn_preview_device')); ?>">
                        <button type="button" id="previewDesktopButton" onclick="setBannerPreviewMode('desktop')" class="w-9 h-8 inline-flex items-center justify-center bg-gray-800 text-white" title="<?php echo e(__('bn_preview_desktop')); ?>"><i class="ti ti-device-desktop"></i></button>
                        <button type="button" id="previewMobileButton" onclick="setBannerPreviewMode('mobile')" class="w-9 h-8 inline-flex items-center justify-center bg-white text-gray-500" title="<?php echo e(__('bn_preview_mobile')); ?>"><i class="ti ti-device-mobile"></i></button>
                    </div>
                </div>
                <div id="bannerLivePreview" data-blox-banner data-blox-content-motion="none" data-blox-background-motion="none" class="blox-banner-static-active relative h-52 overflow-hidden bg-gray-900 rounded">
                    <img id="bannerPreviewImage" src="" alt="" class="absolute inset-0 w-full h-full object-cover hidden" data-blox-banner-bg>
                    <div class="absolute inset-0 bg-black/30 flex items-center justify-center px-6">
                        <div class="text-center text-white max-w-xl">
                            <h3 id="bannerPreviewTitle" class="text-2xl font-bold" data-blox-layer style="--blox-layer-order:0"></h3>
                            <p id="bannerPreviewSubtitle" class="text-sm mt-2" data-blox-layer style="--blox-layer-order:1"></p>
                            <div id="bannerPreviewButtons" class="flex flex-wrap justify-center gap-2 mt-4" data-blox-layer style="--blox-layer-order:2"></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __('admin_save'); ?></button>
            </div>
        </form>
    </div>
</div>

<!-- 设置弹窗 -->
<div id="settingsModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeSettingsModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-md">
        <div class="px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800"><?php echo e(__('bn_default_size')); ?></h3>
            <button onclick="closeSettingsModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <form id="settingsForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="save_settings">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_pc_height')); ?> (px)</label>
                <input type="number" name="banner_height_pc" id="settingHeightPc" value="<?php echo $bannerHeightPc; ?>" min="200" max="1000" class="w-full border rounded px-4 py-2">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_pc_height_tip')); ?></p>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_mobile_height')); ?> (px)</label>
                <input type="number" name="banner_height_mobile" id="settingHeightMobile" value="<?php echo $bannerHeightMobile; ?>" min="150" max="600" class="w-full border rounded px-4 py-2">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_mobile_height_tip')); ?></p>
            </div>

            <div class="bg-blue-50 text-blue-700 p-3 rounded text-sm">
                <strong><?php echo e(__('admin_tip_label')); ?></strong><?php echo e(__('bn_global_height_tip')); ?>
            </div>

            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="closeSettingsModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded inline-flex items-center gap-1">
                    <i class="ti ti-check text-base"></i>
                    <?php echo __('admin_save'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
const bannerGroupPreviewSettings = <?php echo json_encode(array_reduce($groups, static function (array $map, array $group): array {
    $map[(string) $group['slug']] = [
        'content_motion' => (string) ($group['content_motion'] ?? 'none'),
        'background_motion' => (string) ($group['background_motion'] ?? 'none'),
    ];
    return $map;
}, []), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let bannerImageUploadTarget = 'editImage';
let bannerPreviewMode = 'desktop';

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
    }
});

function openEditModal(item = null) {
    document.getElementById('modalTitle').textContent = item ? '<?php echo __("banner_edit"); ?>' : '<?php echo __("banner_add"); ?>';
    document.getElementById('editId').value = item?.id || 0;
    document.getElementById('editTitle').value = item?.title || '';
    document.getElementById('editSubtitle').value = item?.subtitle || '';
    document.getElementById('editBtn1Text').value = item?.btn1_text || '';
    document.getElementById('editBtn1Url').value = item?.btn1_url || '';
    document.getElementById('editBtn2Text').value = item?.btn2_text || '';
    document.getElementById('editBtn2Url').value = item?.btn2_url || '';
    document.getElementById('editImage').value = item?.image || '';
    document.getElementById('editImageMobile').value = item?.image_mobile || '';
    document.getElementById('editLinkUrl').value = item?.link_url || '';
    document.getElementById('editLinkTarget').value = item?.link_target || '_self';
    document.getElementById('editPosition').value = item?.position || 'home';
    document.getElementById('editSortOrder').value = item?.sort_order || 0;
    document.getElementById('editStatus').value = item?.status ?? 1;
    document.getElementById('editContentMotion').value = item?.content_motion || 'inherit';
    document.getElementById('editBackgroundMotion').value = item?.background_motion || 'inherit';
    setBannerScheduleValue('editStartTime', item?.start_time);
    setBannerScheduleValue('editEndTime', item?.end_time);

    renderBannerImageThumb('imagePreview', item?.image || '');
    renderBannerImageThumb('mobileImagePreview', item?.image_mobile || '');
    setBannerPreviewMode('desktop');
    renderBannerPreview();

    document.getElementById('editModal').classList.remove('hidden');
}

function formatBannerDateTime(timestamp) {
    const seconds = Number(timestamp || 0);
    if (!Number.isFinite(seconds) || seconds <= 0) return '';
    const date = new Date(seconds * 1000);
    const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000);
    return local.toISOString().slice(0, 16).replace('T', ' ');
}

function setBannerScheduleValue(inputId, timestamp) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const value = formatBannerDateTime(timestamp);
    if (input._flatpickr) {
        input._flatpickr.setDate(value || null, false, 'Y-m-d H:i');
    } else {
        input.value = value;
    }
}

function renderBannerImageThumb(containerId, url) {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.replaceChildren();
    if (!url) return;
    const image = document.createElement('img');
    image.src = url;
    image.alt = '';
    image.className = 'h-20 max-w-full object-cover rounded';
    container.appendChild(image);
}

function setBannerImage(targetId, url) {
    const input = document.getElementById(targetId);
    if (!input) return;
    input.value = url;
    renderBannerImageThumb(targetId === 'editImageMobile' ? 'mobileImagePreview' : 'imagePreview', url);
    renderBannerPreview();
}

function resolvedBannerMotion(fieldId, groupKey, fallback) {
    const value = document.getElementById(fieldId)?.value || 'inherit';
    if (value !== 'inherit') return value;
    const position = document.getElementById('editPosition')?.value || 'home';
    return bannerGroupPreviewSettings[position]?.[groupKey] || fallback;
}

function renderBannerPreview() {
    const root = document.getElementById('bannerLivePreview');
    const image = document.getElementById('bannerPreviewImage');
    if (!root || !image) return;

    const desktopImage = document.getElementById('editImage')?.value.trim() || '';
    const mobileImage = document.getElementById('editImageMobile')?.value.trim() || '';
    const imageUrl = bannerPreviewMode === 'mobile' ? (mobileImage || desktopImage) : desktopImage;
    image.src = imageUrl;
    image.classList.toggle('hidden', imageUrl === '');
    document.getElementById('bannerPreviewTitle').textContent = document.getElementById('editTitle')?.value || '';
    document.getElementById('bannerPreviewSubtitle').textContent = document.getElementById('editSubtitle')?.value || '';

    const buttons = document.getElementById('bannerPreviewButtons');
    buttons.replaceChildren();
    ['editBtn1Text', 'editBtn2Text'].forEach(function(id, index) {
        const text = document.getElementById(id)?.value.trim() || '';
        if (!text) return;
        const button = document.createElement('span');
        button.textContent = text;
        button.className = index === 0
            ? 'bg-white text-gray-800 px-4 py-2 rounded font-medium'
            : 'border border-white text-white px-4 py-2 rounded font-medium';
        buttons.appendChild(button);
    });

    root.dataset.bloxContentMotion = resolvedBannerMotion('editContentMotion', 'content_motion', 'none');
    root.dataset.bloxBackgroundMotion = resolvedBannerMotion('editBackgroundMotion', 'background_motion', 'none');
    root.classList.remove('blox-banner-static-active');
    void root.offsetWidth;
    root.classList.add('blox-banner-static-active');
}

function setBannerPreviewMode(mode) {
    bannerPreviewMode = mode === 'mobile' ? 'mobile' : 'desktop';
    const root = document.getElementById('bannerLivePreview');
    const desktop = document.getElementById('previewDesktopButton');
    const mobile = document.getElementById('previewMobileButton');
    if (root) {
        root.style.maxWidth = bannerPreviewMode === 'mobile' ? '320px' : '';
        root.style.height = bannerPreviewMode === 'mobile' ? '320px' : '208px';
        root.style.marginInline = bannerPreviewMode === 'mobile' ? 'auto' : '';
    }
    [desktop, mobile].forEach(function(button) {
        if (!button) return;
        const active = button === (bannerPreviewMode === 'mobile' ? mobile : desktop);
        button.classList.toggle('bg-gray-800', active);
        button.classList.toggle('text-white', active);
        button.classList.toggle('bg-white', !active);
        button.classList.toggle('text-gray-500', !active);
    });
    renderBannerPreview();
}

[
    'editTitle', 'editSubtitle', 'editBtn1Text', 'editBtn2Text', 'editImage', 'editImageMobile',
    'editContentMotion', 'editBackgroundMotion', 'editPosition'
].forEach(function(id) {
    document.getElementById(id)?.addEventListener('input', renderBannerPreview);
    document.getElementById(id)?.addEventListener('change', renderBannerPreview);
});

function closeModal() {
    document.getElementById('editModal').classList.add('hidden');
}

document.getElementById('editForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?> + ': ' + err.message, 'error');
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

async function deleteBanner(id) {
    if (!confirm('<?php echo __('admin_confirm_delete'); ?>')) return;
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

function uploadImage(targetId = 'editImage') {
    bannerImageUploadTarget = targetId;
    document.getElementById('imageFileInput').click();
}

function pickImageFromMedia(targetId = 'editImage') {
    openMediaPicker(function(url) {
        setBannerImage(targetId, url);
    });
}

function openSettingsModal() {
    document.getElementById('settingsModal').classList.remove('hidden');
}

function closeSettingsModal() {
    document.getElementById('settingsModal').classList.add('hidden');
}

document.getElementById('settingsForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage(<?php echo json_encode(__('save_success'), JSON_UNESCAPED_UNICODE); ?>);
        closeSettingsModal();
    } else {
        showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
});

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    const formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        const response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            setBannerImage(bannerImageUploadTarget, data.data.url);
            showMessage('<?php echo __('admin_success'); ?>');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('<?php echo __('admin_fail'); ?>', 'error');
    }

    this.value = '';
});
</script>
<?php endif; ?>

<?php if ($tab === 'groups'): ?>
<!-- ========== 分组管理 ========== -->

<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex justify-between items-center">
        <p class="text-sm text-gray-500"><?php echo e(__('bn_groups_intro')); ?></p>
        <button onclick="openGroupModal()" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
            <i class="ti ti-plus text-base"></i>
            <?php echo e(__('bn_add_group')); ?>
        </button>
    </div>
</div>

<div class="bg-white rounded-lg shadow">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_name'); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo e(__('bn_slug')); ?></th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase"><?php echo __('label_shortcode'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('bn_size_col')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('bn_autoplay')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo e(__('admin_banner')); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_status'); ?></th>
                    <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase"><?php echo __('admin_action'); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($groups as $g):
                    $bannerCount = bannerGroupModel()->getBannerCount($g['slug'], $_viewLang);
                    $groupListUrl = '/admin/banner.php?position=' . rawurlencode((string) $g['slug']) . ($_lang['qsAmp'] ?? '');
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-medium">
                        <a href="<?php echo e($groupListUrl); ?>" class="inline-flex items-center gap-1 text-gray-800 hover:text-primary" title="<?php echo e(__('bn_open_group')); ?>">
                            <?php echo e($g['name']); ?>
                            <i class="ti ti-chevron-right text-sm"></i>
                        </a>
                    </td>
                    <td class="px-4 py-3">
                        <a href="<?php echo e($groupListUrl); ?>" title="<?php echo e(__('bn_open_group')); ?>">
                            <code class="text-sm bg-gray-100 hover:bg-blue-50 hover:text-primary px-1.5 py-0.5 rounded"><?php echo e($g['slug']); ?></code>
                        </a>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            <code class="text-sm bg-blue-50 text-blue-700 px-2 py-1 rounded">[banner-<?php echo e($g['slug']); ?>]</code>
                            <button onclick="copyShortcode('<?php echo e($g['slug']); ?>')" class="text-gray-400 hover:text-primary" title="<?php echo e(__('bn_copy_shortcode')); ?>">
                                <i class="ti ti-copy text-base"></i>
                            </button>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600">
                        <div><?php echo $g['height_pc']; ?> / <?php echo $g['height_mobile']; ?>px</div>
                        <?php
                        $groupHeightMode = (string) ($g['height_mode'] ?? (!empty($g['fullscreen']) ? 'screen' : 'fixed'));
                        $groupHeightModeKey = match ($groupHeightMode) {
                            'cover-header' => 'blox_banner_height_cover_header',
                            'screen' => 'blox_banner_height_screen',
                            default => 'blox_banner_height_fixed',
                        };
                        ?>
                        <div class="text-xs text-gray-400 mt-1"><?php echo e(__($groupHeightModeKey)); ?></div>
                    </td>
                    <td class="px-4 py-3 text-center text-sm text-gray-600">
                        <div><?php echo $g['autoplay_delay'] > 0 ? ($g['autoplay_delay'] / 1000) . 's' : e(__('bn_off')); ?></div>
                        <div class="text-xs text-gray-400 mt-1">
                            <?php echo e(__(($g['effect'] ?? 'fade') === 'slide' ? 'blox_banner_effect_slide' : 'blox_banner_effect_fade')); ?>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="<?php echo e($groupListUrl); ?>" class="text-primary hover:underline text-sm"><?php echo str_replace(':n', (string) $bannerCount, e(__('shome_n_images'))); ?></a>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick="toggleGroupStatus(<?php echo $g['id']; ?>, this)"
                                class="text-xs px-2 py-1 rounded <?php echo $g['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                            <?php echo $g['status'] ? e(__('admin_enabled')) : e(__('admin_disabled')); ?>
                        </button>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <button onclick='openGroupModal(<?php echo json_encode($g, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>)'
                                class="text-primary hover:underline text-sm mr-3 inline-flex items-center gap-1 align-middle">
                            <i class="ti ti-adjustments-horizontal text-sm"></i>
                            <?php echo e(__('bn_edit_group_settings')); ?></button>
                        <button onclick="deleteGroup(<?php echo $g['id']; ?>)" title="<?php echo __('admin_delete'); ?>"
                                class="text-red-600 hover:text-red-700 inline-flex items-center align-middle">
                            <i class="ti ti-trash text-base"></i>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($groups)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-8 text-center text-gray-500"><?php echo __('empty_no_groups'); ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- 分组编辑弹窗 -->
<div id="groupModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-black/50" onclick="closeGroupModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[calc(100vh-2rem)] overflow-y-auto"
         role="dialog" aria-modal="true" aria-labelledby="groupModalTitle" tabindex="-1">
        <div class="sticky top-0 z-10 bg-white px-6 py-4 border-b flex justify-between items-center">
            <h3 class="font-bold text-gray-800" id="groupModalTitle"><?php echo __('banner_add_group'); ?></h3>
            <button type="button" onclick="closeGroupModal()" class="text-gray-400 hover:text-gray-600" title="<?php echo e(__('admin_close')); ?>" aria-label="<?php echo e(__('admin_close')); ?>">&times;</button>
        </div>
        <form id="groupForm" class="p-6 space-y-4">
            <input type="hidden" name="action" value="group_save">
            <input type="hidden" name="id" id="groupId" value="0">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_group_name')); ?> <span class="text-red-500">*</span></label>
                <input type="text" name="name" id="groupName" class="w-full border rounded px-4 py-2" required placeholder="<?php echo e(__('bn_ph_group_name')); ?>">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('bn_slug')); ?> (slug) <span class="text-red-500">*</span></label>
                <input type="text" name="slug" id="groupSlug" class="w-full border rounded px-4 py-2" required placeholder="<?php echo e(__('bn_ph_slug')); ?>" data-x="ge" pattern="[a-z0-9][a-z0-9-]*" aria-describedby="groupSlugTip groupSlugLockedTip">
                <p id="groupSlugTip" class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_slug_tip')); ?></p>
                <p id="groupSlugLockedTip" class="hidden text-xs text-amber-600 mt-1"><?php echo e(__('bn_slug_locked_tip')); ?></p>
            </div>

            <div class="border-t pt-4 space-y-4">
                <h4 class="font-medium text-gray-800 inline-flex items-center gap-2">
                    <i class="ti ti-adjustments-horizontal text-primary"></i>
                    <?php echo e(__('blox_banner_overall_settings')); ?>
                </h4>

                <div>
                    <label class="block text-gray-700 mb-2"><?php echo e(__('blox_banner_height_mode')); ?></label>
                    <input type="hidden" name="height_mode" id="groupHeightMode" value="fixed">
                    <input type="hidden" name="fullscreen" id="groupFullscreen" value="0">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2" role="group" aria-label="<?php echo e(__('blox_banner_height_mode')); ?>">
                        <button type="button" data-group-choice="height" data-value="fixed" onclick="setGroupChoice('height', 'fixed')"
                                class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false">
                            <i class="ti ti-arrows-vertical"></i><?php echo e(__('blox_banner_height_fixed')); ?>
                        </button>
                        <button type="button" data-group-choice="height" data-value="screen" onclick="setGroupChoice('height', 'screen')"
                                class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false">
                            <i class="ti ti-maximize"></i><?php echo e(__('blox_banner_height_screen')); ?>
                        </button>
                        <button type="button" data-group-choice="height" data-value="cover-header" onclick="setGroupChoice('height', 'cover-header')"
                                class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false">
                            <i class="ti ti-layout-navbar-expand"></i><?php echo e(__('blox_banner_height_cover_header')); ?>
                        </button>
                    </div>
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('bn_group_height_mode_help')); ?></p>
                    <div id="groupFirstVisibleStatus" class="hidden mt-2 px-3 py-2 border rounded text-xs items-start gap-2" role="status">
                        <i id="groupFirstVisibleIcon" class="ti mt-0.5"></i>
                        <span id="groupFirstVisibleText"></span>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div id="groupHeightPcWrap">
                        <label class="block text-gray-700 mb-1"><?php echo e(__('blox_banner_height_pc')); ?></label>
                        <input type="number" name="height_pc" id="groupHeightPc" value="500" min="200" max="1600" step="10" class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo e(__('blox_banner_height_mobile')); ?></label>
                        <input type="number" name="height_mobile" id="groupHeightMobile" value="250" min="180" max="1200" step="10" class="w-full border rounded px-4 py-2">
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 mb-2"><?php echo e(__('blox_banner_effect')); ?></label>
                    <input type="hidden" name="effect" id="groupEffect" value="fade">
                    <div class="grid grid-cols-2 gap-2" role="group" aria-label="<?php echo e(__('blox_banner_effect')); ?>">
                        <button type="button" data-group-choice="effect" data-value="fade" onclick="setGroupChoice('effect', 'fade')"
                                class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false">
                            <i class="ti ti-layers-subtract"></i><?php echo e(__('blox_banner_effect_fade')); ?>
                        </button>
                        <button type="button" data-group-choice="effect" data-value="slide" onclick="setGroupChoice('effect', 'slide')"
                                class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false">
                            <i class="ti ti-arrows-horizontal"></i><?php echo e(__('blox_banner_effect_slide')); ?>
                        </button>
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 mb-2"><?php echo e(__('blox_banner_content_motion')); ?></label>
                    <input type="hidden" name="content_motion" id="groupContentMotion" value="none">
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-2" role="group" aria-label="<?php echo e(__('blox_banner_content_motion')); ?>">
                        <button type="button" data-group-choice="content" data-value="none" onclick="setGroupChoice('content', 'none')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-ban"></i><?php echo e(__('blox_banner_motion_none')); ?></button>
                        <button type="button" data-group-choice="content" data-value="fade-up" onclick="setGroupChoice('content', 'fade-up')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-arrow-up"></i><?php echo e(__('blox_banner_motion_fade_up')); ?></button>
                        <button type="button" data-group-choice="content" data-value="slide-left" onclick="setGroupChoice('content', 'slide-left')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-arrow-left"></i><?php echo e(__('blox_banner_motion_slide_left')); ?></button>
                        <button type="button" data-group-choice="content" data-value="slide-right" onclick="setGroupChoice('content', 'slide-right')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-arrow-right"></i><?php echo e(__('blox_banner_motion_slide_right')); ?></button>
                        <button type="button" data-group-choice="content" data-value="zoom-in" onclick="setGroupChoice('content', 'zoom-in')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-zoom-in"></i><?php echo e(__('blox_banner_motion_zoom_in')); ?></button>
                        <button type="button" data-group-choice="content" data-value="clip-reveal" onclick="setGroupChoice('content', 'clip-reveal')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-scan"></i><?php echo e(__('blox_banner_motion_clip_reveal')); ?></button>
                        <button type="button" data-group-choice="content" data-value="blur-up" onclick="setGroupChoice('content', 'blur-up')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-blur"></i><?php echo e(__('blox_banner_motion_blur_up')); ?></button>
                        <button type="button" data-group-choice="content" data-value="pop-in" onclick="setGroupChoice('content', 'pop-in')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-sparkles"></i><?php echo e(__('blox_banner_motion_pop_in')); ?></button>
                    </div>
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('blox_banner_content_motion_help')); ?></p>
                </div>

                <div>
                    <label class="block text-gray-700 mb-2"><?php echo e(__('blox_banner_background_motion')); ?></label>
                    <input type="hidden" name="background_motion" id="groupBackgroundMotion" value="none">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2" role="group" aria-label="<?php echo e(__('blox_banner_background_motion')); ?>">
                        <button type="button" data-group-choice="background" data-value="none" onclick="setGroupChoice('background', 'none')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-ban"></i><?php echo e(__('blox_banner_motion_none')); ?></button>
                        <button type="button" data-group-choice="background" data-value="zoom-in" onclick="setGroupChoice('background', 'zoom-in')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-zoom-in"></i><?php echo e(__('blox_banner_background_zoom_in')); ?></button>
                        <button type="button" data-group-choice="background" data-value="zoom-out" onclick="setGroupChoice('background', 'zoom-out')" class="border rounded px-3 py-2 text-sm inline-flex items-center justify-center gap-2 transition" aria-pressed="false"><i class="ti ti-zoom-out"></i><?php echo e(__('blox_banner_background_zoom_out')); ?></button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo e(__('blox_banner_autoplay')); ?></label>
                        <input type="number" name="autoplay_seconds" id="groupAutoplay" value="5" min="0" max="30" step="1" class="w-full border rounded px-4 py-2">
                        <p class="text-xs text-gray-400 mt-1"><?php echo e(__('blox_banner_autoplay_help')); ?></p>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo e(__('blox_banner_speed')); ?></label>
                        <input type="number" name="speed" id="groupSpeed" value="700" min="200" max="2000" step="100" class="w-full border rounded px-4 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo e(__('blox_banner_stagger')); ?></label>
                        <input type="number" name="stagger" id="groupStagger" value="120" min="0" max="600" step="20" class="w-full border rounded px-4 py-2">
                        <p class="text-xs text-gray-400 mt-1"><?php echo e(__('blox_banner_stagger_help')); ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <label class="flex items-center gap-3 cursor-pointer border rounded p-3">
                        <input type="checkbox" name="navigation" value="1" id="groupNavigation" class="w-4 h-4">
                        <span class="text-sm font-medium text-gray-700"><?php echo e(__('blox_banner_navigation')); ?></span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer border rounded p-3">
                        <input type="checkbox" name="pagination" value="1" id="groupPagination" class="w-4 h-4">
                        <span class="text-sm font-medium text-gray-700"><?php echo e(__('blox_banner_pagination')); ?></span>
                    </label>
                    <label class="flex items-center gap-3 cursor-pointer border rounded p-3">
                        <input type="checkbox" name="pause_hover" value="1" id="groupPauseHover" class="w-4 h-4">
                        <span class="text-sm font-medium text-gray-700"><?php echo e(__('blox_banner_pause_hover')); ?></span>
                    </label>
                </div>
            </div>

            <div class="sticky bottom-0 -mx-6 -mb-6 bg-white border-t px-6 py-4 flex flex-wrap justify-between gap-3">
                <a href="#" id="groupManageLink" class="hidden items-center gap-1 text-primary hover:underline text-sm">
                    <i class="ti ti-photo text-base"></i>
                    <?php echo e(__('bn_manage_group_banners')); ?>
                </a>
                <div class="flex gap-2 ml-auto">
                    <button type="button" onclick="closeGroupModal()" class="border px-4 py-2 rounded hover:bg-gray-100"><?php echo __('admin_cancel'); ?></button>
                    <button type="submit" id="groupSubmitButton" class="bg-primary hover:bg-secondary disabled:opacity-60 disabled:cursor-not-allowed text-white px-6 py-2 rounded inline-flex items-center gap-1">
                        <i class="ti ti-check text-base"></i>
                        <span id="groupSubmitLabel"><?php echo __("btn_save"); ?></span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script src="<?php echo e(assetVer('/assets/js/blox-dialog-focus.js')); ?>"></script>
<script>
function setGroupChoice(key, value) {
    var inputIds = {
        height: 'groupHeightMode',
        effect: 'groupEffect',
        content: 'groupContentMotion',
        background: 'groupBackgroundMotion'
    };
    var input = document.getElementById(inputIds[key]);
    if (!input) return;
    input.value = value;
    document.querySelectorAll('[data-group-choice="' + key + '"]').forEach(function(button) {
        var active = button.dataset.value === String(value);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
        button.classList.toggle('border-blue-500', active);
        button.classList.toggle('bg-blue-50', active);
        button.classList.toggle('text-blue-700', active);
        button.classList.toggle('border-gray-200', !active);
        button.classList.toggle('bg-white', !active);
        button.classList.toggle('text-gray-600', !active);
        button.classList.toggle('hover:border-blue-300', !active);
    });
    if (key === 'height') {
        document.getElementById('groupFullscreen').value = value === 'fixed' ? '0' : '1';
        groupFsSync();
    }
}

function openGroupModal(item = null) {
    document.getElementById('groupModalTitle').textContent = item ? '<?php echo __("banner_edit_group"); ?>' : '<?php echo __("banner_add_group"); ?>';
    document.getElementById('groupId').value = item?.id || 0;
    document.getElementById('groupName').value = item?.name || '';
    document.getElementById('groupSlug').value = item?.slug || '';
    document.getElementById('groupSlug').readOnly = !!item;
    document.getElementById('groupSlug').classList.toggle('bg-gray-100', !!item);
    document.getElementById('groupSlug').classList.toggle('text-gray-500', !!item);
    document.getElementById('groupSlugTip').classList.toggle('hidden', !!item);
    document.getElementById('groupSlugLockedTip').classList.toggle('hidden', !item);
    document.getElementById('groupHeightPc').value = item?.height_pc ?? 500;
    document.getElementById('groupHeightMobile').value = item?.height_mobile ?? 250;
    document.getElementById('groupAutoplay').value = item ? Math.round(Number(item.autoplay_delay || 0) / 1000) : 5;
    document.getElementById('groupSpeed').value = item?.speed ?? 700;
    document.getElementById('groupStagger').value = item?.stagger ?? 120;
    setGroupChoice('height', item?.height_mode || (item && Number(item.fullscreen) === 1 ? 'screen' : 'fixed'));
    setGroupChoice('effect', item?.effect || 'fade');
    setGroupChoice('content', item?.content_motion || 'none');
    setGroupChoice('background', item?.background_motion || 'none');
    updateGroupFirstVisibleStatus(item);
    document.getElementById('groupNavigation').checked = !item || !Object.prototype.hasOwnProperty.call(item, 'navigation') || Number(item.navigation) === 1;
    document.getElementById('groupPagination').checked = !item || !Object.prototype.hasOwnProperty.call(item, 'pagination') || Number(item.pagination) === 1;
    document.getElementById('groupPauseHover').checked = !item || !Object.prototype.hasOwnProperty.call(item, 'pause_hover') || Number(item.pause_hover) === 1;
    var manageLink = document.getElementById('groupManageLink');
    manageLink.classList.toggle('hidden', !item);
    manageLink.classList.toggle('inline-flex', !!item);
    manageLink.href = item
        ? '/admin/banner.php?position=' + encodeURIComponent(item.slug) + <?php echo json_encode($_lang['qsAmp'] ?? '', JSON_UNESCAPED_UNICODE); ?>
        : '#';
    var modal = document.getElementById('groupModal');
    modal.classList.remove('hidden');
    window.BloxDialogFocus?.open(modal, '#groupName');
}

function updateGroupFirstVisibleStatus(item) {
    var status = document.getElementById('groupFirstVisibleStatus');
    var icon = document.getElementById('groupFirstVisibleIcon');
    var text = document.getElementById('groupFirstVisibleText');
    if (!status || !icon || !text) return;

    status.className = 'hidden mt-2 px-3 py-2 border rounded text-xs items-start gap-2';
    if (!item) return;

    var isHomeGroup = item.slug === 'home';
    var isFirstVisible = <?php echo $homeFirstVisibleIsBanner ? 'true' : 'false'; ?>;
    var tone = 'border-gray-200 bg-gray-50 text-gray-600';
    var iconClass = 'ti-info-circle';
    var message = <?php echo json_encode(__('bn_group_not_home_banner'), JSON_UNESCAPED_UNICODE); ?>;

    if (isHomeGroup && isFirstVisible) {
        tone = 'border-green-200 bg-green-50 text-green-700';
        iconClass = 'ti-circle-check';
        message = <?php echo json_encode(__('bn_group_first_visible_yes'), JSON_UNESCAPED_UNICODE); ?>;
    } else if (isHomeGroup) {
        tone = 'border-amber-200 bg-amber-50 text-amber-700';
        iconClass = 'ti-alert-triangle';
        message = <?php echo json_encode(__('bn_group_first_visible_no'), JSON_UNESCAPED_UNICODE); ?>;
    }

    status.className = 'mt-2 px-3 py-2 border rounded text-xs flex items-start gap-2 ' + tone;
    icon.className = 'ti mt-0.5 ' + iconClass;
    text.textContent = message;
}

// 首屏模式保留原 PC 高度，切回固定高度即可继续调整。
function groupFsSync() {
    var mode = document.getElementById('groupHeightMode');
    var pc = document.getElementById('groupHeightPc');
    if (!mode || !pc) return;
    var fullscreen = mode.value !== 'fixed';
    pc.readOnly = fullscreen;
    var wrap = document.getElementById('groupHeightPcWrap');
    if (wrap) wrap.style.opacity = fullscreen ? '0.45' : '1';
}

function closeGroupModal() {
    var modal = document.getElementById('groupModal');
    modal.classList.add('hidden');
    window.BloxDialogFocus?.close(modal);
}

document.getElementById('groupModal')?.addEventListener('keydown', function(event) {
    window.BloxDialogFocus?.keydown(event, this, closeGroupModal);
});

document.getElementById('groupForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const submitButton = document.getElementById('groupSubmitButton');
    const submitLabel = document.getElementById('groupSubmitLabel');
    if (submitButton.disabled) return;
    submitButton.disabled = true;
    submitLabel.textContent = <?php echo json_encode(__('bn_saving'), JSON_UNESCAPED_UNICODE); ?>;
    this.setAttribute('aria-busy', 'true');
    const formData = new FormData(this);
    let saved = false;
    try {
        const response = await fetch('', { method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'} });
        const data = await safeJson(response);
        if (data.code === 0) {
            saved = true;
            showMessage('<?php echo __('admin_saved'); ?>');
            setTimeout(() => location.reload(), 1000);
        } else {
            showMessage(data.msg || <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    } finally {
        if (!saved) {
            submitButton.disabled = false;
            submitLabel.textContent = <?php echo json_encode(__('btn_save'), JSON_UNESCAPED_UNICODE); ?>;
            this.removeAttribute('aria-busy');
        }
    }
});

async function toggleGroupStatus(id, btn) {
    const formData = new FormData();
    formData.append('action', 'group_toggle');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        if (data.data.status) {
            btn.className = 'text-xs px-2 py-1 rounded bg-green-100 text-green-600';
            btn.textContent = '<?php echo __('admin_enabled'); ?>';
        } else {
            btn.className = 'text-xs px-2 py-1 rounded bg-gray-100 text-gray-500';
            btn.textContent = '<?php echo __('admin_disabled'); ?>';
        }
    }
}

async function deleteGroup(id) {
    if (!confirm(<?php echo json_encode(__('bn_del_group_confirm'), JSON_UNESCAPED_UNICODE); ?>)) return;
    const formData = new FormData();
    formData.append('action', 'group_delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(() => location.reload(), 1000);
    } else {
        showMessage(data.msg || <?php echo json_encode(__('admin_delete_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

function copyShortcode(slug) {
    var text = '[banner-' + slug + ']';
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(function() {
            showMessage(<?php echo json_encode(__('bn_shortcode_copied'), JSON_UNESCAPED_UNICODE); ?> + ': ' + text);
        });
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showMessage(<?php echo json_encode(__('bn_shortcode_copied'), JSON_UNESCAPED_UNICODE); ?> + ': ' + text);
    }
}

<?php if (is_array($editGroup)): ?>
openGroupModal(<?php echo json_encode($editGroup, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>);
<?php endif; ?>
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
