<?php
/**
 * Yikai CMS - 邮件设置
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
    $action = post('action', 'save');

    if ($action === 'test') {
        // 测试发送邮件
        $testEmail = post('test_email');
        if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            error('请输入有效的测试邮箱');
        }

        // 获取邮件配置
        $smtpHost = config('smtp_host');
        $smtpPort = (int)config('smtp_port', 465);
        $smtpUser = config('smtp_user');
        $smtpPass = config('smtp_pass');
        $smtpSecure = config('smtp_secure', 'ssl');
        $mailFrom = config('mail_from', $smtpUser);
        $mailName = config('mail_from_name', config('site_name'));

        if (!$smtpHost || !$smtpUser || !$smtpPass) {
            error('请先配置SMTP服务器信息');
        }

        // 发送测试邮件
        $result = sendMail(
            $testEmail,
            '测试邮件 - ' . config('site_name'),
            '<h2>邮件配置测试成功</h2><p>如果您收到这封邮件，说明邮件配置正确。</p><p>发送时间：' . date('Y-m-d H:i:s') . '</p>'
        );

        if ($result === true) {
            success([], '测试邮件发送成功，请检查收件箱');
        } else {
            error('发送失败：' . $result);
        }
    }

    // 保存设置
    $settings = $_POST['settings'] ?? [];

    foreach ($settings as $key => $value) {
        settingModel()->set($key, $value);
    }

    adminLog('setting', 'update', '更新邮件设置');
    success();
}

// 获取邮件设置
$settings = settingModel()->getByGroup('email');

// 转为键值对
$emailConfig = [];
foreach ($settings as $item) {
    $emailConfig[$item['key']] = $item['value'];
}

$pageTitle = '邮件设置';
$currentMenu = 'setting_email';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <p class="text-gray-500">配置SMTP邮件服务器，用于发送系统通知、表单提醒等邮件。</p>
</div>

<form id="settingForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">SMTP服务器配置</h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    SMTP服务器
                    <span class="text-gray-400 text-sm block">如：smtp.qq.com</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[smtp_host]"
                           value="<?php echo e($emailConfig['smtp_host'] ?? ''); ?>"
                           placeholder="smtp.example.com"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    SMTP端口
                    <span class="text-gray-400 text-sm block">SSL常用465，TLS常用587</span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[smtp_port]"
                           value="<?php echo e($emailConfig['smtp_port'] ?? '465'); ?>"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    加密方式
                    <span class="text-gray-400 text-sm block">推荐使用SSL</span>
                </label>
                <div class="md:col-span-3">
                    <select name="settings[smtp_secure]" class="w-full border rounded px-4 py-2">
                        <option value="ssl" <?php echo ($emailConfig['smtp_secure'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        <option value="tls" <?php echo ($emailConfig['smtp_secure'] ?? '') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                        <option value="" <?php echo ($emailConfig['smtp_secure'] ?? '') === '' ? 'selected' : ''; ?>>无</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    SMTP用户名
                    <span class="text-gray-400 text-sm block">通常是完整邮箱地址</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[smtp_user]"
                           value="<?php echo e($emailConfig['smtp_user'] ?? ''); ?>"
                           placeholder="your@email.com"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    SMTP密码
                    <span class="text-gray-400 text-sm block">QQ邮箱需使用授权码</span>
                </label>
                <div class="md:col-span-3">
                    <div class="relative pwd-toggle">
                        <input type="password" name="settings[smtp_pass]"
                               value="<?php echo e($emailConfig['smtp_pass'] ?? ''); ?>"
                               placeholder="密码或授权码"
                               class="w-full border rounded px-4 py-2 pr-10">
                        <button type="button" onclick="togglePassword(this)" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <svg class="eye-open w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                            <svg class="eye-closed w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">发件人设置</h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    发件人邮箱
                    <span class="text-gray-400 text-sm block">留空则使用SMTP用户名</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_from]"
                           value="<?php echo e($emailConfig['mail_from'] ?? ''); ?>"
                           placeholder="留空使用SMTP用户名"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    发件人名称
                    <span class="text-gray-400 text-sm block">收件人看到的发件人名称</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_from_name]"
                           value="<?php echo e($emailConfig['mail_from_name'] ?? ''); ?>"
                           placeholder="留空使用站点名称"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">通知设置</h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    管理员邮箱
                    <span class="text-gray-400 text-sm block">接收表单提交通知</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_admin]"
                           value="<?php echo e($emailConfig['mail_admin'] ?? ''); ?>"
                           placeholder="admin@example.com"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    表单提交通知
                    <span class="text-gray-400 text-sm block">有新表单提交时发送通知</span>
                </label>
                <div class="md:col-span-3">
                    <select name="settings[mail_notify_form]" class="w-full border rounded px-4 py-2">
                        <option value="1" <?php echo ($emailConfig['mail_notify_form'] ?? '0') === '1' ? 'selected' : ''; ?>>开启</option>
                        <option value="0" <?php echo ($emailConfig['mail_notify_form'] ?? '0') === '0' ? 'selected' : ''; ?>>关闭</option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex flex-wrap gap-4">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition">
                保存设置
            </button>
            <button type="button" onclick="testEmail()" class="bg-green-500 hover:bg-green-600 text-white px-8 py-2 rounded transition">
                发送测试邮件
            </button>
        </div>
    </div>
</form>

<!-- 测试邮件弹窗 -->
<div id="testModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800">发送测试邮件</h3>
            <button type="button" onclick="closeTestModal()" class="text-gray-400 hover:text-gray-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
        <div class="p-6">
            <p class="text-gray-500 mb-4">请先保存设置，然后输入接收测试邮件的邮箱地址：</p>
            <input type="email" id="testEmailInput" placeholder="your@email.com"
                   class="w-full border rounded px-4 py-2 mb-4">
            <button type="button" onclick="sendTestEmail()"
                    class="w-full bg-green-500 hover:bg-green-600 text-white py-2 rounded transition">
                发送测试
            </button>
        </div>
    </div>
</div>

<script>
document.getElementById('settingForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    const formData = new FormData(this);

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage('保存成功');
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
});

function testEmail() {
    document.getElementById('testModal').classList.remove('hidden');
    document.getElementById('testModal').classList.add('flex');
}

function closeTestModal() {
    document.getElementById('testModal').classList.add('hidden');
    document.getElementById('testModal').classList.remove('flex');
}

async function sendTestEmail() {
    const email = document.getElementById('testEmailInput').value;
    if (!email) {
        showMessage('请输入测试邮箱', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'test');
    formData.append('test_email', email);

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage(data.msg);
            closeTestModal();
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
