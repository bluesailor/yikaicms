<?php
/**
 * Yikai CMS - HTML 缓存设置
 *
 * 控制 HtmlCache 总开关、TTL，以及一键清空缓存。
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
if (!class_exists('HtmlCache')) {
    require_once ROOT_PATH . '/includes/HtmlCache.php';
}

checkLogin();
requirePermission('*');

$currentMenu = 'setting_cache';
$pageTitle = 'HTML 缓存设置';

// 处理 POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $enabled = !empty($_POST['html_cache_enabled']) ? '1' : '0';
        $ttl = max(60, min(86400, (int)($_POST['html_cache_ttl'] ?? 600)));
        settingModel()->set('html_cache_enabled', $enabled);
        settingModel()->set('html_cache_ttl', (string)$ttl);
        adminLog('cache', 'config', "缓存设置: enabled=$enabled ttl=$ttl");
        echo json_encode(['code' => 0, 'msg' => '已保存']);
        exit;
    }

    if ($action === 'clear') {
        $count = HtmlCache::invalidate();
        adminLog('cache', 'clear', "清空缓存：$count 个文件");
        echo json_encode(['code' => 0, 'msg' => "已清空 $count 个缓存文件"]);
        exit;
    }

    echo json_encode(['code' => 1, 'msg' => 'unknown action']);
    exit;
}

// 当前值
$enabled = config('html_cache_enabled', '0') === '1';
$ttl = (int)config('html_cache_ttl', 600);

// TTL 预设（秒 => 标签）
$ttlPresets = [
    60    => '1 分钟（调试）',
    300   => '5 分钟（新闻站推荐）',
    600   => '10 分钟（内容站推荐）',
    1800  => '30 分钟（内容站长缓存）',
    3600  => '1 小时（企业站推荐）',
    7200  => '2 小时（企业站长缓存）',
    21600 => '6 小时',
    43200 => '12 小时',
    86400 => '24 小时（极静态站）',
];

// 友好显示当前 TTL
$ttlDisplay = $ttl < 60 ? $ttl . ' 秒'
    : ($ttl < 3600 ? round($ttl/60, 1) . ' 分钟'
    : ($ttl < 86400 ? round($ttl/3600, 1) . ' 小时'
    : round($ttl/86400, 1) . ' 天'));

// 缓存目录统计
$cacheDir = HtmlCache::dir();
$fileCount = 0;
$totalSize = 0;
if (is_dir($cacheDir)) {
    foreach (glob($cacheDir . '/*.html') ?: [] as $f) {
        $fileCount++;
        $totalSize += @filesize($f);
    }
}

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<div class="p-6 max-w-4xl mx-auto">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-gray-800">HTML 缓存设置</h1>
        <p class="text-sm text-gray-500 mt-1">命中缓存的页面跳过 PHP 业务逻辑，直接 readfile 输出，QPS 容量翻数倍。</p>
    </div>

    <!-- 状态卡片 -->
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">状态</div>
            <div class="text-2xl font-bold <?php echo $enabled ? 'text-green-600' : 'text-gray-400'; ?>">
                <?php echo $enabled ? '已启用' : '未启用'; ?>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">缓存文件</div>
            <div class="text-2xl font-bold text-gray-800"><?php echo number_format($fileCount); ?></div>
            <div class="text-xs text-gray-400 mt-0.5">共 <?php echo formatFileSize($totalSize); ?></div>
        </div>
        <div class="bg-white rounded-lg shadow p-4">
            <div class="text-xs text-gray-500 uppercase tracking-wide mb-1">缓存时长</div>
            <div class="text-2xl font-bold text-gray-800"><?php echo htmlspecialchars($ttlDisplay); ?></div>
            <div class="text-xs text-gray-400 mt-0.5"><?php echo $ttl; ?> 秒</div>
        </div>
    </div>

    <!-- 配置表单 -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <form id="cacheForm" onsubmit="event.preventDefault(); saveCache();">
            <div class="space-y-5">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="html_cache_enabled" value="1" <?php echo $enabled ? 'checked' : ''; ?> class="mt-1 w-5 h-5">
                    <div>
                        <div class="font-medium text-gray-800">启用 HTML 缓存</div>
                        <div class="text-sm text-gray-500 mt-0.5">前台已注册的 GET 页面（首页 / 文章 / 列表 / 详情 / 单页）会被缓存为 HTML 文件。已登录的管理员/会员不走缓存。</div>
                    </div>
                </label>

                <div>
                    <label class="block font-medium text-gray-800 mb-1">缓存时长</label>
                    <div class="flex items-center gap-2 flex-wrap">
                        <select id="ttl_preset" class="w-72 border rounded px-3 py-2" onchange="onTtlPresetChange()">
                            <?php foreach ($ttlPresets as $sec => $label): ?>
                                <option value="<?php echo $sec; ?>" <?php echo $ttl === $sec ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                            <option value="custom" <?php echo !isset($ttlPresets[$ttl]) ? 'selected' : ''; ?>>自定义…</option>
                        </select>
                        <input type="number" id="ttl_custom" value="<?php echo $ttl; ?>" min="60" max="86400" step="60" class="w-28 border rounded px-3 py-2 <?php echo isset($ttlPresets[$ttl]) ? 'hidden' : ''; ?>" placeholder="秒">
                        <span id="ttl_custom_unit" class="text-sm text-gray-500 <?php echo isset($ttlPresets[$ttl]) ? 'hidden' : ''; ?>">秒（60 ~ 86400）</span>
                    </div>
                    <div class="text-xs text-gray-500 mt-1">值越大命中率越高，但内容更新越慢生效。修改后建议立即清空一次缓存。</div>
                </div>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="submit" class="bg-primary hover:opacity-90 text-white px-6 py-2 rounded">保存设置</button>
                <button type="button" onclick="clearCache()" class="border border-gray-300 hover:bg-gray-50 px-6 py-2 rounded text-red-600">立即清空缓存</button>
            </div>
        </form>
    </div>

</div>

<script>
function onTtlPresetChange() {
    const sel = document.getElementById('ttl_preset');
    const isCustom = sel.value === 'custom';
    document.getElementById('ttl_custom').classList.toggle('hidden', !isCustom);
    document.getElementById('ttl_custom_unit').classList.toggle('hidden', !isCustom);
}

async function saveCache() {
    const fd = new FormData(document.getElementById('cacheForm'));
    fd.append('action', 'save');
    const sel = document.getElementById('ttl_preset');
    const ttl = sel.value === 'custom' ? document.getElementById('ttl_custom').value : sel.value;
    fd.set('html_cache_ttl', ttl);
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await r.json();
    showMessage(d.msg || (d.code === 0 ? '已保存' : '失败'), d.code === 0 ? 'success' : 'error');
    if (d.code === 0) setTimeout(() => location.reload(), 600);
}

async function clearCache() {
    if (!confirm('确认清空全部 HTML 缓存？')) return;
    const fd = new FormData();
    fd.append('action', 'clear');
    const r = await fetch('', { method: 'POST', body: fd });
    const d = await r.json();
    showMessage(d.msg, d.code === 0 ? 'success' : 'error');
    if (d.code === 0) setTimeout(() => location.reload(), 600);
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
