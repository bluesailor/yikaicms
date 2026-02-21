<?php
/**
 * Yikai CMS - 会员中心
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/init.php';

if (!isMemberLoggedIn()) {
    redirect('/member/login.php');
}

$member = memberModel()->find($_SESSION['member_id']);
if (!$member) {
    doMemberLogout();
    redirect('/member/login.php');
}

// 当前 tab
$tab = get('tab', 'profile');
if (!in_array($tab, ['profile', 'password'])) {
    $tab = 'profile';
}

$success = '';
$error = '';

// 处理资料更新
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    if ($action === 'update_profile') {
        $tab = 'profile';
        $nickname = trim(post('nickname'));
        $email = trim(post('email'));

        if (empty($nickname)) {
            $error = '请输入昵称';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '邮箱格式不正确';
        } elseif (!memberModel()->isEmailUnique($email, $member['id'])) {
            $error = '邮箱已被其他账号使用';
        } else {
            memberModel()->updateById($member['id'], [
                'nickname' => $nickname,
                'email'    => $email,
            ]);
            $_SESSION['member_nickname'] = $nickname;
            $_SESSION['member_email'] = $email;
            $member['nickname'] = $nickname;
            $member['email'] = $email;
            $success = '资料更新成功';
        }
    }

    if ($action === 'change_password') {
        $tab = 'password';
        $oldPassword = post('old_password');
        $newPassword = post('new_password');
        $confirmPassword = post('confirm_password');

        if (!password_verify($oldPassword, $member['password'])) {
            $error = '原密码不正确';
        } elseif (strlen($newPassword) < 8) {
            $error = '新密码至少8位';
        } elseif ($newPassword !== $confirmPassword) {
            $error = '两次密码不一致';
        } else {
            memberModel()->updateById($member['id'], [
                'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            ]);
            $success = '密码修改成功';
        }
    }
}

$pageTitle = '会员中心';
require_once ROOT_PATH . '/includes/header.php';
?>

<main class="flex-1">
<div class="container mx-auto px-4 py-10">
    <div class="flex flex-col md:flex-row gap-6 max-w-4xl mx-auto">

        <!-- 左侧菜单 -->
        <div class="w-full md:w-56 shrink-0">
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <!-- 会员头像+名称 -->
                <div class="p-5 border-b text-center">
                    <div class="w-16 h-16 rounded-full bg-primary/10 text-primary flex items-center justify-center text-2xl font-bold mx-auto mb-3">
                        <?php echo mb_substr($member['nickname'] ?: $member['username'], 0, 1); ?>
                    </div>
                    <div class="font-medium text-gray-800"><?php echo e($member['nickname'] ?: $member['username']); ?></div>
                    <div class="text-xs text-gray-400 mt-1"><?php echo e($member['email']); ?></div>
                </div>
                <!-- 导航 -->
                <nav class="py-2">
                    <a href="/member/profile.php?tab=profile"
                       class="flex items-center gap-3 px-5 py-3 text-sm transition <?php echo $tab === 'profile' ? 'text-primary bg-primary/5 font-medium border-r-2 border-primary' : 'text-gray-600 hover:bg-gray-50'; ?>">
                        <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                        </svg>
                        会员信息
                    </a>
                    <a href="/member/profile.php?tab=password"
                       class="flex items-center gap-3 px-5 py-3 text-sm transition <?php echo $tab === 'password' ? 'text-primary bg-primary/5 font-medium border-r-2 border-primary' : 'text-gray-600 hover:bg-gray-50'; ?>">
                        <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
                        </svg>
                        修改密码
                    </a>
                    <div class="border-t my-1"></div>
                    <a href="/member/logout.php"
                       class="flex items-center gap-3 px-5 py-3 text-sm text-red-500 hover:bg-red-50 transition">
                        <svg class="w-4.5 h-4.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                        </svg>
                        安全退出
                    </a>
                </nav>
            </div>
        </div>

        <!-- 右侧内容 -->
        <div class="flex-1 min-w-0">

            <?php if ($success): ?>
            <div class="bg-green-50 text-green-600 p-4 rounded-lg text-sm mb-6"><?php echo e($success); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 p-4 rounded-lg text-sm mb-6"><?php echo e($error); ?></div>
            <?php endif; ?>

            <?php if ($tab === 'profile'): ?>
            <!-- 会员信息 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-6">会员信息</h2>
                <form method="post" class="space-y-5">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="update_profile">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-600 mb-1 text-sm">用户名</label>
                            <input type="text" value="<?php echo e($member['username']); ?>" disabled
                                   class="w-full border border-gray-200 rounded px-4 py-2.5 bg-gray-50 text-gray-500">
                        </div>
                        <div>
                            <label class="block text-gray-600 mb-1 text-sm">注册时间</label>
                            <input type="text" value="<?php echo date('Y-m-d H:i', (int)$member['created_at']); ?>" disabled
                                   class="w-full border border-gray-200 rounded px-4 py-2.5 bg-gray-50 text-gray-500">
                        </div>
                    </div>

                    <div>
                        <label class="block text-gray-600 mb-1 text-sm">昵称</label>
                        <input type="text" name="nickname" value="<?php echo e($member['nickname']); ?>" required
                               class="w-full border border-gray-300 rounded px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div>
                        <label class="block text-gray-600 mb-1 text-sm">邮箱</label>
                        <input type="email" name="email" value="<?php echo e($member['email']); ?>" required
                               class="w-full border border-gray-300 rounded px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-600 mb-1 text-sm">最后登录时间</label>
                            <input type="text" value="<?php echo $member['last_login_time'] ? date('Y-m-d H:i', (int)$member['last_login_time']) : '-'; ?>" disabled
                                   class="w-full border border-gray-200 rounded px-4 py-2.5 bg-gray-50 text-gray-500">
                        </div>
                        <div>
                            <label class="block text-gray-600 mb-1 text-sm">登录次数</label>
                            <input type="text" value="<?php echo (int)$member['login_count']; ?> 次" disabled
                                   class="w-full border border-gray-200 rounded px-4 py-2.5 bg-gray-50 text-gray-500">
                        </div>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2.5 rounded transition inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            保存修改
                        </button>
                    </div>
                </form>
            </div>

            <?php elseif ($tab === 'password'): ?>
            <!-- 修改密码 -->
            <div class="bg-white rounded-lg shadow p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-6">修改密码</h2>
                <form method="post" class="space-y-5 max-w-md">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="change_password">

                    <div>
                        <label class="block text-gray-600 mb-1 text-sm">原密码</label>
                        <input type="password" name="old_password" required
                               class="w-full border border-gray-300 rounded px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div>
                        <label class="block text-gray-600 mb-1 text-sm">新密码</label>
                        <input type="password" name="new_password" required
                               class="w-full border border-gray-300 rounded px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                               placeholder="至少8位">
                    </div>

                    <div>
                        <label class="block text-gray-600 mb-1 text-sm">确认新密码</label>
                        <input type="password" name="confirm_password" required
                               class="w-full border border-gray-300 rounded px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent">
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2.5 rounded transition inline-flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                            修改密码
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php require_once ROOT_PATH . '/includes/footer.php'; ?>
