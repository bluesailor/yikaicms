<?php
/**
 * ikaiCMS - 主题管理
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$currentMenu = 'theme';
$pageTitle = __('admin_theme');
$message = '';
$messageType = '';

// 处理主题切换
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $action = $_POST['action'];
        if ($action === 'activate') {
            $slug = $_POST['slug'] ?? '';
            $themeDir = ROOT_PATH . '/themes/' . basename($slug);
            if ($slug && is_dir($themeDir) && file_exists($themeDir . '/theme.json')) {
                settingModel()->set('current_theme', $slug);
                $message = __('theme_switched') . '「' . e($slug) . '」';
                $messageType = 'success';
            } else {
                $message = __('theme_not_found');
                $messageType = 'error';
            }
        }
    } catch (\Throwable $ex) {
        $message = 'Error: ' . $ex->getMessage();
        $messageType = 'error';
    }
}

$themes = getThemes();
$currentTheme = currentTheme();

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo __('admin_theme'); ?></h1>
        <span class="text-sm text-gray-500"><?php echo __('theme_current'); ?>：<span class="font-medium text-primary"><?php echo e($currentTheme); ?></span></span>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 px-4 py-3 rounded-lg text-sm <?php echo $messageType === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'; ?>">
        <?php echo e($message); ?>
    </div>
    <?php endif; ?>

    <?php if (empty($themes)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
        </svg>
        <p><?php echo __('theme_none'); ?></p>
        <p class="text-xs mt-2"><?php echo __('theme_none_hint'); ?></p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($themes as $theme):
            $isActive = ($theme['slug'] === $currentTheme);
            $screenshot = '';
            if (!empty($theme['screenshot'])) {
                $screenshotPath = ROOT_PATH . '/themes/' . $theme['slug'] . '/' . $theme['screenshot'];
                if (file_exists($screenshotPath)) {
                    $screenshot = '/themes/' . $theme['slug'] . '/' . $theme['screenshot'];
                }
            }
        ?>
        <div class="bg-white rounded-lg shadow overflow-hidden <?php echo $isActive ? 'ring-2 ring-primary' : ''; ?>">
            <div class="aspect-[16/10] bg-gray-100 relative overflow-hidden">
                <?php if ($screenshot): ?>
                <img src="<?php echo e($screenshot); ?>" alt="<?php echo e($theme['name']); ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center text-gray-300">
                    <svg class="w-20 h-20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                </div>
                <?php endif; ?>
                <?php if ($isActive): ?>
                <div class="absolute top-3 right-3 bg-primary text-white text-xs font-bold px-3 py-1 rounded-full">
                    <?php echo __('theme_active'); ?>
                </div>
                <?php endif; ?>
            </div>

            <div class="p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-bold text-gray-800"><?php echo e($theme['name']); ?></h3>
                        <p class="text-sm text-gray-500 mt-1"><?php
                            $lang = getLang();
                            $descKey = ($lang === 'en' && !empty($theme['description_en'])) ? 'description_en'
                                : (($lang === 'ja' && !empty($theme['description_ja'])) ? 'description_ja' : 'description');
                            echo e($theme[$descKey] ?? '');
                        ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-4 mt-3 text-xs text-gray-400">
                    <?php if (!empty($theme['version'])): ?>
                    <span>v<?php echo e($theme['version']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($theme['author'])): ?>
                    <span><?php echo e($theme['author']); ?></span>
                    <?php endif; ?>
                </div>

                <div class="mt-4 flex gap-2">
                    <?php if (!$isActive): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('<?php echo __('theme_confirm_switch'); ?>')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="slug" value="<?php echo e($theme['slug']); ?>">
                        <button type="submit" class="px-4 py-2 bg-primary text-white text-sm rounded-lg hover:opacity-90 transition cursor-pointer">
                            <?php echo __('theme_activate'); ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 text-gray-500 text-sm rounded-lg"><?php echo __('theme_activated'); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="mt-8 bg-gray-50 rounded-lg p-6 text-sm text-gray-500">
        <h3 class="font-medium text-gray-700 mb-2"><?php echo __('theme_install_title'); ?></h3>
        <ol class="list-decimal list-inside space-y-1">
            <li><?php echo __('theme_install_step1'); ?></li>
            <li><?php echo __('theme_install_step2'); ?></li>
            <li><?php echo __('theme_install_step3'); ?></li>
            <li><?php echo __('theme_install_step4'); ?></li>
        </ol>
    </div>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
