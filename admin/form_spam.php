<?php
/**
 * YikaiCMS - 询盘管理 › 防垃圾设置
 *
 * 表单提交的防刷与内容过滤集中在这里：频率限制、表单签名、链接/关键词过滤、各表单验证码。
 * 自 2026-09 起从「安全设置」迁来（设置键不变，老站无需迁移）。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/FormSpamGuard.php';

checkLogin();
requirePermission('*');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = (string) post('action', 'save');

    if ($action === 'toggle_captcha') {
        $id = postInt('id');
        if (!formTemplateModel()->findById($id)) {
            error(__('admin_bad_params'), 422);
        }
        $captcha = formTemplateModel()->toggle($id, 'captcha');
        adminLog('form_template', 'captcha', 'Form template #' . $id . ' captcha ' . ($captcha ? 'on' : 'off'));
        success(['captcha' => $captcha]);
    }

    if ($action === 'save') {
        $input = is_array($_POST['settings'] ?? null) ? $_POST['settings'] : [];
        $clamp = static fn(string $key, int $min, int $max, int $default): string
            => (string) max($min, min($max, is_numeric($input[$key] ?? null) ? (int) $input[$key] : $default));
        $keywords = FormSpamGuard::keywordList(is_string($input['form_spam_keywords'] ?? null) ? $input['form_spam_keywords'] : '');
        settingModel()->saveBatch([
            'form_max_submits'       => $clamp('form_max_submits', 1, 100, 5),
            'form_throttle_minutes'  => $clamp('form_throttle_minutes', 1, 60, 5),
            'form_security_version'  => ($input['form_security_version'] ?? '2') === '1' ? '1' : '2',
            'form_signature_max_age' => $clamp('form_signature_max_age', 0, 2592000, 7200),
            'form_max_links'         => $clamp('form_max_links', 0, 20, 3),
            'form_spam_keywords'     => implode("\n", $keywords),
        ]);
        adminLog('setting', 'form_spam', '更新表单防垃圾设置');
        success(['keywords' => count($keywords)]);
    }

    error(__('admin_bad_params'), 422);
}

$spamConfig = [
    'form_max_submits'       => (string) config('form_max_submits', '5'),
    'form_throttle_minutes'  => (string) config('form_throttle_minutes', '5'),
    'form_security_version'  => (string) config('form_security_version', '1'),
    'form_signature_max_age' => (string) config('form_signature_max_age', '0'),
    'form_max_links'         => (string) config('form_max_links', '3'),
    'form_spam_keywords'     => (string) config('form_spam_keywords', ''),
];
$spamTemplates = formTemplateModel()->all('id ASC');
$blockedIpCount = count(formModerationModel()->blockedIps());

$pageTitle = __('fsp_title');
$currentMenu = 'form';

require_once ROOT_PATH . '/admin/includes/header.php';
require ROOT_PATH . '/admin/includes/workflow_nav.php';

$row = static function (string $label, string $tip, string $control): void {
    ?>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
        <label class="text-gray-700 pt-2"><?php echo e($label); ?>
            <?php if ($tip !== ''): ?><span class="text-gray-400 text-sm block"><?php echo e($tip); ?></span><?php endif; ?>
        </label>
        <div class="md:col-span-3"><?php echo $control; ?></div>
    </div>
    <?php
};
$number = static fn(string $key, int $min, int $max): string
    => '<input type="number" name="settings[' . e($key) . ']" value="' . e($spamConfig[$key]) . '" min="' . $min . '" max="' . $max . '" class="w-full border rounded px-4 py-2">';
?>

<form id="formSpamSettings" class="space-y-6" data-testid="form-spam-page">
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('fsp_intro_title')); ?></h2>
            <p class="mt-1 text-sm text-gray-500"><?php echo e(__('fsp_intro')); ?></p>
        </div>
        <div class="grid grid-cols-2 gap-3 p-6 text-sm md:grid-cols-4">
            <?php foreach ([['shield-check', 'fsp_builtin_honeypot'], ['clock-bolt', 'fsp_builtin_speed'], ['copy-off', 'fsp_builtin_duplicate'], ['ban', 'fsp_builtin_ip']] as [$icon, $key]): ?>
            <div class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2 text-gray-600">
                <i class="ti ti-<?php echo $icon; ?> text-base text-emerald-600" aria-hidden="true"></i><?php echo e(__($key)); ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('sec_form_throttle')); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <?php $row(__('sec_max_submissions'), __('sec_max_submissions_tip'), $number('form_max_submits', 1, 100)); ?>
            <?php $row(__('sec_time_window'), __('sec_time_window_tip'), $number('form_throttle_minutes', 1, 60) . '<div class="text-xs text-gray-400 mt-1">' . e(__('sec_form_throttle_hint')) . '</div>'); ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow" data-testid="form-spam-content-filter">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('fsp_content_filter')); ?></h2>
            <p class="mt-1 text-sm text-gray-500"><?php echo e(__('fsp_content_filter_desc')); ?></p>
        </div>
        <div class="p-6 space-y-4">
            <?php $row(__('fsp_max_links'), __('fsp_max_links_tip'), $number('form_max_links', 0, 20)); ?>
            <?php $row(__('fsp_keywords'), __('fsp_keywords_tip'),
                '<textarea name="settings[form_spam_keywords]" rows="6" data-testid="form-spam-keywords" placeholder="' . e(__('fsp_keywords_placeholder')) . '" class="w-full border rounded px-4 py-2 text-sm">' . e($spamConfig['form_spam_keywords']) . '</textarea>'
                . '<div class="text-xs text-gray-400 mt-1">' . e(__('fsp_keywords_hint')) . '</div>'); ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('fsp_signature')); ?></h2>
        </div>
        <div class="p-6 space-y-4">
            <?php $row(__('sec_form_signature_policy'), __('sec_form_signature_policy_tip'),
                '<select name="settings[form_security_version]" class="w-full border rounded px-4 py-2 bg-white">'
                . '<option value="1"' . ($spamConfig['form_security_version'] === '1' ? ' selected' : '') . '>' . e(__('sec_form_signature_compat')) . '</option>'
                . '<option value="2"' . ($spamConfig['form_security_version'] !== '1' ? ' selected' : '') . '>' . e(__('sec_form_signature_strict')) . '</option>'
                . '</select>'); ?>
            <?php $row(__('sec_form_signature_max_age'), __('sec_form_signature_max_age_tip'), $number('form_signature_max_age', 0, 2592000)); ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <button type="submit" data-testid="form-spam-save" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition"><?php echo e(__('admin_save')); ?></button>
    </div>
</form>

<div class="mt-6 space-y-6">
    <div class="bg-white rounded-lg shadow" data-testid="form-spam-captcha">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('fsp_captcha')); ?></h2>
            <p class="mt-1 text-sm text-gray-500"><?php echo e(__('fd_captcha_tip')); ?></p>
        </div>
        <div class="divide-y">
            <?php foreach ($spamTemplates as $template): ?>
            <div class="flex items-center justify-between gap-4 px-6 py-3">
                <div class="min-w-0">
                    <span class="font-medium text-gray-800"><?php echo e((string) $template['name']); ?></span>
                    <code class="ml-2 text-xs text-gray-400">[form-<?php echo e((string) $template['slug']); ?>]</code>
                </div>
                <button type="button" role="switch" aria-checked="<?php echo !empty($template['captcha']) ? 'true' : 'false'; ?>"
                        aria-label="<?php echo e(__('fd_enable_captcha') . ' · ' . $template['name']); ?>"
                        onclick="toggleFormCaptcha(<?php echo (int) $template['id']; ?>, this)"
                        class="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition <?php echo !empty($template['captcha']) ? 'bg-emerald-600' : 'bg-gray-300'; ?>">
                    <span class="inline-block h-5 w-5 rounded-full bg-white shadow transition <?php echo !empty($template['captcha']) ? 'translate-x-5' : 'translate-x-0.5'; ?>"></span>
                </button>
            </div>
            <?php endforeach; ?>
            <?php if ($spamTemplates === []): ?>
            <p class="px-6 py-4 text-sm text-gray-500"><?php echo e(__('fd_empty')); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow px-6 py-4 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h2 class="font-bold text-gray-800"><?php echo e(__('form_ip_list')); ?> <span class="text-sm font-normal text-gray-500">(<?php echo $blockedIpCount; ?>)</span></h2>
            <p class="mt-1 text-sm text-gray-500"><?php echo e(__('fsp_ip_hint')); ?></p>
        </div>
        <a href="/admin/form.php" class="text-primary hover:underline text-sm"><?php echo e(__('fsp_ip_manage')); ?> &rarr;</a>
    </div>
</div>

<script>
document.getElementById('formSpamSettings').addEventListener('submit', async function (event) {
    event.preventDefault();
    const body = new FormData(this);
    body.append('action', 'save');
    try {
        const data = await safeJson(await fetch('', { method: 'POST', body }));
        if (data.code === 0) {
            showMessage(<?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (error) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>, 'error');
    }
});

async function toggleFormCaptcha(id, button) {
    const body = new FormData();
    body.append('action', 'toggle_captcha');
    body.append('id', id);
    try {
        const data = await safeJson(await fetch('', { method: 'POST', body }));
        if (data.code !== 0) {
            showMessage(data.msg, 'error');
            return;
        }
        const on = !!Number(data.data.captcha);
        button.setAttribute('aria-checked', on ? 'true' : 'false');
        button.classList.toggle('bg-emerald-600', on);
        button.classList.toggle('bg-gray-300', !on);
        button.firstElementChild.classList.toggle('translate-x-5', on);
        button.firstElementChild.classList.toggle('translate-x-0.5', !on);
        showMessage(<?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>);
    } catch (error) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>, 'error');
    }
}
</script>

<?php adminModuleEnd(); ?>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
