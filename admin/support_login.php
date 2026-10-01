<?php
/**
 * 官方技术支持一次性登录（见 includes/SupportAccess.php）。
 *
 * 链接形如 /admin/support_login.php#<令牌>：令牌在 # 后面，不会出现在服务器访问日志、Referer 里；
 * 页面脚本把它放进表单，点「登录」才提交——聊天软件、邮件的链接预览抓取不会把一次性链接用掉。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

// 公开演示站不开放任何支持登录
if ((defined('DEMO_MODE') && DEMO_MODE) || (defined('DEMO_SANDBOX') && DEMO_SANDBOX)) {
    http_response_code(404);
    exit;
}

$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    $token = is_string($_POST['support_token'] ?? null) ? $_POST['support_token'] : '';
    unset($_POST['support_token']);   // 别让令牌进操作日志的请求数据
    $lockout = checkLoginThrottle();
    if ($lockout > 0) {
        $message = __('login_throttle_locked', ['minutes' => $lockout]);
    } else {
        $user = SupportAccess::redeem($token);
        if ($user !== null) {
            clearLoginFailure();
            completeAdminLogin($user);
            SupportAccess::enforce();
            adminLog('support', 'login', '官方技术支持通过一次性链接登录');
            redirect('/admin/upgrade_online.php');
        }
        recordLoginFailure();
        $message = __('support_login_invalid');
    }
}
$lang = getLang();
?>
<!doctype html>
<html lang="<?= e($lang) ?>"<?= htmlDirAttr($lang) ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(__('support_login_title')) ?> - <?= e(config('site_name', 'YikaiCMS')) ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
</head>
<body class="min-h-screen bg-gray-100 flex items-center justify-center p-4">
<main class="w-full max-w-md bg-white rounded-lg shadow p-6 space-y-4">
    <h1 class="text-lg font-semibold text-gray-900"><?= e(__('support_login_title')) ?></h1>
    <p class="text-sm text-gray-600"><?= e(__('support_login_hint')) ?></p>
    <?php if ($message !== ''): ?><p role="alert" class="text-sm rounded bg-red-50 text-red-700 p-3"><?= e($message) ?></p><?php endif; ?>
    <form method="post" id="supportLogin">
        <?= csrfField() ?>
        <input type="hidden" name="support_token" id="supportToken">
        <button type="submit" class="w-full bg-primary text-white rounded px-4 py-2 font-medium hover:opacity-90"><?= e(__('support_login_button')) ?></button>
    </form>
</main>
<script>
(function () {
    var token = location.hash.slice(1);
    document.getElementById('supportToken').value = token;
    // 读完就从地址栏抹掉，浏览器历史里不留令牌
    if (token && history.replaceState) history.replaceState(null, '', location.pathname);
}());
</script>
</body>
</html>
