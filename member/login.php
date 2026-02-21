<?php
/**
 * Yikai CMS - 会员登录
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

// 已登录跳转
if (isMemberLoggedIn()) {
    redirect('/member/profile.php');
}

$error = '';
$redirectUrl = get('redirect', '/member/profile.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = post('username');
    $password = post('password');
    $redirectUrl = post('redirect', '/member/profile.php');

    $captcha = strtolower(trim(post('captcha', '')));
    $sessionCaptcha = $_SESSION['member_captcha'] ?? '';
    $captchaTime = $_SESSION['member_captcha_time'] ?? 0;
    unset($_SESSION['member_captcha'], $_SESSION['member_captcha_time']);

    if ($captcha === '' || $captcha !== $sessionCaptcha || (time() - $captchaTime) > 300) {
        $error = '验证码错误';
    } else {
        $result = doMemberLogin($username, $password);
        if ($result['success']) {
            redirect($redirectUrl);
        } else {
            $error = $result['message'];
        }
    }
}

$pageTitle = '会员登录';
require_once ROOT_PATH . '/includes/header.php';
?>

<main class="flex-1">
<div class="container mx-auto px-4 py-16">
    <div class="max-w-md mx-auto">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-800">会员登录</h1>
                <p class="text-gray-500 mt-2">登录您的账号</p>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 p-4 rounded-lg mb-6 text-sm">
                <?php echo e($error); ?>
            </div>
            <?php endif; ?>

            <form method="post" class="space-y-5">
                <?php echo csrfField(); ?>
                <input type="hidden" name="redirect" value="<?php echo e($redirectUrl); ?>">

                <div>
                    <label class="block text-gray-700 mb-2">用户名</label>
                    <input type="text" name="username" required autofocus
                           class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                           placeholder="请输入用户名" value="<?php echo e(post('username', '')); ?>">
                </div>

                <div>
                    <label class="block text-gray-700 mb-2">密码</label>
                    <input type="password" name="password" required
                           class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                           placeholder="请输入密码">
                </div>

                <div>
                    <label class="block text-gray-700 mb-2">验证码</label>
                    <div class="flex gap-3">
                        <input type="text" name="captcha" required maxlength="4" autocomplete="off"
                               class="flex-1 border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                               placeholder="请输入验证码">
                        <img src="/member/captcha.php" alt="验证码" class="h-12 rounded-lg cursor-pointer border"
                             onclick="this.src='/member/captcha.php?t='+Date.now()" title="点击刷新">
                    </div>
                </div>

                <button type="submit"
                        class="w-full bg-primary hover:bg-secondary text-white font-bold py-3 rounded-lg transition">
                    登 录
                </button>
            </form>

            <?php if (config('allow_member_register') === '1'): ?>
            <div class="text-center mt-6 text-gray-500 text-sm">
                还没有账号？<a href="/member/register.php" class="text-primary hover:underline">立即注册</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
