<?php
/**
 * YikaiCMS - 升级检测（后台页面）
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// _columnExists / _sqlToSqlite：迁移 check/执行常用的兼容工具，与 includes/Migrator.php
// 中同名函数完全一致。用 function_exists 守卫，无论本页与 Migrator.php 谁先加载都不重复定义。
if (!function_exists('_columnExists')) {
    function _columnExists(string $table, string $column): bool
    {
        $tableName = DB_PREFIX . $table;
        if (db()->isSqlite()) {
            $cols = db()->fetchAll("PRAGMA table_info('{$tableName}')");
            foreach ($cols as $col) {
                if ($col['name'] === $column) return true;
            }
            return false;
        }
        $cols = db()->fetchAll("SHOW COLUMNS FROM `{$tableName}` LIKE '{$column}'");
        return !empty($cols);
    }
}

// _sqlToSqlite 由 includes/Migrator.php 提供（下方 require）；此处不再重复定义。

// 迁移集合的唯一来源：Migrator::loadAll() 合并 migrations/_inline_upgrades.php（遗留内联包）
// 与 migrations/*.php（独立文件，同 id 覆盖）。后台「数据库升级」与 CLI migrate:run 共用它，
// 从根上消除 CLI/Web 迁移不一致（见 yikaicms-docs/next-phase-hardening-plan.md P1）。
require_once ROOT_PATH . '/includes/Migrator.php';
$upgrades = Migrator::loadAll();

// AJAX: 控制台新版本提醒开关（与 admin/index.php 的关闭按钮配对）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_update_bar') {
    settingModel()->set('dashboard_update_check', post('value') === '1' ? '1' : '0', 'system');
    adminLog('setting', 'update', '控制台新版本提醒：' . (post('value') === '1' ? '开启' : '关闭'));
    success();
}

// AJAX: 在线升级检测（服务端代理，避免 CORS）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_update') {
    header('Content-Type: application/json; charset=utf-8');
    $currentVersion = defined('CMS_VERSION') ? CMS_VERSION : '1.0.0';
    $updateServerUrl = 'https://update.yikaicms.com';
    // 带上本站域名/站名/PHP：检查更新时顺便在 update 服务器登记安装（按域名）
    $apiUrl = $updateServerUrl . '/api/update/check.php?version=' . urlencode($currentVersion)
        . '&domain=' . urlencode($_SERVER['HTTP_HOST'] ?? '')
        . '&site_name=' . urlencode((string) config('site_name', ''))
        . '&php=' . urlencode(PHP_VERSION);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($apiUrl, false, $context);

    if ($response === false) {
        // 尝试 curl 作为备选
        if (function_exists('curl_init')) {
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($response === false || $httpCode >= 400) {
                echo json_encode(['code' => 1, 'msg' => '无法连接更新服务器' . ($error ? ': ' . $error : ' (HTTP ' . $httpCode . ')')], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            echo json_encode(['code' => 1, 'msg' => '无法连接更新服务器，请检查网络或服务器 PHP 配置'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $data = json_decode($response, true);
    if ($data === null) {
        echo json_encode(['code' => 1, 'msg' => '更新服务器返回数据格式错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo $response;
    exit;
}

// AJAX 执行升级
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['run'])) {
    // 抑制响应被任何 warning/notice 污染 (会破坏 JSON 解析)
    ob_start();

    $runIds = (array)$_POST['run'];
    $results = [];
    foreach ($upgrades as $up) {
        if (!in_array($up['id'], $runIds)) continue;
        try {
            // check() 也可能抛错(连不上 DB / 表不存在 / 权限等),包进 try/catch
            $alreadyDone = (bool)$up['check']();
        } catch (\Throwable $e) {
            $results[$up['id']] = ['status' => 'error', 'message' => 'check failed: ' . $e->getMessage()];
            continue;
        }
        if ($alreadyDone) {
            $results[$up['id']] = ['status' => 'skipped', 'message' => '已是最新，无需升级'];
            continue;
        }
        try {
            // 先跑 sqls（若有），再跑 php 回调（若有）—— 与 Migrator::runOne 一致。
            // 修复：旧逻辑是 if(php){只跑php} else {跑sqls}，导致「既有 sqls 又有 php」的迁移
            //       只执行 php、漏掉建表/加列 → php 回调里 SELECT 新列即 "Unknown column"。
            foreach (($up['sqls'] ?? []) as $sql) {
                if (db()->isSqlite()) {
                    $sql = _sqlToSqlite($sql);
                    if ($sql === null) continue;
                }
                try {
                    db()->execute($sql);
                } catch (\Throwable $e) {
                    // 已存在的列/索引/表等幂等失败 → 忽略（与 Migrator::runOne 一致）：
                    // 避免半程重跑或 opcache 导致的 "Duplicate column/already exists" 把升级误报为失败。
                    if (preg_match('/Duplicate column|already exists|duplicate entry|duplicate key/i', $e->getMessage())) {
                        continue;
                    }
                    throw $e;
                }
            }
            $msg = '';
            if (!empty($up['php']) && is_callable($up['php'])) {
                $msg = ($up['php'])();
            }
            $results[$up['id']] = ['status' => 'success', 'message' => $msg ?: __('upgrade_success')];
            // adminLog 失败不影响升级响应
            try { adminLog('upgrade', 'execute', '执行升级: ' . ($up['title'] ?? $up['id'])); } catch (\Throwable $e) {}
        } catch (\Throwable $e) {
            $results[$up['id']] = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    // 升级完成后，自动更新数据库中的版本号
    $currentVersion = defined('CMS_VERSION') ? CMS_VERSION : '1.3.0';
    try {
        $exists = (int)db()->fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE `key` = 'cms_version'");
        if ($exists) {
            db()->execute("UPDATE " . DB_PREFIX . "settings SET `value` = ? WHERE `key` = 'cms_version'", [$currentVersion]);
        }
    } catch (\Throwable $e) {}

    // 丢弃任何意外输出 (warnings/notices/BOM/echo 等)
    ob_end_clean();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => 0, 'data' => $results], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检测各项状态（check() 抛错时按 pending 处理，避免单条迁移让整页 500）
foreach ($upgrades as &$up) {
    try {
        $up['status'] = $up['check']() ? 'done' : 'pending';
    } catch (\Throwable $e) {
        // 常见原因：迁移 A 依赖迁移 B 已加的列，check 中直接 SELECT 那列；A 先 check 时列不存在
        $up['status'] = 'pending';
        $up['_check_error'] = $e->getMessage();
    }
}
unset($up);

// 分离：待升级 / 已完成
$pendingUpgrades = [];
$doneUpgrades = [];
foreach ($upgrades as $up) {
    if ($up['status'] === 'pending') {
        $pendingUpgrades[] = $up;
    } else {
        $doneUpgrades[] = $up;
    }
}

$tab = $_GET['tab'] ?? 'check';
if ($tab === 'history') $tab = 'check';   // 升级历史已并入「数据库升级」标签
$pageTitle = '升级管理';
$currentMenu = $tab === 'online' ? 'online_upgrade' : 'upgrade';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/upgrade.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'check' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            数据库升级
            <?php if (!empty($pendingUpgrades)): ?>
            <span class="ml-1.5 inline-block w-5 h-5 leading-5 text-center rounded-full bg-red-500 text-white text-xs"><?php echo count($pendingUpgrades); ?></span>
            <?php endif; ?>
        </a>
        <a href="/admin/upgrade_online.php" class="px-6 py-3 text-sm font-medium border-b-2 border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300"><?php echo __('upgrade_online'); ?></a>
    </div>
</div>

<?php if ($tab === 'check'): ?>
<div>
    <?php if (empty($pendingUpgrades)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <i class="ti ti-circle-check text-base mx-auto text-green-300 mb-4"></i>
        <p class="text-green-600 font-medium text-lg mb-2"><?php echo __('upgrade_up_to_date'); ?></p>
        <p class="text-gray-400 text-sm"><?php echo __('upgrade_all_done'); ?></p>
    </div>
    <?php else: ?>
    <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-sm text-yellow-800 mb-6">
        检测到 <?php echo count($pendingUpgrades); ?> 项待升级，升级前请确保已备份数据库。
    </div>

    <div id="upgradeList" class="space-y-4">
    <?php foreach ($pendingUpgrades as $up): ?>
    <div class="bg-white rounded-lg shadow" data-id="<?php echo $up['id']; ?>">
        <div class="px-5 py-4 border-b flex items-center gap-3">
            <input type="checkbox" class="upgrade-check w-4 h-4" value="<?php echo $up['id']; ?>" checked>
            <span class="font-semibold flex-1"><?php echo htmlspecialchars((string) ($up['title'] ?? $up['name'] ?? $up['id'])); ?></span>
            <span class="text-xs text-gray-400 font-mono"><?php echo $up['id']; ?></span>
            <span class="upgrade-badge inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">待升级</span>
        </div>
        <div class="px-5 py-3 text-sm text-gray-500">
            <?php echo htmlspecialchars((string) ($up['desc'] ?? '')); ?>
            <div class="upgrade-msg mt-2 hidden"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="mt-6">
        <button id="btnUpgrade" onclick="runUpgrade()" class="bg-primary hover:bg-secondary text-white px-8 py-2.5 rounded transition inline-flex items-center gap-2">
            <i class="ti ti-refresh text-base"></i>
            执行升级
        </button>
    </div>
    <?php endif; ?>

    <?php if (!empty($doneUpgrades)): ?>
    <div class="mt-8">
        <h3 class="text-sm font-semibold text-gray-500 mb-3">已完成（<?php echo count($doneUpgrades); ?>）</h3>
        <div class="space-y-3">
        <?php foreach (array_reverse($doneUpgrades) as $up): ?>
        <div class="bg-white rounded-lg shadow">
            <div class="px-5 py-3.5 flex items-center gap-3">
                <i class="ti ti-circle-check text-lg text-green-500 flex-shrink-0"></i>
                <span class="font-medium flex-1 text-sm"><?php echo htmlspecialchars((string) ($up['title'] ?? $up['name'] ?? $up['id'])); ?></span>
                <span class="text-xs text-gray-400 font-mono"><?php echo $up['id']; ?></span>
                <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700"><?php echo __('upgrade_completed'); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
async function runUpgrade() {
    var checks = document.querySelectorAll('.upgrade-check:checked');
    if (!checks.length) { showMessage('请选择要升级的项目', 'error'); return; }

    var ids = [];
    checks.forEach(function(c) { ids.push(c.value); });

    var btn = document.getElementById('btnUpgrade');
    btn.disabled = true;
    btn.textContent = '<?php echo __('upgrade_running'); ?>';

    var formData = new FormData();
    ids.forEach(function(id) { formData.append('run[]', id); });

    try {
        var response = await fetch('', { method: 'POST', body: formData });
        var data = await safeJson(response);

        if (data.code === 0) {
            var allSuccess = true;
            for (var id in data.data) {
                var item = data.data[id];
                var card = document.querySelector('[data-id="' + id + '"]');
                if (!card) continue;

                var badge = card.querySelector('.upgrade-badge');
                var msg = card.querySelector('.upgrade-msg');
                var check = card.querySelector('.upgrade-check');

                if (check) check.remove();

                if (badge) {
                    if (item.status === 'success') {
                        badge.className = 'inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700';
                        badge.textContent = '<?php echo __('upgrade_success'); ?>';
                    } else if (item.status === 'error') {
                        badge.className = 'inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700';
                        badge.textContent = '<?php echo __('upgrade_failed'); ?>';
                        allSuccess = false;
                    } else {
                        badge.className = 'inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500';
                        badge.textContent = '<?php echo __('upgrade_skipped'); ?>';
                    }
                }

                if (msg && item.status === 'error') {
                    msg.className = 'upgrade-msg mt-2 text-red-600 text-xs';
                    msg.textContent = item.message;
                } else if (msg && item.status === 'success') {
                    msg.className = 'upgrade-msg mt-2 text-green-600 text-xs';
                    msg.textContent = item.message;
                }
            }
            showMessage('升级完成');
            if (allSuccess) {
                setTimeout(function() { location.reload(); }, 1500);
            }
        } else {
            showMessage(data.msg || '<?php echo __('upgrade_failed'); ?>', 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }

    btn.disabled = false;
    btn.textContent = '<?php echo __('upgrade_execute'); ?>';
}
</script>
<?php endif; ?>

<?php if ($tab === 'online'): ?>
<?php
// 在线升级配置
$updateServerUrl = 'https://update.yikaicms.com';
$updateCheckApi  = $updateServerUrl . '/api/update/check';
$currentVersion  = defined('CMS_VERSION') ? CMS_VERSION : '1.0.0';
?>
<div>
    <!-- 当前版本信息 -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800"><?php echo __('upgrade_online'); ?></h2>
            <span class="text-sm text-gray-400">更新服务器：<?php echo e($updateServerUrl); ?></span>
        </div>
        <div class="px-6 py-5">
            <div class="flex items-center gap-4 mb-4">
                <div class="flex-shrink-0 w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                    <i class="ti ti-bolt text-xl text-primary"></i>
                </div>
                <div>
                    <?php // 白标：有效授权不露 CMS 品牌名（同 footer / 系统信息页惯例） ?>
                    <p class="text-gray-800 font-medium"><?php echo (function_exists('license_valid') && license_valid()) ? e(config('site_name', 'YikaiCMS')) : 'YikaiCMS'; ?></p>
                    <p class="text-sm text-gray-500">当前版本：<span class="font-mono font-medium text-primary">v<?php echo e($currentVersion); ?></span></p>
                </div>
            </div>

            <div id="updateResult" class="hidden"></div>

            <label class="flex items-center gap-2 text-sm text-gray-500 mb-4 cursor-pointer select-none">
                <input type="checkbox" id="uoBarToggle" <?php echo config('dashboard_update_check', '1') === '1' ? 'checked' : ''; ?>
                       onchange="toggleUoBar(this)" class="w-4 h-4 accent-blue-500">
                在控制台首页显示新版本提醒
            </label>
            <button id="btnCheckUpdate" onclick="checkUpdate()" class="bg-primary hover:bg-secondary text-white px-6 py-2.5 rounded transition inline-flex items-center gap-2">
                <i class="ti ti-refresh text-base"></i>
                检测更新
            </button>
        </div>
    </div>

    <!-- 升级说明 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h3 class="font-medium text-gray-700">升级说明</h3>
        </div>
        <div class="px-6 py-4 text-sm text-gray-500 space-y-2">
            <p>1. 升级前请务必<strong class="text-gray-700">备份数据库和网站文件</strong>。</p>
            <p>2. 系统会自动从 <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs"><?php echo e($updateServerUrl); ?></code> 检测是否有新版本。</p>
            <p>3. 检测到新版本后，请按照提示下载更新包并按步骤完成升级。</p>
            <p>4. 升级完成后，建议访问 <a href="/admin/upgrade.php" class="text-primary hover:underline"><?php echo __('upgrade_check'); ?></a> 页面执行数据库升级。</p>
        </div>
    </div>
</div>

<script>
var currentVersion = <?php echo json_encode($currentVersion); ?>;

async function checkUpdate() {
    async function toggleUoBar(cb) {
        var fd = new FormData();
        fd.append('action', 'toggle_update_bar');
        fd.append('value', cb.checked ? '1' : '0');
        try {
            await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            showMessage(cb.checked ? '已开启控制台提醒' : '已关闭控制台提醒');
        } catch (e) { showMessage('保存失败', 'error'); cb.checked = !cb.checked; }
    }
    var btn = document.getElementById('btnCheckUpdate');
    var result = document.getElementById('updateResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader-2 text-base animate-spin"></i> 检测中...';

    try {
        var formData = new FormData();
        formData.append('action', 'check_update');
        var response = await fetch('', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!response.ok) {
            throw new Error('服务器响应异常 (HTTP ' + response.status + ')');
        }

        var data = await safeJson(response);
        result.classList.remove('hidden');

        if (data.code === 0 && data.data && data.data.has_update) {
            var d = data.data;
            result.innerHTML = '<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">'
                + '<div class="flex items-start gap-3">'
                + '<i class="ti ti-info-circle text-lg text-blue-500 mt-0.5 flex-shrink-0"></i>'
                + '<div class="flex-1">'
                + '<p class="font-medium text-blue-800 mb-1">发现新版本 <span class="font-mono">v' + escapeHtml(d.latest_version) + '</span></p>'
                + (d.release_date ? '<p class="text-sm text-blue-600 mb-2">发布日期：' + escapeHtml(d.release_date) + '</p>' : '')
                + (d.changelog ? '<div class="text-sm text-blue-700 mb-3 whitespace-pre-line">' + escapeHtml(d.changelog) + '</div>' : '')
                + (d.download_url ? '<a href="' + escapeHtml(d.download_url) + '" target="_blank" class="inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm transition"><i class="ti ti-download text-base"></i> 下载更新包</a>' : '')
                + '</div></div></div>';
        } else if (data.code === 0) {
            result.innerHTML = '<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4 flex items-center gap-3">'
                + '<i class="ti ti-circle-check text-lg text-green-500 flex-shrink-0"></i>'
                + '<p class="text-green-700">当前已是最新版本 <span class="font-mono font-medium">v' + escapeHtml(currentVersion) + '</span></p>'
                + '</div>';
        } else {
            throw new Error(data.msg || '检测失败');
        }
    } catch (err) {
        result.classList.remove('hidden');
        result.innerHTML = '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4 flex items-center gap-3">'
            + '<i class="ti ti-alert-circle text-lg text-red-500 flex-shrink-0"></i>'
            + '<div><p class="text-red-700 font-medium">检测失败</p><p class="text-red-600 text-sm mt-0.5">' + escapeHtml(err.message) + '</p></div>'
            + '</div>';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="ti ti-refresh text-base"></i> 重新检测';
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
