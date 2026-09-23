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
require_once ROOT_PATH . '/includes/MailDelivery.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 投递日志（roadmap #3）：表缺失（升级窗口）时惰性建，页面可用
try {
    MailDelivery::ensureTable();
} catch (Throwable) {
    // 无 DB / 无权限：日志面板显示为空，不影响 SMTP 配置本身
}

// Tab 定义（title 用 __() 后台跟随当前语言）
$tabs = [
    'smtp' => [
        'icon'  => 'ti-server',
        'title' => __('email_tab_smtp'),
    ],
    'register' => [
        'icon'  => 'ti-user-plus',
        'title' => __('email_tab_register'),
        'hint'  => '{{username}} {{email}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_register_subject', 'mail_tpl_register_body'],
    ],
    'forgot' => [
        'icon'  => 'ti-key',
        'title' => __('email_tab_forgot'),
        'hint'  => '{{username}} {{email}} {{reset_link}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_forgot_subject', 'mail_tpl_forgot_body'],
    ],
    'reset' => [
        'icon'  => 'ti-lock',
        'title' => __('email_tab_reset'),
        'hint'  => '{{username}} {{email}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_reset_subject', 'mail_tpl_reset_body'],
    ],
    'inquiry' => [
        'icon'  => 'ti-mail-opened',
        'title' => __('email_tab_inquiry'),
        'hint'  => '{{product_title}} {{name}} {{phone}} {{email}} {{company}} {{content}} {{ip}} {{site_name}} {{site_url}} {{date}}',
        'keys'  => ['mail_tpl_inquiry_subject', 'mail_tpl_inquiry_body'],
    ],
    'log' => [
        'icon'  => 'ti-history',
        'title' => __('email_tab_log'),
        'hint'  => '',
        'keys'  => [],
    ],
];

$activeTab = get('tab', 'smtp');
if (!isset($tabs[$activeTab])) $activeTab = 'smtp';
// ============== 多语言视图（仅模板 tab 启用） ==============
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
// smtp / log tab 不分语言；其余模板 tab 全部 lang-aware
$_emailLangAware = !in_array($activeTab, ['smtp', 'log'], true);
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
        __('email_test_body') . "\n\n" . str_replace(':time', date('Y-m-d H:i:s'), __('email_test_sent_at'))
    );

    if ($result === true) {
        success([], __('email_test_success_msg'));
    } else {
        error(str_replace(':error', (string) $result, __('email_test_fail_prefix')));
    }
}

// ============================================================
// AJAX: 手动重试失败邮件（单封 / 全部到期）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'retry_failed') {
    try {
        $id = postInt('id');
        if ($id > 0) {
            $row = db()->fetchOne(
                'SELECT * FROM ' . DB_PREFIX . 'mail_log WHERE id = ? AND status = ? LIMIT 1',
                [$id, 'failed']
            );
            if ($row === null) {
                error(__('email_log_retry_gone'));
            }
            $result = sendMailRaw((string) $row['to_email'], (string) $row['subject'], (string) $row['body']);
            db()->update('mail_log', [
                'status' => $result === true ? 'sent' : 'failed',
                'error' => mb_substr($result === true ? '' : (string) $result, 0, 190),
                'attempts' => (int) $row['attempts'] + 1,
                'updated_at' => time(),
            ], 'id = ?', [$id]);
            adminLog('setting', 'update', 'Retried mail #' . $id);
            $result === true
                ? success([], __('email_log_retry_sent'))
                : error(str_replace(':error', (string) $result, __('email_log_retry_failed')));
        }
        $summary = MailDelivery::retryFailed(20);
        adminLog('setting', 'update', 'Retried failed mail batch: ' . $summary['retried']);
        success(
            $summary,
            str_replace(
                [':retried', ':sent', ':failed'],
                [(string) $summary['retried'], (string) $summary['sent'], (string) $summary['still_failed']],
                __('email_log_retry_done')
            )
        );
    } catch (Throwable $e) {
        error($e->getMessage());
    }
}

// ============================================================
// POST 保存
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action', 'save') === 'save') {
    $settings = $_POST['settings'] ?? [];
    $saveTab  = post('_save_tab', 'smtp');
    if ($saveTab === 'log') {
        success();   // 日志 tab 无可保存项（表头保存按钮不出现在此 tab）
    }
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
require_once ROOT_PATH . '/admin/includes/module_nav.php';
$emailModuleItems = [];
foreach ($tabs as $tabId => $emailTab) {
    $emailContext = ['tab' => $tabId];
    if ($tabId !== 'smtp') {
        $emailContext['lang'] = (string) $_viewLang;
    }
    $emailModuleItems[] = [
        'label' => $emailTab['title'],
        'url' => '/admin/setting_email.php?' . http_build_query($emailContext, '', '&', PHP_QUERY_RFC3986),
        'icon' => substr($emailTab['icon'], 3),
        'active' => $activeTab === $tabId,
        'testid' => 'admin-module-tab-' . $tabId,
    ];
}
adminModuleStart($emailModuleItems, __('email_page_title'));

if ($_emailLangAware) {
    echo renderAdminLangSwitcher($_viewLang, __('email_lang_tip'));
}
?>

<div class="mb-6">
    <p class="text-gray-500"><?php echo __('email_page_intro'); ?></p>
</div>


<?php if ($activeTab === 'smtp'): ?>
<?php /* ============ SMTP 配置 ============ */ ?>

<form id="settingForm" class="space-y-6">
    <?php echo adminLangField(); ?>
    <input type="hidden" name="_save_tab" value="smtp">

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo __('email_smtp_settings'); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <?php
            // 服务商预设：选中自动填 host/port/加密，用户只需填账号与授权码。
            // 参数天书（465/587、SSL/TLS）是逐站配 SMTP 最大的挫败点（借鉴 WP Mail SMTP）。
            $smtpPresets = [
                'aliyun'   => ['label' => __('email_preset_aliyun'),   'host' => 'smtpdm.aliyun.com',    'port' => 465, 'secure' => 'ssl', 'help' => 'https://www.aliyun.com/product/directmail'],
                'exmail'   => ['label' => __('email_preset_exmail'),   'host' => 'smtp.exmail.qq.com',   'port' => 465, 'secure' => 'ssl', 'help' => 'https://work.weixin.qq.com/mail/'],
                'qq'       => ['label' => __('email_preset_qq'),       'host' => 'smtp.qq.com',          'port' => 465, 'secure' => 'ssl', 'help' => 'https://service.mail.qq.com/detail/0/75'],
                '163'      => ['label' => __('email_preset_163'),      'host' => 'smtp.163.com',         'port' => 465, 'secure' => 'ssl', 'help' => 'https://help.mail.163.com/faqDetail.do?code=d7a5dc8471cd0c0e8b4b8f4f8e49998b374173cfe9171305fa1ce630d7f67ac2'],
                '126'      => ['label' => __('email_preset_126'),      'host' => 'smtp.126.com',         'port' => 465, 'secure' => 'ssl', 'help' => ''],
                'gmail'    => ['label' => __('email_preset_gmail'),    'host' => 'smtp.gmail.com',       'port' => 587, 'secure' => 'tls', 'help' => 'https://support.google.com/accounts/answer/185833'],
                'outlook'  => ['label' => __('email_preset_outlook'),  'host' => 'smtp.office365.com',   'port' => 587, 'secure' => 'tls', 'help' => ''],
                'sendgrid' => ['label' => __('email_preset_sendgrid'), 'host' => 'smtp.sendgrid.net',    'port' => 587, 'secure' => 'tls', 'help' => 'https://docs.sendgrid.com/for-developers/sending-email/integrating-with-the-smtp-api'],
            ];
            ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                <label class="text-gray-700 pt-2">
                    <?php echo __('email_preset_label'); ?>
                    <span class="text-gray-400 text-sm block"><?php echo __('email_preset_tip'); ?></span>
                </label>
                <div class="md:col-span-3">
                    <select id="smtpPresetSelect" class="w-full border rounded px-4 py-2">
                        <option value=""><?php echo __('email_preset_custom'); ?></option>
                        <?php // (string) 必须显式：'163'/'126' 作数组键会被 PHP 自动转 int，e(int) 直接 TypeError ?>
                        <?php foreach ($smtpPresets as $presetKey => $preset): ?>
                        <option value="<?php echo e((string) $presetKey); ?>"><?php echo e($preset['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p id="smtpPresetHint" class="hidden mt-2 text-sm text-amber-700 bg-amber-50 rounded px-3 py-2">
                        <?php echo __('email_preset_filled_hint'); ?>
                        <a id="smtpPresetHelp" href="#" target="_blank" rel="noopener" class="hidden underline font-medium"><?php echo __('email_preset_help_link'); ?></a>
                    </p>
                </div>
            </div>
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
                <i class="ti ti-send mr-1"></i><?php echo __('email_send_test_btn'); ?>
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

// 服务商预设联动：填服务器三参数（host/port/加密），账号密码仍由用户填
const SMTP_PRESETS = <?php echo json_encode(
    array_map(static fn (array $p): array => ['host' => $p['host'], 'port' => $p['port'], 'secure' => $p['secure'], 'help' => $p['help']], $smtpPresets),
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
); ?>;
document.getElementById('smtpPresetSelect').addEventListener('change', function () {
    const preset = SMTP_PRESETS[this.value];
    const hint = document.getElementById('smtpPresetHint');
    const help = document.getElementById('smtpPresetHelp');
    if (!preset) { hint.classList.add('hidden'); return; }
    document.querySelector('input[name="settings[smtp_host]"]').value = preset.host;
    document.querySelector('input[name="settings[smtp_port]"]').value = preset.port;
    document.querySelector('select[name="settings[smtp_secure]"]').value = preset.secure;
    hint.classList.remove('hidden');
    if (preset.help) { help.href = preset.help; help.classList.remove('hidden'); }
    else { help.classList.add('hidden'); }
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

<?php elseif ($activeTab === 'log'): ?>
<?php /* ============ 投递日志与失败重试 ============ */ ?>
<?php
    $mailLog = MailDelivery::recent(100);
    $mailStreak = MailDelivery::failureStreak();
?>
<div class="bg-white rounded-lg shadow mb-6" data-testid="email-log-panel">
    <div class="px-6 py-4 border-b flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-history text-blue-500"></i> <?php echo e(__('email_log_title')); ?>
            </h2>
            <p class="text-xs text-gray-400 mt-0.5"><?php echo e(__('email_log_intro')); ?></p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-gray-400">
                <?php echo str_replace(
                    [':sent', ':failed'],
                    [(string) $mailStreak['total_sent'], (string) $mailStreak['total_failed']],
                    e(__('email_log_recent_stats'))
                ); ?>
            </span>
            <button type="button" onclick="retryAllFailed(this)" data-testid="email-log-retry-all"
                    class="border border-gray-200 hover:border-blue-400 hover:text-blue-500 text-gray-600 text-sm px-3 py-1.5 rounded-lg inline-flex items-center gap-1.5">
                <i class="ti ti-refresh text-base"></i> <?php echo e(__('email_log_retry_all')); ?>
            </button>
        </div>
    </div>

    <?php if ($mailStreak['streak'] >= 3): ?>
    <div class="mx-6 mt-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 flex items-start gap-2" data-testid="email-log-streak-warning">
        <i class="ti ti-alert-triangle text-base mt-0.5"></i>
        <span><?php echo str_replace(
            [':streak', ':error'],
            [(string) $mailStreak['streak'], e((string) $mailStreak['last_error'])],
            e(__('email_log_streak_warning'))
        ); ?></span>
    </div>
    <?php endif; ?>

    <div class="p-6">
        <?php if ($mailLog === []): ?>
        <p class="text-sm text-gray-400 text-center py-6"><?php echo e(__('email_log_empty')); ?></p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs text-gray-400 border-b">
                    <th class="py-2 pr-3 whitespace-nowrap"><?php echo e(__('email_log_col_time')); ?></th>
                    <th class="py-2 pr-3"><?php echo e(__('email_log_col_to')); ?></th>
                    <th class="py-2 pr-3"><?php echo e(__('email_log_col_subject')); ?></th>
                    <th class="py-2 pr-3"><?php echo e(__('email_log_col_status')); ?></th>
                    <th class="py-2 w-24"></th>
                </tr></thead>
                <tbody>
                <?php foreach ($mailLog as $logRow): ?>
                    <?php
                    $logStatus = (string) $logRow['status'];
                    $statusMeta = match ($logStatus) {
                        'sent' => ['bg-green-100 text-green-700', __('email_log_status_sent')],
                        'failed' => ['bg-red-100 text-red-700', __('email_log_status_failed')],
                        default => ['bg-gray-100 text-gray-600', $logStatus],
                    };
                    $attempts = (int) ($logRow['attempts'] ?? 1);
                    ?>
                    <tr class="border-b border-gray-50 align-top" data-testid="email-log-row">
                        <td class="py-2 pr-3 text-xs text-gray-500 whitespace-nowrap tabular-nums">
                            <?php echo e(date('m-d H:i', (int) $logRow['created_at'])); ?>
                        </td>
                        <td class="py-2 pr-3 text-xs text-gray-700 break-all"><?php echo e((string) $logRow['to_email']); ?></td>
                        <td class="py-2 pr-3 text-xs text-gray-600">
                            <div class="text-gray-700"><?php echo e((string) $logRow['subject']); ?></div>
                            <?php // 脱敏预览：正文全文不入界面，只给去标签截断摘要 ?>
                            <div class="text-gray-400 mt-0.5"><?php echo e((string) $logRow['preview']); ?></div>
                            <?php if ($logStatus === 'failed' && (string) $logRow['error'] !== ''): ?>
                            <div class="text-red-500 mt-0.5"><?php echo e((string) $logRow['error']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 pr-3 whitespace-nowrap">
                            <span class="text-xs px-1.5 py-0.5 rounded <?php echo e($statusMeta[0]); ?>"><?php echo e($statusMeta[1]); ?></span>
                            <?php if ($attempts > 1): ?>
                            <span class="text-xs text-gray-400 ml-1">×<?php echo $attempts; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 text-right whitespace-nowrap">
                            <?php if ($logStatus === 'failed'): ?>
                            <button type="button" onclick="retryMail(<?php echo (int) $logRow['id']; ?>, this)"
                                    class="text-xs text-blue-600 hover:text-blue-500 px-2 py-1"><?php echo e(__('email_log_retry')); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function postMailAction(fields) {
    const body = new URLSearchParams();
    body.set('action', 'retry_failed');
    Object.keys(fields || {}).forEach(function (k) { body.set(k, String(fields[k])); });
    return fetch(window.location.href, { method: 'POST', body: body }).then(function (r) { return r.json(); });
}

function retryMail(id, button) {
    button.disabled = true;
    button.textContent = '<?php echo e(__('email_log_retrying')); ?>';
    postMailAction({ id: id }).then(function (res) {
        if (res && res.code === 0) { location.reload(); return; }
        alert((res && res.msg) || '<?php echo e(__('email_log_retry_failed')); ?>');
        button.disabled = false;
        button.textContent = '<?php echo e(__('email_log_retry')); ?>';
    }).catch(function () {
        button.disabled = false;
        button.textContent = '<?php echo e(__('email_log_retry')); ?>';
    });
}

function retryAllFailed(button) {
    button.disabled = true;
    postMailAction({}).then(function (res) {
        if (res && res.code === 0) { location.reload(); return; }
        alert((res && res.msg) || '<?php echo e(__('email_log_retry_failed')); ?>');
        button.disabled = false;
    }).catch(function () { button.disabled = false; });
}
</script>

<?php else: ?>
<?php /* ============ 模板编辑 ============ */ ?>
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
                <i class="ti <?php echo e($tab['icon']); ?> mr-2 text-gray-400"></i><?php echo e($tab['title']); ?> <?php echo __('email_template_label'); ?>
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

<?php adminModuleEnd(); ?>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
