<?php
/**
 * ikaiCMS - 邮件配置
 *
 * SMTP 配置 + 邮件模板管理 + 测试发送
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// Tab 定义
$tabs = [
    'smtp' => [
        'icon'  => 'fa-server',
        'title' => 'SMTP 配置',
    ],
    'register' => [
        'icon'  => 'fa-user-plus',
        'title' => '会员注册',
        'hint'  => '{{username}} {{email}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_register_subject', 'mail_tpl_register_body'],
    ],
    'forgot' => [
        'icon'  => 'fa-key',
        'title' => '找回密码',
        'hint'  => '{{username}} {{email}} {{reset_link}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_forgot_subject', 'mail_tpl_forgot_body'],
    ],
    'reset' => [
        'icon'  => 'fa-lock',
        'title' => '重置密码',
        'hint'  => '{{username}} {{email}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_reset_subject', 'mail_tpl_reset_body'],
    ],
    'inquiry' => [
        'icon'  => 'fa-envelope-open-text',
        'title' => '询盘通知',
        'hint'  => '{{product_title}} {{name}} {{phone}} {{email}} {{company}} {{content}} {{ip}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_inquiry_subject', 'mail_tpl_inquiry_body'],
    ],
];

$activeTab = get('tab', 'smtp');
if (!isset($tabs[$activeTab])) $activeTab = 'smtp';

// ============================================================
// AJAX: 测试发送
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'test') {
    $testEmail = post('test_email');
    if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        error('请输入有效的测试邮箱');
    }

    $result = sendMail(
        $testEmail,
        '测试邮件 - ' . config('site_name'),
        '这是一封测试邮件，用于验证 SMTP 配置是否正确。' . "\n\n" . '发送时间：' . date('Y-m-d H:i:s')
    );

    if ($result === true) {
        success([], '测试邮件发送成功，请检查收件箱');
    } else {
        error('发送失败：' . $result);
    }
}

// ============================================================
// POST 保存
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action', 'save') === 'save') {
    $settings = $_POST['settings'] ?? [];
    foreach ($settings as $key => $value) {
        settingModel()->set($key, $value);
    }

    $tab = post('_save_tab', 'smtp');
    adminLog('setting', 'update', '更新邮件设置: ' . ($tabs[$tab]['title'] ?? 'SMTP'));
    success();
}

$pageTitle = '邮件配置';
$currentMenu = 'setting_email';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="mb-6">
    <p class="text-gray-500">配置SMTP邮件服务器和各类邮件通知模板。</p>
</div>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b overflow-x-auto">
        <?php foreach ($tabs as $tabId => $tab): ?>
        <a href="?tab=<?php echo e($tabId); ?>"
           class="px-5 py-3 text-sm font-medium whitespace-nowrap border-b-2 transition <?php echo $activeTab === $tabId ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            <i class="fa-solid <?php echo e($tab['icon']); ?> mr-1.5"></i><?php echo e($tab['title']); ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($activeTab === 'smtp'): ?>
<!-- ============ SMTP 配置 ============ -->
<form id="settingForm" class="space-y-6">
    <input type="hidden" name="_save_tab" value="smtp">

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">SMTP 服务器配置</h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    SMTP服务器
                    <span class="text-gray-400 text-sm block">如：smtp.qq.com</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[smtp_host]" value="<?php echo e(config('smtp_host')); ?>"
                           placeholder="smtp.example.com" class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    SMTP端口
                    <span class="text-gray-400 text-sm block">SSL常用465，TLS常用587</span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[smtp_port]" value="<?php echo e(config('smtp_port', '465')); ?>"
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
                        <option value="ssl" <?php echo config('smtp_secure', 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                        <option value="tls" <?php echo config('smtp_secure') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                        <option value="" <?php echo config('smtp_secure') === '' ? 'selected' : ''; ?>><?php echo __('none'); ?></option>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    SMTP用户名
                    <span class="text-gray-400 text-sm block">通常是完整邮箱地址</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[smtp_user]" value="<?php echo e(config('smtp_user')); ?>"
                           placeholder="your@email.com" class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    SMTP密码
                    <span class="text-gray-400 text-sm block">QQ邮箱需使用授权码</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[smtp_pass]" value="<?php echo e(config('smtp_pass')); ?>"
                           placeholder="密码或授权码" class="w-full border rounded px-4 py-2 font-mono" autocomplete="off">
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">发件人 / 通知设置</h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    发件人邮箱
                    <span class="text-gray-400 text-sm block">留空则使用SMTP用户名</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_from]" value="<?php echo e(config('mail_from')); ?>"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    发件人名称
                    <span class="text-gray-400 text-sm block"><?php echo __('email_empty_site_name'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_from_name]" value="<?php echo e(config('mail_from_name')); ?>"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    管理员邮箱
                    <span class="text-gray-400 text-sm block">接收询盘通知</span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_admin]" value="<?php echo e(config('mail_admin')); ?>"
                           placeholder="admin@example.com" class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    询盘提交通知
                    <span class="text-gray-400 text-sm block">有新询盘时发送通知</span>
                </label>
                <div class="md:col-span-3">
                    <select name="settings[mail_notify_form]" class="w-full border rounded px-4 py-2">
                        <option value="1" <?php echo config('mail_notify_form') === '1' ? 'selected' : ''; ?>><?php echo __('email_on'); ?></option>
                        <option value="0" <?php echo config('mail_notify_form') !== '1' ? 'selected' : ''; ?>><?php echo __('email_off'); ?></option>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex flex-wrap gap-4">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition">
                <?php echo __('admin_save'); ?>
            </button>
            <button type="button" onclick="testEmail()" class="bg-green-500 hover:bg-green-600 text-white px-8 py-2 rounded transition">
                <i class="fa-solid fa-paper-plane mr-1"></i><?php echo __('email_send_test_btn'); ?>
            </button>
        </div>
    </div>
</form>

<!-- 测试邮件弹窗 -->
<div id="testModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800"><?php echo __('email_send_test_btn'); ?></h3>
            <button type="button" onclick="closeTestModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-gray-500 mb-4">请先保存设置，然后输入接收测试邮件的邮箱地址：</p>
            <input type="email" id="testEmailInput" placeholder="your@email.com"
                   value="<?php echo e(config('mail_admin')); ?>"
                   class="w-full border rounded px-4 py-2 mb-4">
            <button type="button" onclick="sendTestEmail()"
                    class="w-full bg-green-500 hover:bg-green-600 text-white py-2 rounded transition">
                发送测试
            </button>
            <p id="testResult" class="text-sm text-center mt-3 hidden"></p>
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
        if (data.code === 0) showMessage('<?php echo __('admin_saved'); ?>');
        else showMessage(data.msg, 'error');
    } catch (err) { showMessage('请求失败', 'error'); }
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
    if (!email) { showMessage('请输入测试邮箱', 'error'); return; }
    const formData = new FormData();
    formData.append('action', 'test');
    formData.append('test_email', email);
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) { showMessage(data.msg); closeTestModal(); }
        else showMessage(data.msg, 'error');
    } catch (err) { showMessage('请求失败', 'error'); }
}
</script>

<?php else: ?>
<!-- ============ 模板编辑 ============ -->
<?php
    $tab = $tabs[$activeTab];
    $subjectKey = $tab['keys'][0];
    $bodyKey    = $tab['keys'][1];
?>
<form id="tplForm" class="space-y-6">
    <input type="hidden" name="_save_tab" value="<?php echo e($activeTab); ?>">

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800">
                <i class="fa-solid <?php echo e($tab['icon']); ?> mr-2 text-gray-400"></i><?php echo e($tab['title']); ?> 邮件模板
            </h2>
            <?php if (!empty($tab['hint'])): ?>
            <p class="text-xs text-gray-400 mt-1">
                可用变量（点击插入）：
                <?php foreach (explode(' ', $tab['hint']) as $var): ?>
                <code class="bg-gray-100 px-1.5 py-0.5 rounded text-gray-600 cursor-pointer hover:bg-blue-100 hover:text-blue-600 transition" onclick="insertVar('<?php echo e($var); ?>')"><?php echo e($var); ?></code>
                <?php endforeach; ?>
            </p>
            <?php endif; ?>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2"><?php echo __('email_subject'); ?></label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[<?php echo e($subjectKey); ?>]"
                           value="<?php echo e(config($subjectKey)); ?>"
                           class="w-full border rounded px-4 py-2"
                           placeholder="请输入邮件标题...">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2"><?php echo __('email_body'); ?></label>
                <div class="md:col-span-3">
                    <textarea name="settings[<?php echo e($bodyKey); ?>]" rows="14" id="tplBody"
                              class="w-full border rounded px-4 py-2 font-mono text-sm leading-relaxed"
                              placeholder="请输入邮件正文..."><?php echo e(config($bodyKey)); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center gap-4">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition">
                <?php echo __('admin_save'); ?>
            </button>
            <span class="text-xs text-gray-400">修改后立即生效，发送时自动替换变量</span>
        </div>
    </div>
</form>

<script>
document.getElementById('tplForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) showMessage('<?php echo __('admin_saved'); ?>');
        else showMessage(data.msg, 'error');
    } catch (err) { showMessage('请求失败', 'error'); }
});

function insertVar(varName) {
    const textarea = document.getElementById('tplBody');
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    const text = textarea.value;
    textarea.value = text.substring(0, start) + varName + text.substring(end);
    textarea.selectionStart = textarea.selectionEnd = start + varName.length;
    textarea.focus();
}
</script>

<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
