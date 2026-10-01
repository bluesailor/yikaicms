<?php
/**
 * 官方技术支持访问（2.0.3）：站长开启限时访问、生成一次性登录链接、查看支持人员做了什么、随时撤销。
 * 规则见 includes/SupportAccess.php。只有超级管理员能开关；支持账号自己进不来这一页。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF 已由 checkLogin() 统一校验
    if ((defined('DEMO_MODE') && DEMO_MODE) || (defined('DEMO_SANDBOX') && DEMO_SANDBOX)) {
        error(__('auth_demo_sandbox_protected'));
    }
    $action = post('action');
    $linkUrl = static fn(string $token): string => siteBaseUrl() . '/admin/support_login.php#' . $token;
    if ($action === 'enable') {
        try {
            $token = SupportAccess::enable((int) post('hours'), getAdminId(), (string) post('note'));
        } catch (InvalidArgumentException) {
            error(__('support_err_duration'));
        } catch (RuntimeException) {
            error(__('support_err_username_taken'));
        }
        success(['link' => $linkUrl($token)], __('support_enabled'));
    }
    if ($action === 'new_link') {
        try {
            $token = SupportAccess::newLink();
        } catch (RuntimeException) {
            error(__('support_err_inactive'));
        }
        success(['link' => $linkUrl($token)], __('support_new_link_done'));
    }
    if ($action === 'revoke') {
        SupportAccess::revoke();
        success([], __('support_revoked'));
    }
    error(__('admin_fail'));
}

SupportAccess::expireIfDue();
$state = SupportAccess::state();
$activity = $state['user_id'] > 0
    ? adminLogModel()->where(['admin_id' => $state['user_id']], 'id DESC', 50)
    : [];
$remaining = max(0, $state['until'] - time());

$pageTitle = __('support_title');
$currentMenu = 'support_access';
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="max-w-4xl space-y-6">
    <div>
        <h2 class="text-xl font-semibold text-gray-900"><?= e(__('support_title')) ?></h2>
        <p class="mt-1 text-sm text-gray-500"><?= e(__('support_intro')) ?></p>
    </div>

    <section class="bg-white rounded-lg shadow p-6 space-y-4" aria-labelledby="supportStatus">
        <h3 id="supportStatus" class="font-semibold text-gray-800"><?= e(__('support_status')) ?></h3>
        <?php if ($state['active']): ?>
        <p class="rounded bg-amber-50 border border-amber-200 text-amber-900 p-3 text-sm" data-testid="support-active">
            <?= e(__('support_active_until', ['time' => date('Y-m-d H:i', $state['until']), 'hours' => (string) max(1, (int) ceil($remaining / 3600))])) ?>
            <?php if ($state['note'] !== ''): ?><br><span class="text-amber-800"><?= e(__('support_note_label')) ?>：<?= e($state['note']) ?></span><?php endif; ?>
        </p>
        <div class="flex flex-wrap gap-3">
            <button type="button" class="px-4 py-2 rounded border border-gray-300 text-sm hover:bg-gray-50" data-support-action="new_link"><?= e(__('support_new_link')) ?></button>
            <button type="button" class="px-4 py-2 rounded bg-red-600 text-white text-sm hover:bg-red-700" data-support-action="revoke" data-testid="support-revoke"><?= e(__('support_revoke')) ?></button>
        </div>
        <?php else: ?>
        <p class="text-sm text-gray-600" data-testid="support-inactive"><?= e(__('support_inactive')) ?></p>
        <form id="supportEnable" class="flex flex-wrap items-end gap-3">
            <label class="text-sm"><span class="block text-gray-700 mb-1"><?= e(__('support_duration')) ?></span>
                <select name="hours" class="border rounded px-3 py-2">
                    <?php foreach (SupportAccess::DURATIONS as $h): ?>
                    <option value="<?= $h ?>"<?= $h === 24 ? ' selected' : '' ?>><?= e(__('support_hours', ['n' => (string) $h])) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="text-sm flex-1 min-w-[14rem]"><span class="block text-gray-700 mb-1"><?= e(__('support_note_label')) ?></span>
                <input type="text" name="note" maxlength="200" class="w-full border rounded px-3 py-2" placeholder="<?= e(__('support_note_placeholder')) ?>">
            </label>
            <button type="submit" class="bg-primary text-white rounded px-4 py-2 text-sm font-medium hover:opacity-90" data-testid="support-enable"><?= e(__('support_enable')) ?></button>
        </form>
        <?php endif; ?>
        <div id="supportLinkBox" class="hidden space-y-2">
            <p class="text-sm text-gray-700"><?= e(__('support_link_hint')) ?></p>
            <div class="flex gap-2">
                <input type="text" readonly id="supportLink" class="flex-1 border rounded px-3 py-2 font-mono text-xs bg-gray-50" data-testid="support-link">
                <button type="button" id="supportCopy" class="px-3 py-2 rounded border text-sm hover:bg-gray-50"><?= e(__('support_copy')) ?></button>
            </div>
        </div>
        <ul class="text-xs text-gray-500 list-disc pl-5 space-y-1">
            <li><?= e(__('support_rule_can')) ?></li>
            <li><?= e(__('support_rule_cannot')) ?></li>
            <li><?= e(__('support_rule_link')) ?></li>
        </ul>
    </section>

    <section class="bg-white rounded-lg shadow p-6" aria-labelledby="supportActivity">
        <h3 id="supportActivity" class="font-semibold text-gray-800 mb-3"><?= e(__('support_activity')) ?></h3>
        <?php if ($activity === []): ?>
        <p class="text-sm text-gray-500"><?= e(__('support_activity_empty')) ?></p>
        <?php else: ?>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500"><th class="py-2 pr-4"><?= e(__('support_col_time')) ?></th><th class="py-2 pr-4"><?= e(__('support_col_action')) ?></th><th class="py-2"><?= e(__('support_col_detail')) ?></th></tr></thead>
            <tbody>
            <?php foreach ($activity as $row): ?>
            <tr class="border-t"><td class="py-2 pr-4 whitespace-nowrap"><?= e(date('Y-m-d H:i', (int) $row['created_at'])) ?></td>
                <td class="py-2 pr-4"><?= e($row['module'] . ' / ' . $row['action']) ?></td>
                <td class="py-2 text-gray-600"><?= e((string) $row['description']) ?> <span class="text-gray-400"><?= e((string) $row['url']) ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</div>
<script>
(function () {
    var csrf = <?= json_encode(csrfToken()) ?>;
    var tokenName = <?= json_encode(CSRF_TOKEN_NAME) ?>;
    async function post(fields) {
        var fd = new FormData();
        fd.append(tokenName, csrf);
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        return safeJson(await fetch('', { method: 'POST', body: fd }));
    }
    function showLink(link) {
        document.getElementById('supportLinkBox').classList.remove('hidden');
        document.getElementById('supportLink').value = link;
    }
    var form = document.getElementById('supportEnable');
    if (form) form.addEventListener('submit', async function (e) {
        e.preventDefault();
        var data = await post({ action: 'enable', hours: form.hours.value, note: form.note.value });
        if (data.code !== 0) { showMessage(data.msg || '', 'error'); return; }
        showMessage(data.msg);
        showLink(data.data.link);
        form.remove();
    });
    document.querySelectorAll('[data-support-action]').forEach(function (button) {
        button.addEventListener('click', async function () {
            var action = button.getAttribute('data-support-action');
            if (action === 'revoke' && !confirm(<?= json_encode(__('support_revoke_confirm')) ?>)) return;
            var data = await post({ action: action });
            if (data.code !== 0) { showMessage(data.msg || '', 'error'); return; }
            showMessage(data.msg);
            if (action === 'new_link') showLink(data.data.link);
            else setTimeout(function () { location.reload(); }, 600);
        });
    });
    document.getElementById('supportCopy').addEventListener('click', function () {
        var input = document.getElementById('supportLink');
        input.select();
        if (navigator.clipboard) navigator.clipboard.writeText(input.value);
    });
}());
</script>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
