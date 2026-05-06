<?php
/**
 * ikaiCMS - 后台登录
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

// 已登录则跳转
if (!empty($_SESSION['admin_id'])) {
    redirect('/admin/');
}

// ============================================================
// 语言切换 / 浏览器检测
// ============================================================
$supportedLangs = ['zh-CN' => '中文', 'en' => 'English'];

// 1. URL ?lang=xxx 切换 (重定向去掉参数,避免后续刷新冲突)
if (isset($_GET['lang']) && isset($supportedLangs[$_GET['lang']])) {
    $_SESSION['login_lang'] = $_GET['lang'];
    redirect('/admin/login.php');
}

// 2. 没设置过 → 从浏览器 Accept-Language 第一项检测
if (empty($_SESSION['login_lang'])) {
    $accept = strtolower(trim((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));
    $first = trim(explode(';', explode(',', $accept)[0] ?? '')[0]);
    if (str_starts_with($first, 'zh')) {
        $_SESSION['login_lang'] = 'zh-CN';
    } elseif (str_starts_with($first, 'en')) {
        $_SESSION['login_lang'] = 'en';
    }
    // 不匹配则不设, getLang() 走原有 admin_lang / 默认逻辑
}

$error = '';

// 处理登录
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $username = post('username');
    $password = post('password');

    if (empty($username) || empty($password)) {
        $error = __('login_empty_fields');
    } else {
        $result = doLogin($username, $password);
        if ($result['success']) {
            unset($_SESSION['login_lang']); // 登录成功 -> 交回 admin_lang 设置
            redirect('/admin/');
        } else {
            $error = $result['message'];
        }
    }
}
?>
<?php $currentLang = function_exists('getLang') ? getLang() : 'ja'; ?>
<!DOCTYPE html>
<html lang="<?php echo e($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('login_page_title'); ?> - <?php echo e(config('site_name', 'ikaiCMS')); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-md">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-800"><?php echo e(config('site_name', 'ikaiCMS')); ?></h1>
                <p class="text-gray-500 mt-2"><?php echo __('login_subtitle'); ?></p>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 p-4 rounded-lg mb-6">
                <?php echo e($error); ?>
            </div>
            <?php endif; ?>

            <form method="post" class="space-y-6">
                <?php echo csrfField(); ?>
                <div>
                    <label class="block text-gray-700 mb-2"><?php echo __('login_username'); ?></label>
                    <input type="text" name="username" required autofocus
                           class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                           placeholder="<?php echo __('login_username_placeholder'); ?>">
                </div>

                <div>
                    <label class="block text-gray-700 mb-2"><?php echo __('login_password'); ?></label>
                    <div class="relative pwd-toggle">
                        <input type="password" name="password" required
                               class="w-full border border-gray-300 rounded-lg px-4 py-3 pr-11 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                               placeholder="<?php echo __('login_password_placeholder'); ?>">
                        <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 cursor-pointer">
                            <svg class="eye-open w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            <svg class="eye-closed w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="w-full bg-primary hover:bg-secondary text-white font-bold py-3 rounded-lg transition cursor-pointer">
                    <?php echo __('login_button'); ?>
                </button>
            </form>
        </div>

        <div class="text-center mt-6 text-gray-500 text-sm">
            &copy; <?php echo date('Y'); ?> <?php echo e(config('site_name', 'ikaiCMS')); ?>
        </div>

        <!-- 语言切换 -->
        <div class="flex items-center justify-center gap-2 mt-4">
            <?php
            $flagFile = ['zh-CN' => 'cn', 'en' => 'us'];
            foreach ($supportedLangs as $code => $label):
                $active = $currentLang === $code;
            ?>
                <a href="?lang=<?php echo e($code); ?>"
                   hreflang="<?php echo e($code); ?>"
                   class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border text-sm transition <?php echo $active
                       ? 'border-primary text-primary bg-blue-50 font-medium'
                       : 'border-gray-200 text-gray-500 hover:border-gray-300 hover:bg-gray-50'; ?>">
                    <img src="/assets/icons/flags/<?php echo $flagFile[$code]; ?>.svg"
                         alt="" aria-hidden="true"
                         class="w-5 h-3.5 rounded-sm shrink-0 ring-1 ring-gray-200">
                    <span><?php echo $label; ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<script>
function togglePassword(el) {
    var wrap = el.closest('.pwd-toggle');
    var input = wrap.querySelector('input');
    var isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    wrap.querySelector('.eye-open').classList.toggle('hidden', !isHidden);
    wrap.querySelector('.eye-closed').classList.toggle('hidden', isHidden);
}
</script>
</body>
</html>
