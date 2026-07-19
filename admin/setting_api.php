<?php
/**
 * Yikai CMS - 开放接口（公开内容 API）设置
 *
 * 控制 /api/v1 只读 JSON 接口的开关、可选 API Key、限流。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

$currentMenu = 'setting_api';
$pageTitle = __('apiset_title');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $enabled = !empty($_POST['public_api_enabled']) ? '1' : '0';
        $rate    = max(0, min(100000, (int) ($_POST['public_api_rate'] ?? 60)));
        settingModel()->set('public_api_enabled', $enabled, 'api');
        settingModel()->set('public_api_rate', (string) $rate, 'api');
        adminLog('setting', 'api', "开放接口: enabled=$enabled rate=$rate");
        echo json_encode(['code' => 0, 'msg' => __('apiset_saved')]);
        exit;
    }

    if ($action === 'regen_key') {
        $key = 'ykapi_' . bin2hex(random_bytes(16));
        settingModel()->set('public_api_key', $key, 'api');
        adminLog('setting', 'api', '重置 API Key');
        echo json_encode(['code' => 0, 'msg' => __('apiset_key_new'), 'data' => ['key' => $key]]);
        exit;
    }

    if ($action === 'clear_key') {
        settingModel()->set('public_api_key', '', 'api');
        adminLog('setting', 'api', '清除 API Key');
        echo json_encode(['code' => 0, 'msg' => __('apiset_key_cleared')]);
        exit;
    }

    echo json_encode(['code' => 1, 'msg' => 'unknown action']);
    exit;
}

$enabled = config('public_api_enabled', '0') === '1';
$rate    = (int) config('public_api_rate', 60);
$apiKey  = (string) config('public_api_key', '');
$base    = rtrim((string) (config('site_url', '') ?: (defined('SITE_URL') ? SITE_URL : '')), '/');
$ex      = ($base !== '' ? $base : '') . '/api/v1/';

$endpoints = [
    ['channels', '?resource=channels&nav=1',                              __('apiset_ep_channels')],
    ['contents', '?resource=contents&channel=news&page=1',               __('apiset_ep_contents')],
    ['content',  '?resource=content&id=1',                               __('apiset_ep_content')],
    ['products', '?resource=products&category=&brand=&tag=&pmin=&pmax=&sort=', __('apiset_ep_products')],
    ['product',  '?resource=product&id=1',                               __('apiset_ep_product')],
    ['search',   '?resource=search&q=keyword&type=all',                  __('apiset_ep_search')],
];

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><?php echo __('apiset_title'); ?></h1>
        <p class="text-sm text-gray-500 mt-1"><?php echo __('apiset_intro'); ?></p>
    </div>

    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1"><?php echo __('apiset_status'); ?></div>
            <div class="text-2xl font-bold <?php echo $enabled ? 'text-green-600' : 'text-gray-400'; ?>"><?php echo $enabled ? __('apiset_enabled') : __('apiset_disabled'); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1"><?php echo __('apiset_auth'); ?></div>
            <div class="text-2xl font-bold <?php echo $apiKey !== '' ? 'text-gray-800' : 'text-gray-400'; ?>"><?php echo $apiKey !== '' ? __('apiset_need_key') : __('apiset_public'); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1"><?php echo __('apiset_rate'); ?></div>
            <div class="text-2xl font-bold text-gray-800"><?php echo $rate > 0 ? $rate : '∞'; ?></div>
            <div class="text-xs text-gray-400 mt-0.5"><?php echo __('apiset_rate_unit'); ?></div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form id="apiForm" onsubmit="event.preventDefault(); saveApi();">
            <div class="space-y-5">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="public_api_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?> class="mt-1 w-5 h-5">
                    <div>
                        <div class="font-medium text-gray-800"><?php echo __('apiset_enable_label'); ?></div>
                        <div class="text-sm text-gray-500 mt-0.5"><?php echo __('apiset_enable_desc'); ?></div>
                    </div>
                </label>
                <div>
                    <label class="block font-medium text-gray-800 mb-1"><?php echo __('apiset_rate_label'); ?></label>
                    <input type="number" name="public_api_rate" value="<?php echo $rate; ?>" min="0" max="100000" class="w-40 border rounded px-3 py-2">
                    <span class="text-sm text-gray-500 ml-2"><?php echo __('apiset_rate_hint'); ?></span>
                </div>
            </div>
            <div class="mt-6"><button type="submit" class="bg-primary hover:opacity-90 text-white px-6 py-2 rounded"><?php echo __('apiset_save'); ?></button></div>
        </form>
    </div>

    <!-- API Key -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="font-medium text-gray-800 mb-2"><?php echo __('apiset_key_title'); ?></div>
        <div class="text-sm text-gray-500 mb-3"><?php echo __('apiset_key_desc'); ?></div>
        <div class="flex items-center gap-2 flex-wrap">
            <input id="apiKeyBox" type="text" readonly value="<?php echo htmlspecialchars($apiKey); ?>" placeholder="<?php echo __('apiset_key_empty'); ?>"
                   class="flex-1 min-w-0 border rounded px-3 py-2 bg-gray-50 font-mono text-sm">
            <button type="button" onclick="regenKey()" class="bg-primary hover:opacity-90 text-white px-4 py-2 rounded"><?php echo __('apiset_key_gen'); ?></button>
            <button type="button" onclick="clearKey()" class="border border-gray-300 hover:bg-gray-50 px-4 py-2 rounded text-red-600"><?php echo __('apiset_key_clear'); ?></button>
        </div>
    </div>

    <!-- 接口清单 -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="font-medium text-gray-800 mb-3"><?php echo __('apiset_endpoints'); ?></div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-400 border-b">
                    <th class="py-2 pr-4"><?php echo __('apiset_col_resource'); ?></th>
                    <th class="py-2 pr-4"><?php echo __('apiset_col_example'); ?></th>
                    <th class="py-2"><?php echo __('apiset_col_desc'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($endpoints as [$name, $q, $desc]): ?>
                    <tr class="border-b last:border-0">
                        <td class="py-2 pr-4 font-mono text-gray-700"><?php echo $name; ?></td>
                        <td class="py-2 pr-4"><code class="text-xs text-primary break-all"><?php echo htmlspecialchars($ex . $q); ?></code></td>
                        <td class="py-2 text-gray-500"><?php echo htmlspecialchars($desc); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="text-xs text-gray-400 mt-3"><?php echo __('apiset_envelope'); ?></div>
    </div>
</div>

<script>
async function postApi(fd) {
    const r = await fetch('', { method: 'POST', body: fd });
    return r.json();
}
async function saveApi() {
    const fd = new FormData(document.getElementById('apiForm'));
    fd.append('action', 'save');
    const d = await postApi(fd);
    showMessage(d.msg || 'OK', d.code === 0 ? 'success' : 'error');
    if (d.code === 0) setTimeout(() => location.reload(), 600);
}
async function regenKey() {
    if (!confirm(<?php echo json_encode(__('apiset_confirm_regen')); ?>)) return;
    const fd = new FormData(); fd.append('action', 'regen_key');
    const d = await postApi(fd);
    if (d.code === 0) { document.getElementById('apiKeyBox').value = d.data.key; }
    showMessage(d.msg || 'OK', d.code === 0 ? 'success' : 'error');
    setTimeout(() => location.reload(), 800);
}
async function clearKey() {
    if (!confirm(<?php echo json_encode(__('apiset_confirm_clear')); ?>)) return;
    const fd = new FormData(); fd.append('action', 'clear_key');
    const d = await postApi(fd);
    showMessage(d.msg || 'OK', d.code === 0 ? 'success' : 'error');
    setTimeout(() => location.reload(), 600);
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
