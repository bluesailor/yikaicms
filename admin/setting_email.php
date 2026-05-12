<?php
/**
 * YikaiCMS - 邮件配置
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

// Tab 定义（title 用 __() 后台跟随当前语言）
$tabs = [
    'smtp' => [
        'icon'  => 'fa-server',
        'title' => __('email_tab_smtp'),
    ],
    'register' => [
        'icon'  => 'fa-user-plus',
        'title' => __('email_tab_register'),
        'hint'  => '{{username}} {{email}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_register_subject', 'mail_tpl_register_body'],
    ],
    'forgot' => [
        'icon'  => 'fa-key',
        'title' => __('email_tab_forgot'),
        'hint'  => '{{username}} {{email}} {{reset_link}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_forgot_subject', 'mail_tpl_forgot_body'],
    ],
    'reset' => [
        'icon'  => 'fa-lock',
        'title' => __('email_tab_reset'),
        'hint'  => '{{username}} {{email}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_reset_subject', 'mail_tpl_reset_body'],
    ],
    'inquiry' => [
        'icon'  => 'fa-envelope-open-text',
        'title' => __('email_tab_inquiry'),
        'hint'  => '{{product_title}} {{name}} {{phone}} {{email}} {{company}} {{content}} {{ip}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_inquiry_subject', 'mail_tpl_inquiry_body'],
    ],
];

$activeTab = get('tab', 'smtp');
if (!isset($tabs[$activeTab])) $activeTab = 'smtp';

// ============== 多语言视图（仅模板 tab 启用） ==============
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
// smtp tab 不分语言；其余模板 tab 全部 lang-aware
$_emailLangAware = ($activeTab !== 'smtp');
$EMAIL_LANG_KEYS = [
    'mail_tpl_register_subject', 'mail_tpl_register_body',
    'mail_tpl_forgot_subject',   'mail_tpl_forgot_body',
    'mail_tpl_reset_subject',    'mail_tpl_reset_body',
    'mail_tpl_inquiry_subject',  'mail_tpl_inquiry_body',
];

// ============================================================
// AJAX: 测试发送
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'test') {
    $testEmail = post('test_email');
    if (!$testEmail || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
        error(__('email_test_invalid_email_err'));
    }

    $result = sendMail(
        $testEmail,
        __('email_test_subject') . ' - ' . config('site_name'),
        __('email_test_body') . "\n\n" . __('email_test_sent_at') . date('Y-m-d H:i:s')
    );

    if ($result === true) {
        success([], __('email_test_success_msg'));
    } else {
        error(__('email_test_fail_prefix') . $result);
    }
}

// ============================================================
// POST 保存
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action', 'save') === 'save') {
    $settings = $_POST['settings'] ?? [];
    $saveTab  = post('_save_tab', 'smtp');
    $isLangTab = ($saveTab !== 'smtp');

    foreach ($settings as $key => $value) {
        // 模板 tab + 非默认语言：写入 <key>_<lang>
        if ($isLangTab && $_viewLang !== $_defaultLang && in_array($key, $EMAIL_LANG_KEYS, true)) {
            settingModel()->set($key . '_' . $_viewLang, (string) $value);
        } else {
            settingModel()->set($key, (string) $value);
        }
    }

    adminLog('setting', 'update', '更新邮件设置: ' . ($tabs[$saveTab]['title'] ?? 'SMTP') . ' (' . ($isLangTab ? $_viewLang : 'global') . ')');
    success();
}

// lang-aware 读取：模板 tab + 非默认语言时优先 <key>_<lang>，空则回退到 base
$readEmailLang = function (string $base) use ($EMAIL_LANG_KEYS, $_emailLangAware, $_viewLang, $_defaultLang): string {
    if ($_emailLangAware && in_array($base, $EMAIL_LANG_KEYS, true) && $_viewLang !== $_defaultLang) {
        $v = (string) config($base . '_' . $_viewLang, '');
        if ($v !== '') return $v;
    }
    return (string) config($base, '');
};

$pageTitle = __('email_page_title');
$currentMenu = 'setting_email';

require_once ROOT_PATH . '/admin/includes/trans_pills.php';
require_once ROOT_PATH . '/admin/includes/header.php';

if ($_emailLangAware) {
    echo renderAdminLangSwitcher($_viewLang, __('email_lang_tip'));
}
?>

<div class="mb-6">
    <p class="text-gray-500"><?php echo __('email_page_intro'); ?></p>
</div>

<!-- Tab 导航 -->
<?php
// 模板 tab 链接保留 ?lang= 视图；smtp tab 链接不带 lang（SMTP 不分语言）
$_emailLangQS = ($_viewLang !== $_defaultLang) ? ('&lang=' . urlencode($_viewLang)) : '';
?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b overflow-x-auto">
        <?php foreach ($tabs as $tabId => $tab): ?>
        <?php $_tabHref = ($tabId === 'smtp') ? '?tab=smtp' : ('?tab=' . urlencode($tabId) . $_emailLangQS); ?>
        <a href="<?php echo e($_tabHref); ?>"
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
            <h2 class="font-bold text-gray-800"><?php echo __('email_smtp_settings'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_smtp_host'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_smtp_host_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[smtp_host]" value="<?php echo e(config('smtp_host')); ?>"
                           placeholder="smtp.example.com" class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_smtp_port'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_smtp_port_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="number" name="settings[smtp_port]" value="<?php echo e(config('smtp_port', '465')); ?>"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_smtp_secure'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_smtp_secure_tip'); ?></span>
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
                    <?php echo __('email_smtp_user'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_smtp_user_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[smtp_user]" value="<?php echo e(config('smtp_user')); ?>"
                           placeholder="your@email.com" class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_smtp_pass'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_smtp_pass_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[smtp_pass]" value="<?php echo e(config('smtp_pass')); ?>"
                           placeholder="<?php echo e(__('email_smtp_pass_placeholder')); ?>" class="w-full border rounded px-4 py-2 font-mono" autocomplete="off">
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('email_sender_section'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_from'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_mail_from_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_from]" value="<?php echo e(config('mail_from')); ?>"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_from_name'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_empty_site_name'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_from_name]" value="<?php echo e(config('mail_from_name')); ?>"
                           class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_admin'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_admin_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <input type="text" name="settings[mail_admin]" value="<?php echo e(config('mail_admin')); ?>"
                           placeholder="admin@example.com" class="w-full border rounded px-4 py-2">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_notify_form'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_notify_form_tip'); ?></span>
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

<div id="testModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h3 class="font-bold text-gray-800"><?php echo __('email_send_test_btn'); ?></h3>
            <button type="button" onclick="closeTestModal()" class="text-gray-400 hover:text-gray-600">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-gray-500 mb-4"><?php echo __('email_test_modal_intro'); ?></p>
            <input type="email" id="testEmailInput" placeholder="your@email.com"
                   value="<?php echo e(config('mail_admin')); ?>"
                   class="w-full border rounded px-4 py-2 mb-4">
            <button type="button" onclick="sendTestEmail()"
                    class="w-full bg-green-500 hover:bg-green-600 text-white py-2 rounded transition">
                <?php echo __('email_test_btn_in_modal'); ?>
            </button>
            <p id="testResult" class="text-sm text-center mt-3 hidden"></p>
        </div>
    </div>
</div>

<script>
document.getElementById('settingForm').addEventListener('submit', function (e) {
    e.preventDefault();
    adminSave(this, { successMsg: '<?php echo __('admin_saved'); ?>' });
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
    if (!email) { showMessage('<?php echo e(__('email_test_empty_email_err')); ?>', 'error'); return; }
    const formData = new FormData();
    formData.append('action', 'test');
    formData.append('test_email', email);
    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);
        if (data.code === 0) { showMessage(data.msg); closeTestModal(); }
        else showMessage(data.msg, 'error');
    } catch (err) { showMessage('<?php echo e(__('admin_request_failed')); ?>', 'error'); }
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
                <i class="fa-solid <?php echo e($tab['icon']); ?> mr-2 text-gray-400"></i><?php echo e($tab['title']); ?> <?php echo __('email_template_label'); ?>
            </h2>
            <?php if (!empty($tab['hint'])): ?>
            <p class="text-xs text-gray-400 mt-1">
                <?php echo __('email_available_vars'); ?>
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
                           value="<?php echo e($readEmailLang($subjectKey)); ?>"
                           class="w-full border rounded px-4 py-2"
                           placeholder="<?php echo e(__('email_subject_placeholder')); ?>">
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2"><?php echo __('email_body'); ?></label>
                <div class="md:col-span-3">
                    <textarea name="settings[<?php echo e($bodyKey); ?>]" rows="14" id="tplBody"
                              class="w-full border rounded px-4 py-2 font-mono text-sm leading-relaxed"
                              placeholder="<?php echo e(__('email_body_placeholder')); ?>"><?php echo e($readEmailLang($bodyKey)); ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <div class="flex items-center gap-4">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition">
                <?php echo __('admin_save'); ?>
            </button>
            <span class="text-xs text-gray-400"><?php echo __('email_tpl_save_hint'); ?></span>
        </div>
    </div>
</form>

<script>
document.getElementById('tplForm').addEventListener('submit', function (e) {
    e.preventDefault();
    adminSave(this, { successMsg: '<?php echo __('admin_saved'); ?>' });
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
