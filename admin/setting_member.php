<?php
/**
 * YikaiCMS - 会员设置
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

$pageTitle = __('member_settings');
$currentMenu = 'setting_member';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/member.php" class="px-6 py-3 text-sm font-medium text-gray-500 hover:text-gray-700 border-b-2 border-transparent hover:border-gray-300"><?php echo __('member_list'); ?></a>
        <a href="/admin/setting_member.php" class="px-6 py-3 text-sm font-medium border-b-2 border-primary text-primary"><?php echo __('member_settings'); ?></a>
    </div>
</div>

<form id="settingForm" class="space-y-6">
    <div class="bg-white rounded-lg shadow p-6 space-y-6">
        <h3 class="text-lg font-bold text-gray-800"><?php echo __('member_register_section'); ?></h3>

        <div class="flex items-center justify-between py-3 border-b">
            <div>
                <div class="font-medium text-gray-800"><?php echo __('member_allow_register'); ?></div>
                <div class="text-sm text-gray-500 mt-1"><?php echo __('member_allow_register_tip'); ?></div>
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
        <h3 class="text-lg font-bold text-gray-800"><?php echo __('member_download_section'); ?></h3>

        <div class="flex items-center justify-between py-3 border-b">
            <div>
                <div class="font-medium text-gray-800"><?php echo __('member_download_require_login'); ?></div>
                <div class="text-sm text-gray-500 mt-1"><?php echo __('member_download_require_login_tip'); ?></div>
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

    <!-- 会员前台地址（只读，方便复制） -->
    <?php $_siteUrl = rtrim((string) config('site_url', SITE_URL), '/'); ?>
    <div class="bg-white rounded-lg shadow p-6 space-y-4">
        <h3 class="text-lg font-bold text-gray-800"><?php echo __('member_frontend_urls'); ?></h3>
        <p class="text-sm text-gray-500"><?php echo __('member_frontend_urls_tip'); ?></p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('member_register_url'); ?></label>
                <div class="flex items-center gap-2">
                    <input type="text" id="memberRegUrl" readonly
                           value="<?php echo e($_siteUrl . '/member/register.php'); ?>"
                           class="flex-1 border rounded px-3 py-2 text-sm bg-gray-50 font-mono text-gray-700">
                    <button type="button" onclick="copyToClipboard('memberRegUrl', this)"
                            class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <?php echo __('admin_copy'); ?>
                    </button>
                    <a href="<?php echo e($_siteUrl . '/member/register.php'); ?>" target="_blank"
                       class="px-3 py-2 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        <?php echo __('admin_preview'); ?>
                    </a>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo __('member_login_url'); ?></label>
                <div class="flex items-center gap-2">
                    <input type="text" id="memberLoginUrl" readonly
                           value="<?php echo e($_siteUrl . '/member/login.php'); ?>"
                           class="flex-1 border rounded px-3 py-2 text-sm bg-gray-50 font-mono text-gray-700">
                    <button type="button" onclick="copyToClipboard('memberLoginUrl', this)"
                            class="px-3 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                        <?php echo __('admin_copy'); ?>
                    </button>
                    <a href="<?php echo e($_siteUrl . '/member/login.php'); ?>" target="_blank"
                       class="px-3 py-2 bg-blue-50 hover:bg-blue-100 text-blue-600 rounded text-sm inline-flex items-center gap-1">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        <?php echo __('admin_preview'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div>
        <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <?php echo __('btn_save_settings'); ?>
        </button>
    </div>
</form>

<script>
document.getElementById('settingForm').addEventListener('submit', function (e) {
    e.preventDefault();
    adminSave(this, { successMsg: '<?php echo __('admin_saved'); ?>' });
});

function copyToClipboard(elId, btn) {
    var el = document.getElementById(elId);
    if (!el) return;
    el.select(); el.setSelectionRange(0, 999);
    navigator.clipboard.writeText(el.value).then(function() {
        showMessage('<?php echo __('admin_copied'); ?>');
    }).catch(function() {
        try { document.execCommand('copy'); showMessage('<?php echo __('admin_copied'); ?>'); }
        catch (e) { showMessage('<?php echo __('admin_copy_failed'); ?>', 'error'); }
    });
    window.getSelection().removeAllRanges();
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
