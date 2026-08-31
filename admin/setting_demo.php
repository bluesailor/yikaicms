<?php
/**
 * Yikai CMS - 演示模式开关（隐藏页）
 *
 * 不在后台侧栏挂链接，直接通过 /admin/setting_demo.php 访问。
 * 三态写入 yikai_settings.demo_mode：0 关 / 1 只读演示 / 2 演示沙盒。
 * Compatibility::initDemoMode() 每次请求读取并定义 DEMO_MODE 与 DEMO_SANDBOX。
 *
 * 为什么切换要额外验站长口令（DemoSandbox::ownerTokenMatches）：
 * 公开演示站的超管账号密码本身就是公开的，`requirePermission('*')` 对访客形同虚设，
 * 访客只要打开本页就能把演示模式关掉、拿到一个完全可写的真站。站长口令独立于 cron token，
 * 只有能读库或能登 shell 的人拿得到——这才是「站长」与「演示超管」的分界线。
 */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
require_once ROOT_PATH . '/includes/Cron.php';
require_once ROOT_PATH . '/includes/DemoSandbox.php';

checkLogin();
requirePermission('*');

$currentMode = DemoSandbox::mode();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = post('action');

    // 口令闸放在任何动作分派之前，且不挑动作——本页所有 POST 都是高风险操作，
    // 用具名白名单意味着以后新增一个动作、忘了加进名单就会绕过闸门。
    $ownerToken = trim((string) post('owner_token', ''));
    if ($ownerToken === '') {
        error(__('dm_owner_token_required'));
    }
    if (!DemoSandbox::ownerTokenMatches($ownerToken)) {
        adminLog('setting', 'demo_mode', 'owner token rejected');
        error(__('dm_owner_token_bad'));
    }

    if ($action === 'save_mode') {
        $requestedMode = (string) post('demo_mode', DemoSandbox::MODE_OFF);
        // 非法取值直接报错，不做静默收敛：把看不懂的输入当成「关闭」，
        // 正是旧版沙盒被静默降级的成因。
        if (!DemoSandbox::isValidMode($requestedMode)) {
            error(__('blox_bad_request'));
        }
        $newMode = DemoSandbox::normalizeMode($requestedMode);

        // 沙盒没有快照就等于没有「回到原样」的能力，开了也白开。
        if ($newMode === DemoSandbox::MODE_SANDBOX && !DemoSandbox::hasSnapshot()) {
            error(__('dm_sandbox_needs_snapshot'));
        }

        if ($newMode !== $currentMode) {
            settingModel()->set('demo_mode', $newMode);
            adminLog('setting', 'demo_mode', 'demo mode: ' . $currentMode . ' -> ' . $newMode);
        }

        $interval = (string) post('demo_reset_interval', '');
        if ($newMode === DemoSandbox::MODE_SANDBOX && ctype_digit($interval)) {
            settingModel()->set('demo_reset_interval', (string) max(DemoSandbox::MIN_INTERVAL, (int) $interval));
        }

        success([], __('admin_saved'));
    }

    if ($action === 'snapshot') {
        try {
            $m = DemoSandbox::snapshot();
        } catch (Throwable $e) {
            error($e->getMessage());
        }
        adminLog('setting', 'demo_snapshot', 'demo snapshot rebuilt');
        success([], __('dm_snapshot_done', [
            'tables' => (int) $m['tables'],
            'files' => (int) $m['files'],
        ]));
    }

    if ($action === 'reset') {
        try {
            $r = DemoSandbox::reset('admin');
        } catch (Throwable $e) {
            error($e->getMessage());
        }
        adminLog('setting', 'demo_reset', 'demo reset from admin');
        success([], __('dm_reset_done', [
            'statements' => (int) $r['statements'],
            'files' => (int) $r['files'],
        ]));
    }
}

$pageTitle = __('dm_title');
$currentMenu = '';
$manifest = DemoSandbox::manifest();
$lastReset = DemoSandbox::lastReset();

// 口令只在「尚未配置」时显示一次——那是它刚被创建出来、除了这里没有别处能拿到的时刻。
// 已配置就再也不回显；开启演示后缺失口令也不允许访客从 Web 签发。
// （浏览器历史、截图、旁人一瞥），而这恰恰削弱了它「只有能读库或登 shell 的人才知道」的边界。
$ownerTokenConfigured = trim((string) settingModel()->get('demo_owner_token', '')) !== '';
$mayIssueOwnerToken = $currentMode === DemoSandbox::MODE_OFF
    && !(defined('DEMO_MODE') && DEMO_MODE) && !(defined('DEMO_SANDBOX') && DEMO_SANDBOX);
$issuedToken = !$ownerTokenConfigured && $mayIssueOwnerToken ? DemoSandbox::ownerToken() : '';

// 注意：不要拿 MODE_* 当数组键。它们是 '0'/'1'/'2'，PHP 会把数字字符串键
// 静默转成 int，于是 e($value) 收到 int 直接 TypeError，
// 且 $currentMode === $value 这种严格比较恒为 false，没有一项会被选中。
$modes = [
    ['value' => DemoSandbox::MODE_OFF, 'label' => __('dm_mode_off'), 'hint' => __('dm_mode_off_hint'), 'tone' => 'text-green-600'],
    ['value' => DemoSandbox::MODE_READONLY, 'label' => __('dm_mode_readonly'), 'hint' => __('dm_mode_readonly_hint'), 'tone' => 'text-orange-600'],
    ['value' => DemoSandbox::MODE_SANDBOX, 'label' => __('dm_mode_sandbox'), 'hint' => __('dm_mode_sandbox_hint'), 'tone' => 'text-cyan-600'],
];
$currentMeta = $modes[0];
foreach ($modes as $meta) {
    if ($meta['value'] === $currentMode) {
        $currentMeta = $meta;
    }
}

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-2xl mx-auto space-y-6">
    <form id="demoForm" class="space-y-6">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_mode">

        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo e(__('dm_heading')); ?></h2>
                <p class="text-sm text-gray-500 mt-1"><?php echo e(__('dm_desc_modes')); ?></p>
                <p class="text-xs text-gray-400 mt-2">
                    <?php echo e(__('dm_current_state')); ?>
                    <span class="font-medium <?php echo e($currentMeta['tone']); ?>">
                        <?php echo e($currentMeta['label']); ?>
                    </span>
                </p>
            </div>

            <div class="p-6 space-y-3">
                <?php foreach ($modes as $meta): ?>
                    <label class="flex items-start gap-3 p-4 rounded-lg border hover:bg-gray-50 cursor-pointer">
                        <input type="radio" name="demo_mode" value="<?php echo e($meta['value']); ?>"
                               <?php echo $currentMode === $meta['value'] ? 'checked' : ''; ?>
                               class="w-4 h-4 mt-1">
                        <div>
                            <span class="font-medium text-gray-700"><?php echo e($meta['label']); ?></span>
                            <p class="text-xs text-gray-400 mt-1"><?php echo e($meta['hint']); ?></p>
                        </div>
                    </label>
                <?php endforeach; ?>

                <div class="pl-4 border-l-2 border-gray-100">
                    <label class="block text-xs text-gray-500 mb-1"><?php echo e(__('dm_interval')); ?></label>
                    <input type="number" name="demo_reset_interval" min="<?php echo DemoSandbox::MIN_INTERVAL; ?>"
                           value="<?php echo e((string) DemoSandbox::interval()); ?>"
                           class="w-40 border rounded px-3 py-1.5 text-sm">
                    <p class="text-xs text-gray-400 mt-1"><?php echo e(__('dm_interval_hint')); ?></p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo e(__('dm_owner_token')); ?></h2>
                <p class="text-sm text-gray-500 mt-1"><?php echo e(__('dm_owner_token_hint')); ?></p>
            </div>
            <div class="p-6 space-y-3">
                <input type="password" name="owner_token" autocomplete="off"
                       placeholder="<?php echo e(__('dm_owner_token_placeholder')); ?>"
                       class="w-full border rounded px-3 py-2 font-mono text-sm">
                <?php if ($issuedToken !== ''): ?>
                    <div class="bg-amber-50 border border-amber-200 rounded p-3 text-xs text-amber-800">
                        <div class="font-medium"><?php echo e(__('dm_owner_token_issued')); ?></div>
                        <code class="inline-block bg-white border px-2 py-0.5 rounded mt-2 select-all"><?php echo e($issuedToken); ?></code>
                    </div>
                <?php else: ?>
                    <div class="bg-gray-50 border rounded p-3 text-xs text-gray-600">
                        <?php echo e(__($ownerTokenConfigured ? 'dm_owner_token_hidden' : 'dm_owner_token_cli_required')); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="bg-primary hover:bg-secondary text-white px-8 py-2 rounded transition inline-flex items-center gap-2">
                <i class="ti ti-check text-base"></i>
                <?php echo e(__('admin_save')); ?>
            </button>
        </div>
    </form>

    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h2 class="font-bold text-gray-800"><?php echo e(__('dm_snapshot_title')); ?></h2>
            <p class="text-sm text-gray-500 mt-1"><?php echo e(__('dm_snapshot_desc')); ?></p>
        </div>
        <div class="p-6 space-y-4">
            <div class="text-xs text-gray-600 space-y-1">
                <div><?php echo e($manifest === null
                    ? __('dm_snapshot_none')
                    : __('dm_snapshot_info', [
                        'at' => (string) ($manifest['created_at'] ?? '?'),
                        'tables' => (int) ($manifest['tables'] ?? 0),
                        'files' => (int) ($manifest['files'] ?? 0),
                    ])); ?></div>
                <div><?php echo e($lastReset === null
                    ? __('dm_reset_never')
                    : __('dm_reset_last', [
                        'at' => (string) ($lastReset['at'] ?? '?'),
                        'trigger' => (string) ($lastReset['trigger'] ?? '?'),
                    ])); ?></div>
            </div>
            <div class="flex gap-3">
                <button type="button" data-demo-action="snapshot"
                        class="border border-gray-300 hover:bg-gray-50 px-5 py-2 rounded text-sm inline-flex items-center gap-2">
                    <i class="ti ti-camera text-base"></i><?php echo e(__('dm_snapshot_btn')); ?>
                </button>
                <button type="button" data-demo-action="reset" <?php echo $manifest === null ? 'disabled' : ''; ?>
                        class="border border-red-300 text-red-600 hover:bg-red-50 disabled:opacity-40 disabled:cursor-not-allowed px-5 py-2 rounded text-sm inline-flex items-center gap-2">
                    <i class="ti ti-refresh text-base"></i><?php echo e(__('dm_reset_btn')); ?>
                </button>
            </div>
        </div>
    </div>

    <div class="bg-blue-50 border border-blue-200 rounded p-3 text-xs text-blue-700 space-y-1">
        <div><strong><?php echo e(__('dm_entry')); ?></strong>/admin/setting_demo.php<?php echo e(__('dm_entry_note')); ?></div>
        <div><strong><?php echo e(__('dm_mechanism')); ?></strong><?php echo str_replace([':row', ':const'], ['<code>' . DB_PREFIX . 'settings.demo_mode</code>', '<code>config/config.php</code>'], e(__('dm_mechanism_note'))); ?></div>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('demoForm');
    var savedMsg = <?php echo json_encode(__('admin_saved'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var failMsg = <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var confirmReset = <?php echo json_encode(__('dm_reset_confirm'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var needToken = <?php echo json_encode(__('dm_owner_token_required'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

    async function submit(action) {
        var fd = new FormData(form);
        fd.set('action', action);
        if (!String(fd.get('owner_token') || '').trim()) {
            showMessage(needToken, 'error');
            return;
        }
        var resp = await fetch('', { method: 'POST', body: fd });
        var data = await safeJson(resp);
        if (data.code === 0) {
            showMessage(data.msg || savedMsg);
            setTimeout(function () { location.reload(); }, 900);
        } else {
            showMessage(data.msg || failMsg, 'error');
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        submit('save_mode');
    });

    document.querySelectorAll('[data-demo-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var action = btn.getAttribute('data-demo-action');
            if (action === 'reset' && !window.confirm(confirmReset)) return;
            submit(action);
        });
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
