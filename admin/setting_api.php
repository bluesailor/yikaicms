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
$pageTitle = '开放接口';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $enabled = !empty($_POST['public_api_enabled']) ? '1' : '0';
        $rate    = max(0, min(100000, (int) ($_POST['public_api_rate'] ?? 60)));
        settingModel()->set('public_api_enabled', $enabled, 'api');
        settingModel()->set('public_api_rate', (string) $rate, 'api');
        adminLog('setting', 'api', "开放接口: enabled=$enabled rate=$rate");
        echo json_encode(['code' => 0, 'msg' => '已保存']);
        exit;
    }

    if ($action === 'regen_key') {
        $key = 'ykapi_' . bin2hex(random_bytes(16));
        settingModel()->set('public_api_key', $key, 'api');
        adminLog('setting', 'api', '重置 API Key');
        echo json_encode(['code' => 0, 'msg' => '已生成新 Key', 'data' => ['key' => $key]]);
        exit;
    }

    if ($action === 'clear_key') {
        settingModel()->set('public_api_key', '', 'api');
        adminLog('setting', 'api', '清除 API Key');
        echo json_encode(['code' => 0, 'msg' => '已清除 Key（接口将无需 Key）']);
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
    ['channels',  '?resource=channels&nav=1',                 '栏目/导航树'],
    ['contents',  '?resource=contents&channel=news&page=1',   '内容列表（channel 可用 id 或 slug；recommend/hot/top/keyword）'],
    ['content',   '?resource=content&id=1',                   '内容详情（含正文）'],
    ['products',  '?resource=products&category=&brand=&tag=&pmin=&pmax=&sort=', '产品列表（复用多条件筛选）'],
    ['product',   '?resource=product&id=1',                   '产品详情（含正文/规格）'],
    ['search',    '?resource=search&q=关键词&type=all',        '搜索（type=content/product/all）'],
];

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">开放接口（公开内容 API）</h1>
        <p class="text-sm text-gray-500 mt-1">只读 JSON 接口，供小程序 / App / 静态站 / AI 取站点已发布内容。默认关闭。</p>
    </div>

    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">状态</div>
            <div class="text-2xl font-bold <?php echo $enabled ? 'text-green-600' : 'text-gray-400'; ?>"><?php echo $enabled ? '已启用' : '未启用'; ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">鉴权</div>
            <div class="text-2xl font-bold <?php echo $apiKey !== '' ? 'text-gray-800' : 'text-gray-400'; ?>"><?php echo $apiKey !== '' ? '需 Key' : '公开'; ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">限流</div>
            <div class="text-2xl font-bold text-gray-800"><?php echo $rate > 0 ? $rate : '∞'; ?></div>
            <div class="text-xs text-gray-400 mt-0.5">次 / 分钟 / IP</div>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form id="apiForm" onsubmit="event.preventDefault(); saveApi();">
            <div class="space-y-5">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="public_api_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?> class="mt-1 w-5 h-5">
                    <div>
                        <div class="font-medium text-gray-800">启用开放接口</div>
                        <div class="text-sm text-gray-500 mt-0.5">开启后 <code>/api/v1</code> 对外可访问，仅返回已发布内容的白名单字段。</div>
                    </div>
                </label>
                <div>
                    <label class="block font-medium text-gray-800 mb-1">每 IP 限流（次/分钟）</label>
                    <input type="number" name="public_api_rate" value="<?php echo $rate; ?>" min="0" max="100000" class="w-40 border rounded px-3 py-2">
                    <span class="text-sm text-gray-500 ml-2">0 = 不限流</span>
                </div>
            </div>
            <div class="mt-6"><button type="submit" class="bg-primary hover:opacity-90 text-white px-6 py-2 rounded">保存设置</button></div>
        </form>
    </div>

    <!-- API Key -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="font-medium text-gray-800 mb-2">API Key（可选）</div>
        <div class="text-sm text-gray-500 mb-3">设置后，调用需带 <code>X-API-Key</code> 头或 <code>?key=</code> 参数；清除则接口无需 Key（内容本就公开）。</div>
        <div class="flex items-center gap-2 flex-wrap">
            <input id="apiKeyBox" type="text" readonly value="<?php echo htmlspecialchars($apiKey); ?>" placeholder="（未设置）"
                   class="flex-1 min-w-0 border rounded px-3 py-2 bg-gray-50 font-mono text-sm">
            <button type="button" onclick="regenKey()" class="bg-primary hover:opacity-90 text-white px-4 py-2 rounded">生成新 Key</button>
            <button type="button" onclick="clearKey()" class="border border-gray-300 hover:bg-gray-50 px-4 py-2 rounded text-red-600">清除</button>
        </div>
    </div>

    <!-- 接口清单 -->
    <div class="bg-white rounded-lg shadow p-6">
        <div class="font-medium text-gray-800 mb-3">接口清单</div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-400 border-b">
                    <th class="py-2 pr-4">资源</th><th class="py-2 pr-4">示例</th><th class="py-2">说明</th>
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
        <div class="text-xs text-gray-400 mt-3">统一返回 <code>{code,msg,data}</code>；列表类 data 含 <code>items/total/page/limit</code>。</div>
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
    showMessage(d.msg || '完成', d.code === 0 ? 'success' : 'error');
    if (d.code === 0) setTimeout(() => location.reload(), 600);
}
async function regenKey() {
    if (!confirm('生成新 Key 会使旧 Key 立即失效，确定？')) return;
    const fd = new FormData(); fd.append('action', 'regen_key');
    const d = await postApi(fd);
    if (d.code === 0) { document.getElementById('apiKeyBox').value = d.data.key; }
    showMessage(d.msg || '完成', d.code === 0 ? 'success' : 'error');
    setTimeout(() => location.reload(), 800);
}
async function clearKey() {
    if (!confirm('清除后接口将无需 Key，确定？')) return;
    const fd = new FormData(); fd.append('action', 'clear_key');
    const d = await postApi(fd);
    showMessage(d.msg || '完成', d.code === 0 ? 'success' : 'error');
    setTimeout(() => location.reload(), 600);
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
