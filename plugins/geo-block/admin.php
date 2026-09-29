<?php
/**
 * 地区访问限制 - 设置页
 * 由 /admin/plugin_page.php?plugin=geo-block 加载（已 checkLogin + CSRF）。
 */

declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition Direct requests do not load the CMS bootstrap. */
if (!defined('ROOT_PATH')) exit('Access Denied');

require_once __DIR__ . '/GeoBlock.php';

$gbStatus = GeoBlock::status();
$gbLanguages = function_exists('enabledLanguages') ? enabledLanguages() : ['zh-CN' => '简体中文'];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $gbAction = (string) ($_POST['gb_action_type'] ?? '');
    if ($gbAction === 'test') {
        // IP 自检：只查地区，不改任何设置
        $testIp = trim((string) ($_POST['ip'] ?? ''));
        if (filter_var($testIp, FILTER_VALIDATE_IP) === false) {
            error(__('gb_test_invalid'));
        }
        success(['mainland' => GeoBlockRanges::bundled()->contains($testIp)]);
    }
    if ($gbAction === 'save') {
        if ($gbStatus !== 'ready') {
            error(__('gb_license_required'));
        }
        $allowIps = ClientIpResolver::parseRules((string) ($_POST['gb_allow_ips'] ?? ''));
        if ($allowIps['invalid'] !== []) {
            error(__('gb_invalid_ips', ['ips' => implode(', ', array_slice($allowIps['invalid'], 0, 5))]));
        }
        $action = ($_POST['gb_action'] ?? 'block') === 'redirect' ? 'redirect' : 'block';
        $redirect = trim((string) ($_POST['gb_redirect_url'] ?? ''));
        if ($action === 'redirect' && GeoBlock::validRedirect($redirect, (string) ($_SERVER['HTTP_HOST'] ?? '')) === null) {
            error(__('gb_invalid_redirect'));
        }
        $messages = [];
        foreach (array_keys($gbLanguages) as $code) {
            $text = trim((string) ($_POST['gb_message'][$code] ?? ''));
            if ($text !== '') {
                $messages[(string) $code] = mb_substr($text, 0, 500);
            }
        }
        settingModel()->set('gb_enabled', isset($_POST['gb_enabled']) ? '1' : '0', 'plugin');
        settingModel()->set('gb_action', $action, 'plugin');
        settingModel()->set('gb_redirect_url', mb_substr($redirect, 0, 500), 'plugin');
        settingModel()->set('gb_messages', json_encode($messages, JSON_UNESCAPED_UNICODE), 'plugin');
        settingModel()->set('gb_allow_ips', implode("\n", $allowIps['rules']), 'plugin');
        settingModel()->set('gb_exempt_paths', implode("\n", GeoBlock::parsePaths((string) ($_POST['gb_exempt_paths'] ?? ''))), 'plugin');
        settingModel()->set('gb_allow_admins', isset($_POST['gb_allow_admins']) ? '1' : '0', 'plugin');
        settingModel()->set('gb_cdn_header', isset($_POST['gb_cdn_header']) ? '1' : '0', 'plugin');
        adminLog('plugin', 'update', __('gb_log_update'));
        success();
    }
    error(__('operation_failed'));
}

$gbSettings = GeoBlock::settings();
$gbRanges = GeoBlockRanges::bundled();
$gbCounts = $gbRanges->counts();
$gbMeta = json_decode((string) @file_get_contents(__DIR__ . '/data/meta.json'), true);
$gbMeta = is_array($gbMeta) ? $gbMeta : [];
// 「你现在访问会怎样」：按当前设置判定管理员本人（不计入后台会话放行，便于看清真实结果）
$gbSelf = GeoBlock::currentRequest();
$gbSelfIp = (string) $gbSelf['ip'];
$gbSelfMainland = $gbRanges->contains($gbSelfIp);
$gbSelfDecision = GeoBlock::decide(['admin' => false] + $gbSelf, ['enabled' => true] + $gbSettings, true, $gbRanges);
$gbLocked = $gbStatus !== 'ready';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="max-w-3xl space-y-6" data-testid="geo-block-admin">
    <?php if ($gbLocked): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800" data-testid="geo-block-locked">
        <i class="ti ti-lock"></i> <?php echo e(__('gb_status_' . $gbStatus)); ?>
        <?php if ($gbStatus === 'unlicensed'): ?><a href="/admin/license.php" class="ml-1 underline"><?php echo e(__('gb_license_link')); ?></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="font-bold text-gray-800 mb-1"><?php echo e(__('gb_title')); ?></h2>
        <p class="text-sm text-gray-500 mb-4"><?php echo e(__('gb_desc')); ?></p>
        <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-3">
            <div class="rounded border border-gray-100 bg-gray-50 px-3 py-2">
                <dt class="text-xs text-gray-500"><?php echo e(__('gb_state')); ?></dt>
                <dd class="mt-0.5 font-medium <?php echo $gbSettings['enabled'] && !$gbLocked ? 'text-green-700' : 'text-gray-600'; ?>">
                    <?php echo e($gbSettings['enabled'] && !$gbLocked ? __('gb_state_on') : __('gb_state_off')); ?>
                </dd>
            </div>
            <div class="rounded border border-gray-100 bg-gray-50 px-3 py-2">
                <dt class="text-xs text-gray-500"><?php echo e(__('gb_data')); ?></dt>
                <dd class="mt-0.5 font-medium text-gray-700">
                    <?php echo e(__('gb_data_value', ['v4' => (string) $gbCounts['ipv4'], 'v6' => (string) $gbCounts['ipv6']])); ?>
                    <span class="block text-xs font-normal text-gray-400"><?php echo e(__('gb_data_date', ['date' => (string) ($gbMeta['source_date'] ?? '—')])); ?></span>
                </dd>
            </div>
            <div class="rounded border border-gray-100 bg-gray-50 px-3 py-2" data-testid="geo-block-self">
                <dt class="text-xs text-gray-500"><?php echo e(__('gb_self')); ?></dt>
                <dd class="mt-0.5 font-medium text-gray-700 font-mono"><?php echo e($gbSelfIp); ?></dd>
                <dd class="text-xs <?php echo $gbSelfDecision['block'] ? 'text-red-600' : 'text-gray-500'; ?>">
                    <?php echo e($gbSelfMainland ? __('gb_region_mainland') : __('gb_region_other')); ?> ·
                    <?php echo e($gbSelfDecision['block'] ? __('gb_self_blocked') : __('gb_self_allowed')); ?>
                </dd>
            </div>
        </dl>
        <?php if ($gbRanges->isEmpty()): ?>
            <p class="mt-3 text-sm text-red-600"><?php echo e(__('gb_data_missing')); ?></p>
        <?php endif; ?>
    </div>

    <form id="gbForm" class="bg-white rounded-lg shadow p-6 space-y-5">
        <input type="hidden" name="gb_action_type" value="save">
        <fieldset class="space-y-5" <?php echo $gbLocked ? 'disabled' : ''; ?>>
            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="gb_enabled" value="1" <?php echo $gbSettings['enabled'] ? 'checked' : ''; ?>
                       data-testid="geo-block-enabled" class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700 font-medium"><?php echo e(__('gb_enabled')); ?>
                    <span class="block text-xs font-normal text-gray-400 mt-0.5"><?php echo e(__('gb_enabled_tip')); ?></span>
                </span>
            </label>

            <div>
                <span class="block text-sm text-gray-700 mb-2"><?php echo e(__('gb_action')); ?></span>
                <div class="flex flex-wrap gap-4 text-sm text-gray-700">
                    <label class="inline-flex items-center gap-1.5"><input type="radio" name="gb_action" value="block" <?php echo $gbSettings['action'] === 'block' ? 'checked' : ''; ?>> <?php echo e(__('gb_action_block')); ?></label>
                    <label class="inline-flex items-center gap-1.5"><input type="radio" name="gb_action" value="redirect" <?php echo $gbSettings['action'] === 'redirect' ? 'checked' : ''; ?>> <?php echo e(__('gb_action_redirect')); ?></label>
                </div>
                <input type="url" name="gb_redirect_url" value="<?php echo e($gbSettings['redirect_url']); ?>" placeholder="https://example.cn/"
                       class="mt-2 w-full border rounded px-3 py-2 text-sm" data-testid="geo-block-redirect">
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('gb_redirect_tip')); ?></p>
            </div>

            <div class="space-y-2">
                <span class="block text-sm text-gray-700"><?php echo e(__('gb_message')); ?></span>
                <?php foreach ($gbLanguages as $gbCode => $gbLabel): ?>
                <label class="block">
                    <span class="text-xs text-gray-500"><?php echo e((string) $gbLabel); ?></span>
                    <textarea name="gb_message[<?php echo e((string) $gbCode); ?>]" rows="2" maxlength="500"
                              placeholder="<?php echo e(__('gb_default_message')); ?>"
                              class="w-full border rounded px-3 py-2 text-sm"><?php echo e((string) ($gbSettings['messages'][$gbCode] ?? '')); ?></textarea>
                </label>
                <?php endforeach; ?>
                <p class="text-xs text-gray-400"><?php echo e(__('gb_message_tip')); ?></p>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('gb_allow_ips')); ?></label>
                <textarea name="gb_allow_ips" rows="3" class="w-full border rounded px-3 py-2 text-sm font-mono"
                          placeholder="203.0.113.10&#10;198.51.100.0/24"><?php echo e($gbSettings['allow_ips']); ?></textarea>
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('gb_allow_ips_tip')); ?></p>
            </div>

            <div>
                <label class="block text-sm text-gray-700 mb-1"><?php echo e(__('gb_exempt_paths')); ?></label>
                <textarea name="gb_exempt_paths" rows="3" class="w-full border rounded px-3 py-2 text-sm font-mono"
                          placeholder="/api/v1/&#10;/sitemap.xml"><?php echo e($gbSettings['exempt_paths']); ?></textarea>
                <p class="text-xs text-gray-400 mt-1"><?php echo e(__('gb_exempt_paths_tip')); ?></p>
            </div>

            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="gb_allow_admins" value="1" <?php echo $gbSettings['allow_admins'] ? 'checked' : ''; ?>
                       class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700"><?php echo e(__('gb_allow_admins')); ?>
                    <span class="block text-xs text-gray-400 mt-0.5"><?php echo e(__('gb_allow_admins_tip')); ?></span>
                </span>
            </label>

            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="gb_cdn_header" value="1" <?php echo $gbSettings['cdn_header'] ? 'checked' : ''; ?>
                       class="mt-0.5 rounded border-gray-300 text-primary focus:ring-primary">
                <span class="text-sm text-gray-700"><?php echo e(__('gb_cdn_header')); ?>
                    <span class="block text-xs text-gray-400 mt-0.5"><?php echo e(__('gb_cdn_header_tip')); ?></span>
                </span>
            </label>

            <button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition inline-flex items-center gap-1 disabled:opacity-50">
                <i class="ti ti-check text-base"></i> <?php echo e(__('gb_save')); ?>
            </button>
        </fieldset>
    </form>

    <div class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-gray-800 mb-2 text-sm"><?php echo e(__('gb_test_title')); ?></h3>
        <form id="gbTest" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="gb_action_type" value="test">
            <input type="text" name="ip" required placeholder="114.114.114.114" class="w-64 border rounded px-3 py-2 text-sm font-mono" data-testid="geo-block-test-ip">
            <button type="submit" class="border border-gray-300 rounded px-4 py-2 text-sm hover:border-primary hover:text-primary"><?php echo e(__('gb_test_button')); ?></button>
            <span id="gbTestResult" class="text-sm" aria-live="polite"></span>
        </form>
    </div>

    <div class="bg-white rounded-lg shadow p-6 text-sm text-gray-600 space-y-2">
        <h3 class="font-bold text-gray-800 text-sm"><?php echo e(__('gb_notes_title')); ?></h3>
        <ul class="list-disc pl-5 space-y-1.5">
            <li><?php echo e(__('gb_note_admin')); ?></li>
            <li><?php echo e(__('gb_note_static')); ?></li>
            <li><?php echo e(__('gb_note_cdn')); ?></li>
            <li><?php echo e(__('gb_note_seo')); ?></li>
            <li><?php echo e(__('gb_note_accuracy')); ?></li>
        </ul>
    </div>
</div>

<script>
(function () {
    var text = <?php echo json_encode([
        'ok' => __('operation_success'),
        'failed' => __('operation_failed'),
        'mainland' => __('gb_region_mainland'),
        'other' => __('gb_region_other'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    document.getElementById('gbForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        var data = await safeJson(await fetch('', { method: 'POST', body: new FormData(this) }));
        if (data.code === 0) { showMessage(text.ok); setTimeout(function () { location.reload(); }, 600); }
        else showMessage(data.msg || text.failed, 'error');
    });
    document.getElementById('gbTest').addEventListener('submit', async function (e) {
        e.preventDefault();
        var result = document.getElementById('gbTestResult');
        var data = await safeJson(await fetch('', { method: 'POST', body: new FormData(this) }));
        if (data.code !== 0) { result.className = 'text-sm text-red-600'; result.textContent = data.msg || text.failed; return; }
        result.className = 'text-sm ' + (data.data.mainland ? 'text-red-600' : 'text-green-700');
        result.textContent = data.data.mainland ? text.mainland : text.other;
    });
})();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
