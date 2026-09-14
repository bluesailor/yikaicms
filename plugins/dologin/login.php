<?php
declare(strict_types=1);

if (!defined('ROOT_PATH')) exit('Access Denied');

require_once __DIR__ . '/compatibility.php';
if (!DoLoginCompatibility::currentSiteSupported()) {
    error(__('dl_cms_version_required'), 403);
}

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$message = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    $token = is_string($_POST['dologin_token'] ?? null) ? $_POST['dologin_token'] : '';
    // Never let the credential reach audit request payloads or later authentication hooks.
    unset($_POST['dologin_token']);
    $lockout = checkLoginThrottle();
    if ($lockout > 0) {
        $message = __('login_throttle_locked', ['minutes' => $lockout]);
    } else {
        try {
            $user = doLoginLinkModel()->redeem($token, getClientIp());
        } catch (Throwable $exception) {
            $user = null;
            error_log('Easy Login redemption unavailable.');
        }
        if ($user) {
            if (!empty($user['totp_secret'])) {
                session_regenerate_id(true);
                $_SESSION['2fa_pending'] = ['user_id' => (int) $user['id'], 'expires' => time() + 300];
                redirect('/admin/login.php');
            }
            clearLoginFailure();
            completeAdminLogin($user);
            unset($_SESSION['login_lang']);
            redirect('/admin/');
        }
        recordLoginFailure();
        $message = __('dl_invalid');
    }
}
?>
<!doctype html>
<html lang="<?= e(getLang()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(__('dl_title')) ?></title>
    <link rel="stylesheet" href="/plugins/dologin/style.css">
    <script src="/plugins/dologin/login.js" defer></script>
</head>
<body class="dl-login">
<main class="dl-card">
    <h1><?= e(__('dl_title')) ?></h1>
    <p><?= e(__('dl_confirm_hint')) ?></p>
    <?php if ($message !== ''): ?><p role="alert" class="dl-alert"><?= e($message) ?></p><?php endif; ?>
    <form method="post" action="/admin/login.php?dologin=1" id="dl-login-form">
        <?= csrfField() ?>
        <input type="hidden" name="dologin_token" id="dl-token" value="">
        <button type="submit" id="dl-confirm" disabled><?= e(__('dl_confirm')) ?></button>
    </form>
    <p id="dl-missing"><?= e(__('dl_missing')) ?></p>
    <noscript><p><?= e(__('dl_js_required')) ?></p></noscript>
    <a href="/admin/login.php"><?= e(__('dl_password_login')) ?></a>
</main>
</body>
</html>
