<?php
/**
 * YikaiCMS - 个人设置
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

$adminId = $_SESSION['admin_id'];
$admin = userModel()->find($adminId);

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'update_info') {
        $nickname = post('nickname');
        $email = post('email');

        userModel()->updateById($adminId, [
            'nickname' => $nickname,
            'email' => $email,
            'updated_at' => time(),
        ]);

        $_SESSION['admin_nickname'] = $nickname;
        $message = __('profile_updated');
        $admin = userModel()->find($adminId);
    }

    if ($action === 'totp_setup') {
        // 第一步：生成密钥，暂存 session，页面展示二维码等待确认
        require_once ROOT_PATH . '/includes/Totp.php';
        $_SESSION['totp_setup_secret'] = Totp::generateSecret();
    }

    if ($action === 'totp_enable') {
        require_once ROOT_PATH . '/includes/Totp.php';
        $setupSecret = (string) ($_SESSION['totp_setup_secret'] ?? '');
        $code = post('totp_code');
        if ($setupSecret === '') {
            $error = __('totp_session_expired');
        } elseif (!Totp::verify($setupSecret, $code)) {
            $error = __('totp_code_invalid');
        } else {
            userModel()->updateById($adminId, ['totp_secret' => $setupSecret, 'updated_at' => time()]);
            unset($_SESSION['totp_setup_secret']);
            adminLog('profile', 'totp_enable', '启用两步验证');
            $message = __('totp_enabled_msg');
            $admin = userModel()->find($adminId);
        }
    }

    if ($action === 'totp_disable') {
        if (!password_verify(post('current_password'), $admin['password'])) {
            $error = __('totp_pwd_wrong');
        } else {
            userModel()->updateById($adminId, ['totp_secret' => '', 'updated_at' => time()]);
            adminLog('profile', 'totp_disable', '关闭两步验证');
            $message = __('totp_disabled_msg');
            $admin = userModel()->find($adminId);
        }
    }

    if ($action === 'change_password') {
        $oldPassword = post('old_password');
        $newPassword = post('new_password');
        $confirmPassword = post('confirm_password');

        if (!password_verify($oldPassword, $admin['password'])) {
            $error = __('profile_pwd_wrong');
        } elseif (strlen($newPassword) < 8 || !preg_match('/[a-zA-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $error = __('profile_pwd_rule');
        } elseif ($newPassword !== $confirmPassword) {
            $error = __('profile_pwd_mismatch');
        } else {
            userModel()->setPassword($adminId, $newPassword);
            adminLog('profile', 'change_password', '修改密码');
            $message = __('profile_pwd_changed');
        }
    }
}

$pageTitle = __('profile_title');
$currentMenu = '';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php if ($message): ?>
<div class="bg-green-50 text-green-600 p-4 rounded-lg mb-6"><?php echo e($message); ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 text-red-600 p-4 rounded-lg mb-6"><?php echo e($error); ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- 基本信息 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('admin_basic_info'); ?></h2>
        </div>
        <form method="post" class="p-6 space-y-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="update_info">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('admin_username')); ?></label>
                <input type="text" value="<?php echo e($admin['username']); ?>" disabled
                       class="w-full border rounded px-4 py-2 bg-gray-100">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('admin_nickname')); ?></label>
                <input type="text" name="nickname" value="<?php echo e($admin['nickname']); ?>"
                       class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo e(__('admin_email')); ?></label>
                <input type="email" name="email" value="<?php echo e($admin['email']); ?>"
                       class="w-full border rounded px-4 py-2">
            </div>

            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1 cursor-pointer">
                <i class="ti ti-check text-base"></i>
                <?php echo __("btn_save"); ?>
            </button>
        </form>
    </div>

    <!-- 修改密码 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('profile_change_pwd'); ?></h2>
        </div>
        <form method="post" class="p-6 space-y-4">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="change_password">

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('profile_current_pwd'); ?></label>
                <div class="relative pwd-toggle">
                    <input type="password" name="old_password" required
                           class="w-full border rounded px-4 py-2 pr-10">
                    <button type="button" onclick="togglePassword(this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="ti ti-eye text-lg eye-open hidden"></i>
                        <i class="ti ti-eye-off text-lg eye-closed"></i>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('profile_new_pwd'); ?></label>
                <div class="relative pwd-toggle">
                    <input type="password" name="new_password" required minlength="6"
                           class="w-full border rounded px-4 py-2 pr-10">
                    <button type="button" onclick="togglePassword(this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="ti ti-eye text-lg eye-open hidden"></i>
                        <i class="ti ti-eye-off text-lg eye-closed"></i>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1"><?php echo __('profile_confirm_pwd'); ?></label>
                <div class="relative pwd-toggle">
                    <input type="password" name="confirm_password" required
                           class="w-full border rounded px-4 py-2 pr-10">
                    <button type="button" onclick="togglePassword(this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <i class="ti ti-eye text-lg eye-open hidden"></i>
                        <i class="ti ti-eye-off text-lg eye-closed"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1 cursor-pointer">
                <i class="ti ti-key text-base"></i>
                <?php echo e(__('profile_change_pwd')); ?>
            </button>
        </form>
    </div>
</div>

<!-- 两步验证 -->
<?php
$totpEnabled = !empty($admin['totp_secret']);
$setupSecret = (string) ($_SESSION['totp_setup_secret'] ?? '');
if ($setupSecret !== '') {
    require_once ROOT_PATH . '/includes/Totp.php';
    $otpauthUri = Totp::otpauthUri($setupSecret, (string) $admin['username'], (string) config('site_name', 'YikaiCMS'));
}
?>
<div class="bg-white rounded-lg shadow mt-6">
    <div class="px-6 py-4 border-b flex items-center justify-between">
        <h2 class="font-bold text-gray-800"><?php echo e(__('totp_title')); ?></h2>
        <span class="text-xs px-2 py-1 rounded <?php echo $totpEnabled ? 'bg-green-50 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
            <?php echo $totpEnabled ? __('totp_on') : __('totp_off'); ?>
        </span>
    </div>
    <div class="p-6">
        <?php if ($totpEnabled): ?>
        <p class="text-gray-600 text-sm mb-4"><?php echo e(__('totp_bound_desc')); ?></p>
        <form method="post" class="flex items-end gap-3" onsubmit="return confirm(<?php echo json_encode(__('totp_disable_confirm'), JSON_UNESCAPED_UNICODE); ?>);">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="totp_disable">
            <div>
                <label class="block text-gray-700 mb-1 text-sm"><?php echo e(__('profile_current_pwd')); ?></label>
                <input type="password" name="current_password" required class="border rounded px-4 py-2">
            </div>
            <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded transition cursor-pointer">
                <?php echo e(__('totp_disable')); ?>
            </button>
        </form>
        <?php elseif ($setupSecret !== ''): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <p class="text-gray-600 text-sm mb-3"><?php echo e(__('totp_step1')); ?></p>
                <div id="totpQr" class="inline-block p-3 bg-white border rounded"></div>
                <p class="mt-3 text-sm">
                    <a href="<?php echo e($otpauthUri); ?>" class="text-primary hover:underline"><?php echo e(__('totp_open_local')); ?></a>
                </p>
                <p class="mt-2 text-xs text-gray-400 break-all"><?php echo e(__('totp_manual_key')); ?><code class="bg-gray-50 px-1"><?php echo e($setupSecret); ?></code></p>
            </div>
            <form method="post" class="space-y-4">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="totp_enable">
                <p class="text-gray-600 text-sm"><?php echo e(__('totp_step2')); ?></p>
                <input type="text" name="totp_code" required inputmode="numeric" autocomplete="one-time-code" maxlength="7"
                       class="w-full border rounded px-4 py-3 text-center text-2xl tracking-[0.5em]" placeholder="000000" autofocus>
                <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition cursor-pointer">
                    <?php echo e(__('totp_confirm_bind')); ?>
                </button>
            </form>
        </div>
        <script src="/assets/qrcode/qrcode.js"></script>
        <script>
        (function () {
            var qr = qrcode(0, 'M');
            qr.addData(<?php echo json_encode($otpauthUri, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG); ?>);
            qr.make();
            document.getElementById('totpQr').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2 });
        })();
        </script>
        <?php else: ?>
        <p class="text-gray-600 text-sm mb-4"><?php echo e(__('totp_intro')); ?></p>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="totp_setup">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1 cursor-pointer">
                <i class="ti ti-shield-lock text-base"></i>
                <?php echo e(__('totp_enable')); ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- 登录信息 -->
<div class="bg-white rounded-lg shadow mt-6">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800"><?php echo e(__('profile_login_info')); ?></h2>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <span class="text-gray-500"><?php echo e(__('profile_last_login')); ?></span>
                <span class="text-gray-800"><?php echo $admin['last_login_time'] ? date('Y-m-d H:i:s', (int)$admin['last_login_time']) : '-'; ?></span>
            </div>
            <div>
                <span class="text-gray-500"><?php echo e(__('profile_login_ip')); ?></span>
                <span class="text-gray-800"><?php echo e($admin['last_login_ip'] ?: '-'); ?></span>
            </div>
            <div>
                <span class="text-gray-500"><?php echo e(__('profile_login_count')); ?></span>
                <span class="text-gray-800"><?php echo number_format((int)$admin['login_count']); ?></span>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
