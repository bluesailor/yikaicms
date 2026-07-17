<?php
/**
 * YikaiCMS - 定时任务
 *
 * 查看/手动运行定时任务，显示 cron 接入地址。调度逻辑见 includes/Cron.php。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/Cron.php';

checkLogin();
requirePermission('*');

// ============================================================
// AJAX（CSRF 已由 checkLogin() 统一校验）
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'run') {
        $r = Cron::runOne(post('name'));
        if (!$r['ran']) {
            error(__('cron_task_not_found'));
        }
        adminLog('cron', 'run', '手动运行定时任务：' . post('name'));
        success(['ok' => $r['ok'], 'msg' => $r['msg'], 'ms' => $r['ms']], $r['ok'] ? __('cron_run_ok') : $r['msg']);
    }

    if ($action === 'reset_token') {
        settingModel()->set('cron_token', bin2hex(random_bytes(16)), 'cron');
        adminLog('cron', 'reset_token', '重置 cron 令牌');
        success(['token' => Cron::token()], __('cron_token_reset'));
    }

    error();
}

$tasks = Cron::tasks();
$history = array_slice(Cron::history(), 0, 20);
$token = Cron::token();
$cronUrl = siteBaseUrl() . '/cron.php?token=' . $token;

/** 秒数转人类可读 */
function cronInterval(int $s): string
{
    if ($s % 86400 === 0) return ($s / 86400) . ' ' . __('cron_unit_day');
    if ($s % 3600 === 0) return ($s / 3600) . ' ' . __('cron_unit_hour');
    if ($s % 60 === 0) return ($s / 60) . ' ' . __('cron_unit_min');
    return $s . ' ' . __('cron_unit_sec');
}

$pageTitle = __('admin_cron');
$currentMenu = 'cron';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- 接入说明 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800"><?php echo __('cron_setup_title'); ?></h2>
    </div>
    <div class="p-6 space-y-3 text-sm text-gray-600">
        <p><?php echo __('cron_setup_desc'); ?></p>
        <div class="flex items-center gap-2">
            <code id="cronCmd" class="flex-1 bg-gray-50 border rounded px-3 py-2 text-xs break-all">*/5 * * * * curl -s "<?php echo e($cronUrl); ?>" &gt;/dev/null</code>
            <button type="button" onclick="copyCron()" class="px-3 py-2 bg-primary text-white rounded text-xs whitespace-nowrap cursor-pointer"><?php echo __('cron_copy'); ?></button>
        </div>
        <p class="text-gray-400 text-xs">
            <?php echo __('cron_token_note'); ?>
            <a href="#" onclick="resetToken();return false;" class="text-primary hover:underline"><?php echo __('cron_reset_token'); ?></a>
        </p>
    </div>
</div>

<!-- 任务列表 -->
<div class="bg-white rounded-lg shadow mb-6 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-500 text-left">
            <tr>
                <th class="px-6 py-3 font-medium"><?php echo __('cron_col_task'); ?></th>
                <th class="px-6 py-3 font-medium w-28"><?php echo __('cron_col_interval'); ?></th>
                <th class="px-6 py-3 font-medium w-44"><?php echo __('cron_col_last'); ?></th>
                <th class="px-6 py-3 font-medium"><?php echo __('cron_col_result'); ?></th>
                <th class="px-6 py-3 font-medium w-28 text-right"><?php echo __('cron_col_action'); ?></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            <?php foreach ($tasks as $t): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-6 py-3">
                    <div class="font-medium text-gray-800"><?php echo e($t['label']); ?></div>
                    <div class="text-xs text-gray-400"><?php echo e($t['name']); ?></div>
                </td>
                <td class="px-6 py-3 text-gray-500"><?php echo e(cronInterval($t['interval'])); ?></td>
                <td class="px-6 py-3 text-gray-500">
                    <?php echo $t['last'] > 0 ? date('Y-m-d H:i', $t['last']) : '<span class="text-gray-300">' . __('cron_never') . '</span>'; ?>
                </td>
                <td class="px-6 py-3">
                    <?php if ($t['status'] === 'ok'): ?>
                        <span class="text-green-600"><i class="ti ti-circle-check"></i></span>
                    <?php elseif ($t['status'] === 'fail'): ?>
                        <span class="text-red-500"><i class="ti ti-alert-circle"></i></span>
                    <?php endif; ?>
                    <span class="text-gray-500 text-xs"><?php echo e($t['msg']); ?></span>
                </td>
                <td class="px-6 py-3 text-right">
                    <button type="button" onclick="runTask('<?php echo e($t['name']); ?>', this)"
                            class="text-primary hover:text-secondary inline-flex items-center gap-1 cursor-pointer">
                        <i class="ti ti-player-play"></i><?php echo __('cron_run_now'); ?>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- 运行历史 -->
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="px-6 py-4 border-b"><h2 class="font-bold text-gray-800"><?php echo __('cron_history'); ?></h2></div>
    <?php if (empty($history)): ?>
    <div class="p-8 text-center text-gray-400"><?php echo __('cron_no_history'); ?></div>
    <?php else: ?>
    <table class="w-full text-sm">
        <tbody class="divide-y">
            <?php foreach ($history as $h): ?>
            <tr>
                <td class="px-6 py-2 text-gray-500 w-44"><?php echo date('Y-m-d H:i:s', (int) $h['at']); ?></td>
                <td class="px-6 py-2"><?php echo e($h['name']); ?></td>
                <td class="px-6 py-2"><?php echo !empty($h['ok']) ? '<span class="text-green-600">OK</span>' : '<span class="text-red-500">FAIL</span>'; ?></td>
                <td class="px-6 py-2 text-gray-500"><?php echo e($h['msg']); ?> <span class="text-gray-300">(<?php echo (int) $h['ms']; ?>ms)</span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
const CSRF = <?php echo json_encode(csrfToken()); ?>;
const CSRF_NAME = <?php echo json_encode(CSRF_TOKEN_NAME); ?>;

async function cronPost(body) {
    body[CSRF_NAME] = CSRF;
    const res = await fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new URLSearchParams(body) });
    return res.json().catch(() => ({ code: 1, msg: 'error' }));
}
async function runTask(name, btn) {
    btn.disabled = true; btn.style.opacity = '.5';
    const data = await cronPost({ action: 'run', name });
    alert(data.msg || 'done');
    location.reload();
}
function copyCron() {
    const txt = document.getElementById('cronCmd').textContent;
    navigator.clipboard.writeText(txt).then(() => alert(<?php echo json_encode(__('cron_copied')); ?>));
}
async function resetToken() {
    if (!confirm(<?php echo json_encode(__('cron_reset_confirm')); ?>)) return;
    await cronPost({ action: 'reset_token' });
    location.reload();
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
