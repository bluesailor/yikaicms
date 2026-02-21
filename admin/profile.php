<?php
/**
 * Yikai CMS - 个人设置
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
        $message = '资料更新成功';
        $admin = userModel()->find($adminId);
    }

    if ($action === 'change_password') {
        $oldPassword = post('old_password');
        $newPassword = post('new_password');
        $confirmPassword = post('confirm_password');

        if (!password_verify($oldPassword, $admin['password'])) {
            $error = '当前密码错误';
        } elseif (strlen($newPassword) < 8 || !preg_match('/[a-zA-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $error = '新密码至少8位，且必须包含字母和数字';
        } elseif ($newPassword !== $confirmPassword) {
            $error = '两次密码不一致';
        } else {
            userModel()->setPassword($adminId, $newPassword);
            adminLog('profile', 'change_password', '修改密码');
            $message = '密码修改成功';
        }
    }
}

$pageTitle = '个人设置';
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
            <h2 class="font-bold text-gray-800">基本信息</h2>
        </div>
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="action" value="update_info">

            <div>
                <label class="block text-gray-700 mb-1">用户名</label>
                <input type="text" value="<?php echo e($admin['username']); ?>" disabled
                       class="w-full border rounded px-4 py-2 bg-gray-100">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">昵称</label>
                <input type="text" name="nickname" value="<?php echo e($admin['nickname']); ?>"
                       class="w-full border rounded px-4 py-2">
            </div>

            <div>
                <label class="block text-gray-700 mb-1">邮箱</label>
                <input type="email" name="email" value="<?php echo e($admin['email']); ?>"
                       class="w-full border rounded px-4 py-2">
            </div>

            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                保存
            </button>
        </form>
    </div>

    <!-- 修改密码 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">修改密码</h2>
        </div>
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="action" value="change_password">

            <div>
                <label class="block text-gray-700 mb-1">当前密码</label>
                <div class="relative pwd-toggle">
                    <input type="password" name="old_password" required
                           class="w-full border rounded px-4 py-2 pr-10">
                    <button type="button" onclick="togglePassword(this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <svg class="eye-open w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        <svg class="eye-closed w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">新密码</label>
                <div class="relative pwd-toggle">
                    <input type="password" name="new_password" required minlength="6"
                           class="w-full border rounded px-4 py-2 pr-10">
                    <button type="button" onclick="togglePassword(this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <svg class="eye-open w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        <svg class="eye-closed w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-gray-700 mb-1">确认密码</label>
                <div class="relative pwd-toggle">
                    <input type="password" name="confirm_password" required
                           class="w-full border rounded px-4 py-2 pr-10">
                    <button type="button" onclick="togglePassword(this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                        <svg class="eye-open w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        <svg class="eye-closed w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                修改密码
            </button>
        </form>
    </div>
</div>

<!-- 登录信息 -->
<div class="bg-white rounded-lg shadow mt-6">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800">登录信息</h2>
    </div>
    <div class="p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <span class="text-gray-500">最后登录：</span>
                <span class="text-gray-800"><?php echo $admin['last_login_time'] ? date('Y-m-d H:i:s', (int)$admin['last_login_time']) : '-'; ?></span>
            </div>
            <div>
                <span class="text-gray-500">登录IP：</span>
                <span class="text-gray-800"><?php echo e($admin['last_login_ip'] ?: '-'); ?></span>
            </div>
            <div>
                <span class="text-gray-500">登录次数：</span>
                <span class="text-gray-800"><?php echo number_format((int)$admin['login_count']); ?></span>
            </div>
        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
