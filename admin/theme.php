<?php
/**
 * Yikai CMS - 主题管理
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$currentMenu = 'theme';
$pageTitle = '主题管理';
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
                $message = '主题已切换为「' . e($slug) . '」';
                $messageType = 'success';
            } else {
                $message = '主题不存在 (dir: ' . $themeDir . ')';
                $messageType = 'error';
            }
        }
    } catch (\Throwable $ex) {
        $message = '错误: ' . $ex->getMessage() . ' in ' . $ex->getFile() . ':' . $ex->getLine();
        $messageType = 'error';
    }
}

// 获取所有主题
$themes = getThemes();
$currentTheme = currentTheme();

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800">主题管理</h1>
        <span class="text-sm text-gray-500">当前主题：<span class="font-medium text-primary"><?php echo e($currentTheme); ?></span></span>
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
        <p>暂无可用主题</p>
        <p class="text-xs mt-2">请在 <code class="bg-gray-100 px-1.5 py-0.5 rounded">themes/</code> 目录下放置主题文件夹</p>
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
            <!-- 预览图 -->
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
                    当前使用
                </div>
                <?php endif; ?>
            </div>

            <!-- 信息 -->
            <div class="p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="font-bold text-gray-800"><?php echo e($theme['name']); ?></h3>
                        <p class="text-sm text-gray-500 mt-1"><?php echo e($theme['description'] ?? ''); ?></p>
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

                <!-- 操作 -->
                <div class="mt-4 flex gap-2">
                    <?php if (!$isActive): ?>
                    <form method="POST" class="inline" onsubmit="return confirm('确定切换到此主题？')">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="activate">
                        <input type="hidden" name="slug" value="<?php echo e($theme['slug']); ?>">
                        <button type="submit" class="px-4 py-2 bg-primary text-white text-sm rounded-lg hover:opacity-90 transition cursor-pointer">
                            启用主题
                        </button>
                    </form>
                    <?php else: ?>
                    <span class="px-4 py-2 bg-gray-100 text-gray-500 text-sm rounded-lg">已启用</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- 说明 -->
    <div class="mt-8 bg-gray-50 rounded-lg p-6 text-sm text-gray-500">
        <h3 class="font-medium text-gray-700 mb-2">安装新主题</h3>
        <ol class="list-decimal list-inside space-y-1">
            <li>将主题文件夹上传到 <code class="bg-white px-1.5 py-0.5 rounded border text-xs">themes/</code> 目录</li>
            <li>确保主题根目录包含 <code class="bg-white px-1.5 py-0.5 rounded border text-xs">theme.json</code> 配置文件</li>
            <li>刷新此页面，新主题将自动出现</li>
            <li>点击"启用主题"切换</li>
        </ol>
    </div>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
