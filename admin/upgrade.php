<?php
/**
 * Yikai CMS - 升级检测（后台页面）
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

// 升级项定义：每项包含 id、描述、检测方法、执行SQL
$upgrades = [

    [
        'id'    => '20260220_banner_groups',
        'title' => '轮播图分组管理',
        'desc'  => '新增轮播图分组表(yikai_banner_groups)，支持动态管理轮播图分组并通过短码 [banner-slug] 在任意页面嵌入轮播图。',
        'check' => function () {
            return db()->tableExists('banner_groups');
        },
        'sqls' => [
            "CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "banner_groups` (
                `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` varchar(50) NOT NULL COMMENT '分组名称',
                `slug` varchar(50) NOT NULL COMMENT '短码标识',
                `height_pc` smallint(5) UNSIGNED NOT NULL DEFAULT 500 COMMENT 'PC端高度',
                `height_mobile` smallint(5) UNSIGNED NOT NULL DEFAULT 250 COMMENT '移动端高度',
                `autoplay_delay` int(11) UNSIGNED NOT NULL DEFAULT 5000 COMMENT '自动播放间隔ms',
                `sort_order` int(11) NOT NULL DEFAULT 0 COMMENT '排序',
                `status` tinyint(1) NOT NULL DEFAULT 1 COMMENT '状态',
                `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uk_slug` (`slug`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='轮播图分组'",
            "INSERT IGNORE INTO `" . DB_PREFIX . "banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES ('首页', 'home', 650, 300, 5000, 0, 1, UNIX_TIMESTAMP())",
            "INSERT IGNORE INTO `" . DB_PREFIX . "banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES ('关于我们', 'about', 500, 250, 5000, 1, 1, UNIX_TIMESTAMP())",
            "INSERT IGNORE INTO `" . DB_PREFIX . "banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES ('产品中心', 'product', 500, 250, 5000, 2, 1, UNIX_TIMESTAMP())",
            "INSERT IGNORE INTO `" . DB_PREFIX . "banner_groups` (`name`, `slug`, `height_pc`, `height_mobile`, `autoplay_delay`, `sort_order`, `status`, `created_at`) VALUES ('案例展示', 'case', 500, 250, 5000, 3, 1, UNIX_TIMESTAMP())",
        ],
    ],

    // --- 未来升级项追加到这里 ---

];

// AJAX: 在线升级检测（服务端代理，避免 CORS）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_update') {
    header('Content-Type: application/json; charset=utf-8');
    $currentVersion = defined('CMS_VERSION') ? CMS_VERSION : '1.0.0';
    $updateServerUrl = 'https://update.yikaicms.com';
    $apiUrl = $updateServerUrl . '/api/update/check.php?version=' . urlencode($currentVersion);

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
    $runIds = (array)$_POST['run'];
    $results = [];
    foreach ($upgrades as $up) {
        if (!in_array($up['id'], $runIds)) continue;
        if ($up['check']()) {
            $results[$up['id']] = ['status' => 'skipped', 'message' => '已是最新，无需升级'];
            continue;
        }
        try {
            foreach ($up['sqls'] as $sql) {
                db()->execute($sql);
            }
            $results[$up['id']] = ['status' => 'success', 'message' => '升级成功'];
            adminLog('upgrade', 'execute', '执行升级: ' . $up['title']);
        } catch (\Throwable $e) {
            $results[$up['id']] = ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    header('Content-Type: application/json');
    echo json_encode(['code' => 0, 'data' => $results]);
    exit;
}

// 检测各项状态
foreach ($upgrades as &$up) {
    $up['status'] = $up['check']() ? 'done' : 'pending';
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
$pageTitle = '升级管理';
$currentMenu = $tab === 'online' ? 'online_upgrade' : 'upgrade';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<!-- Tab 导航 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="flex border-b">
        <a href="/admin/upgrade.php" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'check' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
            升级检测
            <?php if (!empty($pendingUpgrades)): ?>
            <span class="ml-1.5 inline-block w-5 h-5 leading-5 text-center rounded-full bg-red-500 text-white text-xs"><?php echo count($pendingUpgrades); ?></span>
            <?php endif; ?>
        </a>
        <a href="/admin/upgrade.php?tab=history" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'history' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">升级历史</a>
        <a href="/admin/upgrade.php?tab=online" class="px-6 py-3 text-sm font-medium border-b-2 <?php echo $tab === 'online' ? 'border-primary text-primary' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">在线升级</a>
    </div>
</div>

<?php if ($tab === 'check'): ?>
<div class="max-w-3xl">
    <?php if (empty($pendingUpgrades)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <svg class="w-16 h-16 mx-auto text-green-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <p class="text-green-600 font-medium text-lg mb-2">数据库已是最新版本</p>
        <p class="text-gray-400 text-sm">所有升级项均已完成，无需操作。</p>
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
            <span class="font-semibold flex-1"><?php echo htmlspecialchars($up['title']); ?></span>
            <span class="text-xs text-gray-400 font-mono"><?php echo $up['id']; ?></span>
            <span class="upgrade-badge inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700">待升级</span>
        </div>
        <div class="px-5 py-3 text-sm text-gray-500">
            <?php echo htmlspecialchars($up['desc']); ?>
            <div class="upgrade-msg mt-2 hidden"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="mt-6">
        <button id="btnUpgrade" onclick="runUpgrade()" class="bg-primary hover:bg-secondary text-white px-8 py-2.5 rounded transition inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
            执行升级
        </button>
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
    btn.textContent = '升级中...';

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
                        badge.textContent = '升级成功';
                    } else if (item.status === 'error') {
                        badge.className = 'inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700';
                        badge.textContent = '升级失败';
                        allSuccess = false;
                    } else {
                        badge.className = 'inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500';
                        badge.textContent = '已跳过';
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
            showMessage(data.msg || '升级失败', 'error');
        }
    } catch (err) {
        showMessage('请求失败', 'error');
    }

    btn.disabled = false;
    btn.textContent = '执行升级';
}
</script>
<?php endif; ?>

<?php if ($tab === 'history'): ?>
<div class="max-w-3xl">
    <?php if (empty($doneUpgrades)): ?>
    <div class="bg-white rounded-lg shadow p-12 text-center">
        <p class="text-gray-400">暂无升级历史记录。</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
    <?php foreach (array_reverse($doneUpgrades) as $up): ?>
    <div class="bg-white rounded-lg shadow">
        <div class="px-5 py-4 border-b flex items-center gap-3">
            <svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span class="font-semibold flex-1"><?php echo htmlspecialchars($up['title']); ?></span>
            <span class="text-xs text-gray-400 font-mono"><?php echo $up['id']; ?></span>
            <span class="inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">已完成</span>
        </div>
        <div class="px-5 py-3 text-sm text-gray-500">
            <?php echo htmlspecialchars($up['desc']); ?>
        </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'online'): ?>
<?php
// 在线升级配置
$updateServerUrl = 'https://update.yikaicms.com';
$updateCheckApi  = $updateServerUrl . '/api/update/check';
$currentVersion  = defined('CMS_VERSION') ? CMS_VERSION : '1.0.0';
?>
<div class="max-w-2xl">
    <!-- 当前版本信息 -->
    <div class="bg-white rounded-lg shadow mb-6">
        <div class="px-6 py-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800">在线升级</h2>
            <span class="text-sm text-gray-400">更新服务器：<?php echo e($updateServerUrl); ?></span>
        </div>
        <div class="px-6 py-5">
            <div class="flex items-center gap-4 mb-4">
                <div class="flex-shrink-0 w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                    <svg class="w-6 h-6 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                    </svg>
                </div>
                <div>
                    <p class="text-gray-800 font-medium">Yikai CMS</p>
                    <p class="text-sm text-gray-500">当前版本：<span class="font-mono font-medium text-primary">v<?php echo e($currentVersion); ?></span></p>
                </div>
            </div>

            <div id="updateResult" class="hidden"></div>

            <button id="btnCheckUpdate" onclick="checkUpdate()" class="bg-primary hover:bg-secondary text-white px-6 py-2.5 rounded transition inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
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
            <p>4. 升级完成后，建议访问 <a href="/admin/upgrade.php" class="text-primary hover:underline">升级检测</a> 页面执行数据库升级。</p>
        </div>
    </div>
</div>

<script>
var currentVersion = <?php echo json_encode($currentVersion); ?>;

async function checkUpdate() {
    var btn = document.getElementById('btnCheckUpdate');
    var result = document.getElementById('updateResult');
    btn.disabled = true;
    btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> 检测中...';

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
                + '<svg class="w-5 h-5 text-blue-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                + '<div class="flex-1">'
                + '<p class="font-medium text-blue-800 mb-1">发现新版本 <span class="font-mono">v' + escapeHtml(d.latest_version) + '</span></p>'
                + (d.release_date ? '<p class="text-sm text-blue-600 mb-2">发布日期：' + escapeHtml(d.release_date) + '</p>' : '')
                + (d.changelog ? '<div class="text-sm text-blue-700 mb-3 whitespace-pre-line">' + escapeHtml(d.changelog) + '</div>' : '')
                + (d.download_url ? '<a href="' + escapeHtml(d.download_url) + '" target="_blank" class="inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm transition"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg> 下载更新包</a>' : '')
                + '</div></div></div>';
        } else if (data.code === 0) {
            result.innerHTML = '<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4 flex items-center gap-3">'
                + '<svg class="w-5 h-5 text-green-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                + '<p class="text-green-700">当前已是最新版本 <span class="font-mono font-medium">v' + escapeHtml(currentVersion) + '</span></p>'
                + '</div>';
        } else {
            throw new Error(data.msg || '检测失败');
        }
    } catch (err) {
        result.classList.remove('hidden');
        result.innerHTML = '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4 flex items-center gap-3">'
            + '<svg class="w-5 h-5 text-red-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
            + '<div><p class="text-red-700 font-medium">检测失败</p><p class="text-red-600 text-sm mt-0.5">' + escapeHtml(err.message) + '</p></div>'
            + '</div>';
    }

    btn.disabled = false;
    btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg> 重新检测';
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
