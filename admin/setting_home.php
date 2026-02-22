<?php
/**
 * Yikai CMS - 首页设置（区块拖拽排序）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $settings = $_POST['settings'] ?? [];
    settingModel()->saveBatch($settings);

    // 更新页脚导航中的"首页"链接
    $homeInFooterNav = (int)($_POST['home_footer_nav'] ?? 0);
    $homeUrl = '/';
    $footerNav = json_decode(config('footer_nav') ?: '[]', true) ?: [];

    // 移除已有的"首页"链接
    foreach ($footerNav as &$group) {
        $group['links'] = array_values(array_filter($group['links'] ?? [], function($link) use ($homeUrl) {
            return ($link['url'] ?? '') !== $homeUrl;
        }));
    }
    unset($group);

    if ($homeInFooterNav) {
        $homeText = trim($settings['nav_home_text'] ?? '') ?: config('nav_home_text', '') ?: '首页';
        if (empty($footerNav)) {
            $footerNav[] = ['title' => '', 'links' => []];
        }
        $footerNav[0]['links'][] = ['name' => $homeText, 'url' => '/', 'target' => '_self'];
    }

    // 清理空分组
    $footerNav = array_values(array_filter($footerNav, function($g) {
        return !empty($g['links']);
    }));
    settingModel()->set('footer_nav', json_encode($footerNav, JSON_UNESCAPED_UNICODE));

    adminLog('setting', 'update', '更新首页设置');
    success();
}

// 获取全部 home 组设置
$allSettings = settingModel()->getByGroup('home');

// 构建 key => row 映射
$settingsMap = [];
foreach ($allSettings as $item) {
    $settingsMap[$item['key']] = $item;
}

// 获取首页展示的栏目（用于动态生成区块）
$homeChannelRows = channelModel()->query(
    "SELECT id, name FROM " . channelModel()->tableName() . " WHERE is_home = 1 AND parent_id = 0 AND status = 1 ORDER BY sort_order ASC"
);

// 区块配置
$blocksConfig = json_decode($settingsMap['home_blocks_config']['value'] ?? '', true);
if (!$blocksConfig) {
    $blocksConfig = [
        ['type' => 'banner', 'enabled' => true],
        ['type' => 'about', 'enabled' => true],
        ['type' => 'stats', 'enabled' => true],
        ['type' => 'channels', 'enabled' => true],
        ['type' => 'testimonials', 'enabled' => true],
        ['type' => 'advantage', 'enabled' => true],
        ['type' => 'cta', 'enabled' => true],
    ];
}

// 迁移旧的 channels 整块为独立的 channel:{id} 区块
$channelIdsInConfig = [];
$migratedConfig = [];
foreach ($blocksConfig as $b) {
    if ($b['type'] === 'channels') {
        foreach ($homeChannelRows as $hcRow) {
            $migratedConfig[] = ['type' => 'channel:' . $hcRow['id'], 'enabled' => $b['enabled'] ?? true];
            $channelIdsInConfig[] = (int)$hcRow['id'];
        }
    } else {
        $migratedConfig[] = $b;
        if (str_starts_with($b['type'], 'channel:')) {
            $channelIdsInConfig[] = (int)substr($b['type'], 8);
        }
    }
}
// 新增的首页栏目自动追加
foreach ($homeChannelRows as $hcRow) {
    if (!in_array((int)$hcRow['id'], $channelIdsInConfig)) {
        $migratedConfig[] = ['type' => 'channel:' . $hcRow['id'], 'enabled' => true];
    }
}
$blocksConfig = $migratedConfig;

// 客户评价数据
$testimonialsData = json_decode($settingsMap['home_testimonials']['value'] ?? '[]', true) ?: [];

// 栏目图标 SVG path
$channelIconPath = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>';

// 区块元数据
$blockMeta = [
    'banner' => [
        'title' => 'Banner轮播图',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>',
        'tip'   => '轮播图内容在 <a href="/admin/banner.php" class="text-primary hover:underline">轮播图管理</a> 中编辑',
        'keys'  => [],
    ],
    'about' => [
        'title' => '关于我们',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
        'bg_default' => '#ffffff',
        'keys'  => ['home_about_layout', 'home_about_content', 'home_about_image', 'home_about_tag_title', 'home_about_tag_desc'],
    ],
    'stats' => [
        'title' => '数据统计',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>',
        'bg_default' => '#111827',
        'keys'  => ['home_stat_bg', 'home_stat_1_num', 'home_stat_1_text', 'home_stat_2_num', 'home_stat_2_text', 'home_stat_3_num', 'home_stat_3_text', 'home_stat_4_num', 'home_stat_4_text'],
    ],
    'testimonials' => [
        'title'  => '客户评价',
        'icon'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>',
        'bg_default' => '#f9fafb',
        'editor' => 'testimonials',
        'keys'   => ['home_testimonials_title', 'home_testimonials_desc'],
    ],
    'advantage' => [
        'title' => '我们的优势',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
        'bg_default' => '#1f2937',
        'text_light' => true,
        'keys'  => ['home_advantage_desc', 'home_adv_1_icon', 'home_adv_1_title', 'home_adv_1_desc', 'home_adv_2_icon', 'home_adv_2_title', 'home_adv_2_desc', 'home_adv_3_icon', 'home_adv_3_title', 'home_adv_3_desc', 'home_adv_4_icon', 'home_adv_4_title', 'home_adv_4_desc'],
    ],
    'cta' => [
        'title' => '行动号召',
        'icon'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>',
        'bg_default' => config('primary_color', '#3B82F6'),
        'text_light' => true,
        'keys'  => ['home_cta_title', 'home_cta_desc'],
    ],
];

// 动态添加各栏目区块的元数据
foreach ($homeChannelRows as $hcRow) {
    $blockMeta['channel:' . $hcRow['id']] = [
        'title' => $hcRow['name'],
        'icon'  => $channelIconPath,
        'bg_default' => '#f9fafb',
        'tip'   => '在 <a href="/admin/channel.php?action=edit&id=' . (int)$hcRow['id'] . '" class="text-primary hover:underline">栏目管理</a> 中编辑此栏目内容',
        'keys'  => [],
    ];
}

$pageTitle = '首页设置';
$currentMenu = 'setting_home';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <p class="text-gray-500">拖拽调整版块顺序，开关控制显示/隐藏，展开编辑版块内容。</p>
</div>

<form id="settingForm">
    <!-- 导航首页文字 -->
    <div class="bg-white rounded-lg shadow mb-6 max-w-6xl">
        <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                <label class="text-gray-700 text-sm">
                    导航「首页」文字
                    <span class="text-gray-400 text-xs block">留空则显示默认"首页"</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[nav_home_text]"
                           value="<?php echo e(config('nav_home_text', '')); ?>"
                           class="w-full border rounded px-3 py-2 text-sm" placeholder="首页">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-center">
                <label class="text-gray-700 text-sm">菜单位置</label>
                <div class="md:col-span-3 flex gap-4">
                    <label class="flex items-center">
                        <input type="hidden" name="settings[nav_home_show]" value="0">
                        <input type="checkbox" name="settings[nav_home_show]" value="1" <?php echo config('nav_home_show', '1') !== '0' ? 'checked' : ''; ?> class="mr-2">
                        主菜单
                    </label>
                    <label class="flex items-center">
                        <?php
                        $homeInFooter = false;
                        $footerNavCheck = json_decode(config('footer_nav') ?: '[]', true) ?: [];
                        foreach ($footerNavCheck as $fGroup) {
                            foreach (($fGroup['links'] ?? []) as $fLink) {
                                if (($fLink['url'] ?? '') === '/') { $homeInFooter = true; break 2; }
                            }
                        }
                        ?>
                        <input type="hidden" name="home_footer_nav" value="0">
                        <input type="checkbox" name="home_footer_nav" value="1" <?php echo $homeInFooter ? 'checked' : ''; ?> class="mr-2">
                        页脚导航
                    </label>
                </div>
            </div>
        </div>
    </div>
    <input type="hidden" name="settings[home_blocks_config]" id="blocksConfigJson">
    <input type="hidden" name="settings[home_testimonials]" id="testimonialsJson">

    <div id="blocksContainer" class="space-y-3 max-w-6xl">
        <?php foreach ($blocksConfig as $block):
            $type = $block['type'] ?? '';
            $meta = $blockMeta[$type] ?? null;
            if (!$meta) continue;
            $enabled = !empty($block['enabled']);
        ?>
        <div class="block-card bg-white rounded-lg shadow" data-type="<?php echo $type; ?>" x-data="{ expanded: false }">
            <!-- 卡片头部 -->
            <div class="flex items-center gap-3 px-4 py-3">
                <!-- 拖拽手柄 -->
                <div class="block-drag-handle cursor-grab text-gray-300 hover:text-gray-500 flex-shrink-0" title="拖拽排序">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M7 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM7 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 8a2 2 0 1 0 0 4 2 2 0 0 0 0-4zM13 14a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"></path>
                    </svg>
                </div>
                <!-- 图标 -->
                <svg class="w-5 h-5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <?php echo $meta['icon']; ?>
                </svg>
                <!-- 标题 -->
                <span class="font-medium text-gray-800 flex-1"><?php echo $meta['title']; ?></span>
                <!-- 开关 -->
                <label class="inline-flex items-center cursor-pointer flex-shrink-0" @click.stop>
                    <input type="checkbox" class="block-toggle sr-only peer" <?php echo $enabled ? 'checked' : ''; ?>>
                    <div class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary"></div>
                </label>
                <!-- 展开/折叠 -->
                <button type="button" class="text-gray-400 hover:text-gray-600 flex-shrink-0 p-1 transition" @click="expanded = !expanded">
                    <svg class="w-5 h-5 transition-transform" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                    </svg>
                </button>
            </div>

            <!-- 展开内容 -->
            <div x-show="expanded" x-collapse class="border-t">
                <div class="p-5 space-y-4">
                    <?php if (!empty($meta['tip'])): ?>
                    <div class="text-sm text-gray-500 bg-gray-50 rounded px-4 py-3">
                        <?php echo $meta['tip']; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 背景设置 -->
                    <?php if ($type !== 'banner'):
                        $blockBgColor = $block['bg_color'] ?? '';
                        $bgDefault = $meta['bg_default'] ?? '';
                        $bgPickerVal = preg_match('/^#[0-9a-fA-F]{3,8}$/', $blockBgColor) ? $blockBgColor : ($bgDefault ?: '#ffffff');
                        $blockTextLight = isset($block['text_light']) ? !empty($block['text_light']) : !empty($meta['text_light']);
                        $blockLayout = $block['layout'] ?? 'container';
                        // 渐变色解析
                        $isGradient = str_starts_with($blockBgColor, 'linear-gradient');
                        $bgMode = $isGradient ? 'gradient' : 'solid';
                        $gradFrom = '#3B82F6'; $gradTo = '#8B5CF6'; $gradDir = 'to right';
                        if ($isGradient && preg_match('/linear-gradient\(\s*([^,]+)\s*,\s*(#[0-9a-fA-F]{3,8})\s*,\s*(#[0-9a-fA-F]{3,8})\s*\)/', $blockBgColor, $gm)) {
                            $gradDir = trim($gm[1]); $gradFrom = $gm[2]; $gradTo = $gm[3];
                        }
                    ?>
                    <div class="bg-gray-50 rounded-lg p-4">
                        <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-3">版块样式</h4>
                        <div class="grid grid-cols-1 md:grid-cols-6 gap-3">
                            <div>
                                <label class="text-xs text-gray-500 block mb-1">背景图片</label>
                                <div class="flex gap-1">
                                    <input type="text" class="block-bg-image flex-1 border rounded px-2 py-1.5 text-xs"
                                           value="<?php echo e($block['bg_image'] ?? ''); ?>" placeholder="图片URL">
                                    <button type="button" class="block-bg-upload bg-gray-100 hover:bg-gray-200 text-gray-500 px-2 rounded" title="上传">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"></path></svg>
                                    </button>
                                </div>
                            </div>
                            <div x-data="{ bgMode: '<?php echo $bgMode; ?>' }" class="md:col-span-2">
                                <input type="hidden" class="block-bg-mode" :value="bgMode">
                                <div class="flex items-center gap-2 mb-1">
                                    <label class="text-xs text-gray-500">背景色</label>
                                    <div class="flex gap-0.5 ml-auto">
                                        <button type="button" @click="bgMode='solid'"
                                                :class="bgMode==='solid' ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500 hover:bg-gray-300'"
                                                class="text-[10px] leading-none px-1.5 py-1 rounded transition">单色</button>
                                        <button type="button" @click="bgMode='gradient'"
                                                :class="bgMode==='gradient' ? 'bg-primary text-white' : 'bg-gray-200 text-gray-500 hover:bg-gray-300'"
                                                class="text-[10px] leading-none px-1.5 py-1 rounded transition">渐变</button>
                                    </div>
                                </div>
                                <div class="flex gap-1" x-show="bgMode==='solid'">
                                    <input type="color" class="block-bg-picker w-8 h-[30px] border rounded cursor-pointer p-0.5"
                                           value="<?php echo e($bgPickerVal); ?>">
                                    <input type="text" class="block-bg-color flex-1 border rounded px-2 py-1.5 text-xs"
                                           value="<?php echo e($isGradient ? '' : $blockBgColor); ?>" placeholder="<?php echo $bgDefault ? '默认: ' . e($bgDefault) : '#hex'; ?>">
                                </div>
                                <div x-show="bgMode==='gradient'">
                                    <div class="flex gap-1 items-center">
                                        <input type="color" class="block-grad-from w-8 h-[30px] border rounded cursor-pointer p-0.5"
                                               value="<?php echo e($gradFrom); ?>">
                                        <span class="text-gray-300 text-xs">→</span>
                                        <input type="color" class="block-grad-to w-8 h-[30px] border rounded cursor-pointer p-0.5"
                                               value="<?php echo e($gradTo); ?>">
                                        <select class="block-grad-dir flex-1 border rounded px-1 py-1.5 text-xs bg-white">
                                            <option value="to right" <?php echo $gradDir === 'to right' ? 'selected' : ''; ?>>→ 水平</option>
                                            <option value="to bottom" <?php echo $gradDir === 'to bottom' ? 'selected' : ''; ?>>↓ 垂直</option>
                                            <option value="to bottom right" <?php echo $gradDir === 'to bottom right' ? 'selected' : ''; ?>>↘ 对角</option>
                                            <option value="135deg" <?php echo $gradDir === '135deg' ? 'selected' : ''; ?>>↗ 反对角</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1">透明度</label>
                                <div class="flex items-center gap-2">
                                    <input type="range" class="block-bg-opacity flex-1 accent-primary" min="0" max="100"
                                           value="<?php echo (int)($block['bg_opacity'] ?? 100); ?>">
                                    <span class="block-bg-opacity-val text-xs text-gray-500 w-8 text-right"><?php echo (int)($block['bg_opacity'] ?? 100); ?>%</span>
                                </div>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1">文字颜色</label>
                                <label class="inline-flex items-center gap-2 cursor-pointer mt-1">
                                    <input type="checkbox" class="block-text-light sr-only peer" <?php echo $blockTextLight ? 'checked' : ''; ?>>
                                    <div class="relative w-9 h-5 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary"></div>
                                    <span class="text-xs text-gray-500">浅色文字</span>
                                </label>
                            </div>
                            <div>
                                <label class="text-xs text-gray-500 block mb-1">内容宽度</label>
                                <select class="block-layout w-full border rounded px-2 py-1.5 text-xs bg-white">
                                    <option value="container" <?php echo $blockLayout === 'container' ? 'selected' : ''; ?>>居中容器</option>
                                    <option value="full" <?php echo $blockLayout === 'full' ? 'selected' : ''; ?>>100%全屏</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php // 渲染普通设置项 ?>
                    <?php foreach ($meta['keys'] as $settingKey):
                        $item = $settingsMap[$settingKey] ?? null;
                        if (!$item) continue;
                    ?>
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                        <label class="text-gray-700 text-sm pt-2">
                            <?php echo e($item['name']); ?>
                            <?php if ($item['tip']): ?>
                            <span class="text-gray-400 text-xs block"><?php echo e($item['tip']); ?></span>
                            <?php endif; ?>
                        </label>
                        <div class="md:col-span-3">
                            <?php if ($item['type'] === 'textarea'): ?>
                            <textarea name="settings[<?php echo e($item['key']); ?>]" rows="3"
                                      class="w-full border rounded px-3 py-2 text-sm"><?php echo e($item['value']); ?></textarea>

                            <?php elseif ($item['type'] === 'select' && !empty($item['options'])): ?>
                            <?php $opts = json_decode($item['options'], true) ?: []; ?>
                            <select name="settings[<?php echo e($item['key']); ?>]"
                                    class="w-full border rounded px-3 py-2 text-sm bg-white">
                                <?php foreach ($opts as $optVal => $optLabel): ?>
                                <option value="<?php echo e($optVal); ?>" <?php echo $item['value'] === $optVal ? 'selected' : ''; ?>>
                                    <?php echo e($optLabel); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>

                            <?php elseif ($item['type'] === 'icon'): ?>
                            <?php $allIcons = getAdvantageIcons(); $curIcon = $item['value'] ?: 'check-circle'; ?>
                            <div class="icon-picker flex flex-wrap gap-1.5">
                                <?php foreach ($allIcons as $iKey => $iData): ?>
                                <button type="button"
                                        class="icon-pick-btn w-9 h-9 flex items-center justify-center rounded border transition <?php echo $curIcon === $iKey ? 'border-primary bg-blue-50 text-primary' : 'border-gray-200 text-gray-500 hover:border-gray-300'; ?>"
                                        data-icon="<?php echo $iKey; ?>" title="<?php echo e($iData['label']); ?>">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><?php echo $iData['svg']; ?></svg>
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="settings[<?php echo e($item['key']); ?>]" class="icon-pick-value" value="<?php echo e($curIcon); ?>">

                            <?php elseif ($item['type'] === 'image'): ?>
                            <div class="flex gap-2 items-center">
                                <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                                       value="<?php echo e($item['value']); ?>"
                                       id="input_<?php echo e($item['key']); ?>"
                                       class="flex-1 border rounded px-3 py-2 text-sm">
                                <button type="button" onclick="uploadImage('<?php echo e($item['key']); ?>')"
                                        class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                                    上传
                                </button>
                                <button type="button" onclick="pickFromMedia('<?php echo e($item['key']); ?>')"
                                        class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm inline-flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    媒体库
                                </button>
                            </div>
                            <?php if ($item['value']): ?>
                            <img src="<?php echo e($item['value']); ?>" class="h-16 mt-2 rounded" id="preview_<?php echo e($item['key']); ?>">
                            <?php endif; ?>

                            <?php else: ?>
                            <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                                   value="<?php echo e($item['value']); ?>"
                                   class="w-full border rounded px-3 py-2 text-sm">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php // 客户评价编辑器 ?>
                    <?php if (($meta['editor'] ?? '') === 'testimonials'): ?>
                    <div class="border-t pt-4 mt-2">
                        <div class="flex items-center justify-between mb-3">
                            <h4 class="text-sm font-medium text-gray-700">评价列表</h4>
                            <button type="button" onclick="addTestimonial()" class="text-primary hover:underline text-sm">+ 添加评价</button>
                        </div>
                        <div id="testimonialsEditor" class="space-y-3">
                            <?php for ($ti = 0; $ti < max(count($testimonialsData), 1); $ti++):
                                $tm = $testimonialsData[$ti] ?? null;
                            ?>
                            <div class="tm-row p-3 border rounded-lg bg-gray-50">
                                <div class="flex gap-3 items-start">
                                    <span class="text-gray-300 text-sm pt-2 w-5 flex-shrink-0"><?php echo $ti + 1; ?></span>
                                    <div class="flex-shrink-0">
                                        <label class="text-xs text-gray-400 block mb-1">头像</label>
                                        <div class="flex items-center gap-1">
                                            <input type="text" class="tm-avatar border rounded px-2 py-1.5 text-xs w-28" placeholder="图片URL" value="<?php echo e($tm['avatar'] ?? ''); ?>">
                                            <button type="button" class="tm-upload-btn text-gray-400 hover:text-primary" title="上传头像">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="w-24 flex-shrink-0">
                                        <label class="text-xs text-gray-400 block mb-1">姓名</label>
                                        <input type="text" class="tm-name w-full border rounded px-2 py-1.5 text-sm" value="<?php echo e($tm['name'] ?? ''); ?>">
                                    </div>
                                    <div class="w-32 flex-shrink-0">
                                        <label class="text-xs text-gray-400 block mb-1">公司</label>
                                        <input type="text" class="tm-company w-full border rounded px-2 py-1.5 text-sm" value="<?php echo e($tm['company'] ?? ''); ?>">
                                    </div>
                                    <div class="flex-1">
                                        <label class="text-xs text-gray-400 block mb-1">评价内容</label>
                                        <input type="text" class="tm-content w-full border rounded px-2 py-1.5 text-sm" value="<?php echo e($tm['content'] ?? ''); ?>">
                                    </div>
                                    <button type="button" class="tm-remove text-gray-300 hover:text-red-400 pt-5 flex-shrink-0" title="删除">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 显示设置（合作伙伴 — 独立于区块系统） -->
    <?php
    $displayKeys = ['home_show_links', 'home_links_title'];
    $hasDisplayItems = false;
    foreach ($displayKeys as $dk) { if (isset($settingsMap[$dk])) { $hasDisplayItems = true; break; } }
    ?>
    <?php if ($hasDisplayItems): ?>
    <div class="max-w-6xl mt-6">
        <div class="bg-white rounded-lg shadow">
            <div class="px-5 py-3 border-b">
                <h3 class="text-sm font-medium text-gray-600">合作伙伴</h3>
            </div>
            <div class="p-5 space-y-4">
                <?php foreach ($displayKeys as $dk):
                    $item = $settingsMap[$dk] ?? null;
                    if (!$item) continue;
                ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                    <label class="text-gray-700 text-sm pt-2">
                        <?php echo e($item['name']); ?>
                        <?php if ($item['tip']): ?>
                        <span class="text-gray-400 text-xs block"><?php echo e($item['tip']); ?></span>
                        <?php endif; ?>
                    </label>
                    <div class="md:col-span-3">
                        <?php if ($item['type'] === 'select'): ?>
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="hidden" name="settings[<?php echo e($item['key']); ?>]" value="0">
                            <input type="checkbox" name="settings[<?php echo e($item['key']); ?>]" value="1"
                                   <?php echo $item['value'] === '1' ? 'checked' : ''; ?>
                                   class="sr-only peer">
                            <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                            <span class="ml-3 text-sm text-gray-600"><?php echo $item['value'] === '1' ? '显示' : '隐藏'; ?></span>
                        </label>
                        <?php else: ?>
                        <input type="text" name="settings[<?php echo e($item['key']); ?>]"
                               value="<?php echo e($item['value']); ?>"
                               class="w-full border rounded px-3 py-2 text-sm">
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="max-w-6xl mt-6">
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            保存设置
        </button>
    </div>
</form>

<input type="file" id="imageFileInput" class="hidden" accept="image/*">

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// SortableJS 拖拽排序
new Sortable(document.getElementById('blocksContainer'), {
    handle: '.block-drag-handle',
    animation: 200,
    ghostClass: 'opacity-30',
    chosenClass: 'shadow-lg'
});

// 收集区块配置
function collectBlocksConfig() {
    var cards = document.querySelectorAll('#blocksContainer .block-card');
    var config = [];
    cards.forEach(function(card) {
        var item = {
            type: card.dataset.type,
            enabled: card.querySelector('.block-toggle').checked
        };
        var bgImage = card.querySelector('.block-bg-image');
        var bgOpacity = card.querySelector('.block-bg-opacity');
        var textLight = card.querySelector('.block-text-light');
        if (bgImage && bgImage.value.trim()) item.bg_image = bgImage.value.trim();
        // 背景色：读取模式标记判断单色/渐变
        var bgModeInput = card.querySelector('.block-bg-mode');
        var bgColorVal = '';
        if (bgModeInput && bgModeInput.value === 'gradient') {
            var gf = card.querySelector('.block-grad-from').value;
            var gt = card.querySelector('.block-grad-to').value;
            var gd = card.querySelector('.block-grad-dir').value;
            bgColorVal = 'linear-gradient(' + gd + ', ' + gf + ', ' + gt + ')';
        } else {
            var bgColor = card.querySelector('.block-bg-color');
            if (bgColor) bgColorVal = bgColor.value.trim();
        }
        if (bgColorVal) item.bg_color = bgColorVal;
        if (bgOpacity) item.bg_opacity = parseInt(bgOpacity.value);
        if (textLight) item.text_light = textLight.checked;
        var layout = card.querySelector('.block-layout');
        if (layout) item.layout = layout.value;
        config.push(item);
    });
    document.getElementById('blocksConfigJson').value = JSON.stringify(config);
}

// 收集客户评价数据
function collectTestimonials() {
    var rows = document.querySelectorAll('#testimonialsEditor .tm-row');
    var data = [];
    rows.forEach(function(row) {
        var name = row.querySelector('.tm-name').value.trim();
        var content = row.querySelector('.tm-content').value.trim();
        if (!name && !content) return;
        data.push({
            avatar: row.querySelector('.tm-avatar').value.trim(),
            name: name,
            company: row.querySelector('.tm-company').value.trim(),
            content: content
        });
    });
    document.getElementById('testimonialsJson').value = JSON.stringify(data);
}

// 添加评价行
function addTestimonial() {
    var editor = document.getElementById('testimonialsEditor');
    var rows = editor.querySelectorAll('.tm-row');
    if (rows.length >= 6) { showMessage('最多添加6条评价', 'error'); return; }

    var idx = rows.length + 1;
    var div = document.createElement('div');
    div.className = 'tm-row p-3 border rounded-lg bg-gray-50';
    div.innerHTML = '<div class="flex gap-3 items-start">' +
        '<span class="text-gray-300 text-sm pt-2 w-5 flex-shrink-0">' + idx + '</span>' +
        '<div class="flex-shrink-0"><label class="text-xs text-gray-400 block mb-1">头像</label>' +
        '<div class="flex items-center gap-1"><input type="text" class="tm-avatar border rounded px-2 py-1.5 text-xs w-28" placeholder="图片URL">' +
        '<button type="button" class="tm-upload-btn text-gray-400 hover:text-primary" title="上传头像">' +
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>' +
        '</button></div></div>' +
        '<div class="w-24 flex-shrink-0"><label class="text-xs text-gray-400 block mb-1">姓名</label>' +
        '<input type="text" class="tm-name w-full border rounded px-2 py-1.5 text-sm"></div>' +
        '<div class="w-32 flex-shrink-0"><label class="text-xs text-gray-400 block mb-1">公司</label>' +
        '<input type="text" class="tm-company w-full border rounded px-2 py-1.5 text-sm"></div>' +
        '<div class="flex-1"><label class="text-xs text-gray-400 block mb-1">评价内容</label>' +
        '<input type="text" class="tm-content w-full border rounded px-2 py-1.5 text-sm"></div>' +
        '<button type="button" class="tm-remove text-gray-300 hover:text-red-400 pt-5 flex-shrink-0" title="删除">' +
        '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' +
        '</button></div>';
    editor.appendChild(div);
}

// 删除评价行（事件委托）
document.getElementById('testimonialsEditor')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.tm-remove');
    if (btn) {
        btn.closest('.tm-row').remove();
        // 重新编号
        var rows = this.querySelectorAll('.tm-row');
        rows.forEach(function(row, i) {
            row.querySelector('span').textContent = i + 1;
        });
    }
});

// 图标选择器
document.querySelectorAll('.icon-picker').forEach(function(picker) {
    picker.addEventListener('click', function(e) {
        var btn = e.target.closest('.icon-pick-btn');
        if (!btn) return;
        picker.querySelectorAll('.icon-pick-btn').forEach(function(b) {
            b.classList.remove('border-primary', 'bg-blue-50', 'text-primary');
            b.classList.add('border-gray-200', 'text-gray-500');
        });
        btn.classList.remove('border-gray-200', 'text-gray-500');
        btn.classList.add('border-primary', 'bg-blue-50', 'text-primary');
        var input = picker.nextElementSibling;
        if (input && input.classList.contains('icon-pick-value')) {
            input.value = btn.dataset.icon;
        }
    });
});

// 背景色色板同步 + 渐变控件 + 透明度滑块
document.getElementById('blocksContainer').addEventListener('input', function(e) {
    if (e.target.classList.contains('block-bg-picker')) {
        var textInput = e.target.closest('.block-card').querySelector('.block-bg-color');
        if (textInput) textInput.value = e.target.value;
    }
    if (e.target.classList.contains('block-bg-opacity')) {
        var valSpan = e.target.parentNode.querySelector('.block-bg-opacity-val');
        if (valSpan) valSpan.textContent = e.target.value + '%';
    }
});
// 渐变方向 select 的 change 事件也需要监听（select 不触发 input）
document.getElementById('blocksContainer').addEventListener('change', function(e) {
    if (e.target.classList.contains('block-grad-dir')) {
        // 无需额外操作，collectBlocksConfig 保存时直接读取
    }
});

// 图片上传
var currentImageKey = '';
function uploadImage(key) {
    currentImageKey = key;
    document.getElementById('imageFileInput').click();
}

// 区块背景图上传（事件委托）
var blockBgUploadTarget = null;
document.getElementById('blocksContainer').addEventListener('click', function(e) {
    var btn = e.target.closest('.block-bg-upload');
    if (btn) {
        blockBgUploadTarget = btn.closest('.block-card').querySelector('.block-bg-image');
        currentImageKey = '__block_bg__';
        document.getElementById('imageFileInput').click();
    }
});

// 评价头像上传（事件委托）
var tmUploadTarget = null;
document.getElementById('testimonialsEditor')?.addEventListener('click', function(e) {
    var btn = e.target.closest('.tm-upload-btn');
    if (btn) {
        tmUploadTarget = btn.closest('.tm-row').querySelector('.tm-avatar');
        currentImageKey = '__tm_avatar__';
        document.getElementById('imageFileInput').click();
    }
});

document.getElementById('imageFileInput').addEventListener('change', async function() {
    if (!this.files[0]) return;

    var formData = new FormData();
    formData.append('file', this.files[0]);
    formData.append('type', 'images');

    try {
        var response = await fetch('/admin/upload.php', { method: 'POST', body: formData });
        var data = await safeJson(response);

        if (data.code === 0) {
            if (currentImageKey === '__block_bg__' && blockBgUploadTarget) {
                blockBgUploadTarget.value = data.data.url;
                blockBgUploadTarget = null;
            } else if (currentImageKey === '__tm_avatar__' && tmUploadTarget) {
                tmUploadTarget.value = data.data.url;
                tmUploadTarget = null;
            } else {
                document.getElementById('input_' + currentImageKey).value = data.data.url;
                var preview = document.getElementById('preview_' + currentImageKey);
                if (!preview) {
                    preview = document.createElement('img');
                    preview.id = 'preview_' + currentImageKey;
                    preview.className = 'h-16 mt-2 rounded';
                    document.getElementById('input_' + currentImageKey).parentNode.parentNode.appendChild(preview);
                }
                preview.src = data.data.url;
            }
            showMessage('上传成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('上传失败', 'error');
    }

    this.value = '';
});

function pickFromMedia(key) {
    openMediaPicker(function(url) {
        document.getElementById('input_' + key).value = url;
        var preview = document.getElementById('preview_' + key);
        if (!preview) {
            preview = document.createElement('img');
            preview.id = 'preview_' + key;
            preview.className = 'h-16 mt-2 rounded';
            document.getElementById('input_' + key).parentNode.parentNode.appendChild(preview);
        }
        preview.src = url;
    });
}

// 表单提交
document.getElementById('settingForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    collectBlocksConfig();
    collectTestimonials();

    var formData = new FormData(this);

    try {
        var response = await fetch('', { method: 'POST', body: formData });
        var data = await safeJson(response);

        if (data.code === 0) {
            showMessage('保存成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
