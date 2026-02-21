<?php
/**
 * Yikai CMS - 会员设置
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('setting');

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    settingModel()->set('allow_member_register', post('allow_member_register', '0'));
    settingModel()->set('download_require_login', post('download_require_login', '0'));
    adminLog('setting', 'update', '更新会员设置');
    success();
}

$pageTitle = '会员设置';
$currentMenu = 'setting_member';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/member.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300">会员列表</a>
        <a href="/admin/setting_member.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary">会员设置</a>
    </div>
</div>

<form id="settingForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow p-6 space-y-6">
        <h3 class="text-lg font-bold text-gray-800">会员注册</h3>

        <div class="flex items-center justify-between py-3 border-b">
            <div>
                <div class="font-medium text-gray-800">允许会员注册</div>
                <div class="text-sm text-gray-500 mt-1">关闭后前台将无法注册新会员</div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="hidden" name="allow_member_register" value="0">
                <input type="checkbox" name="allow_member_register" value="1"
                       class="sr-only peer"
                       <?php echo config('allow_member_register') === '1' ? 'checked' : ''; ?>>
                <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-primary/50 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
            </label>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 space-y-6">
        <h3 class="text-lg font-bold text-gray-800">下载权限</h3>

        <div class="flex items-center justify-between py-3 border-b">
            <div>
                <div class="font-medium text-gray-800">下载需要登录</div>
                <div class="text-sm text-gray-500 mt-1">开启后，下载文件需要会员登录</div>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="hidden" name="download_require_login" value="0">
                <input type="checkbox" name="download_require_login" value="1"
                       class="sr-only peer"
                       <?php echo config('download_require_login') === '1' ? 'checked' : ''; ?>>
                <div class="w-11 h-6 bg-gray-200 peer-focus:ring-2 peer-focus:ring-primary/50 rounded-full peer peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
            </label>
        </div>
    </div>

    <div>
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            保存设置
        </button>
    </div>
</form>

<script>
document.getElementById('settingForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('保存成功');
    } else {
        showMessage(data.msg, 'error');
    }
});
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
