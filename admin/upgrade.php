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
require_once ROOT_PATH . '/includes/UpdateChannel.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// /admin/upgrade.php 是历史入口；无标签访问时统一进入程序在线升级。
// 数据库迁移仍通过显式 tab=check 进入，避免在线升级完成后的迁移步骤被再次导回在线页。
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !array_key_exists('tab', $_GET)) {
    header('Location: /admin/upgrade_online.php', true, 302);
    exit;
}

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
        // 用 information_schema 精确匹配，不走 LIKE。
        //
        // 原写法是 SHOW COLUMNS ... LIKE '{$column}'，列名里的 `_` 会被当通配符：
        // 'deleted_at' 连 'deletedXat' 一起匹配，_columnExists() 就可能对**不存在的列**
        // 返回 true。在迁移里这意味着「误判已应用 → 跳过 → 列没建 → 上线 500」。
        // 而 SHOW 语句又不接受占位符（SHOW COLUMNS ... LIKE ? 在 MySQL 上直接 1064），
        // 想转义就只能拼字符串。information_schema 支持参数化且是精确比较，两个问题一起没了。
        $row = db()->fetchOne(
            'SELECT 1 AS ok FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tableName, $column]
        );
        return $row !== null;
    }
}

// _sqlToSqlite 由 includes/Migrator.php 提供（下方 require）；此处不再重复定义。

// 迁移集合的唯一来源：Migrator::loadAll() 合并 migrations/_inline_upgrades.php（遗留内联包）
// 与 migrations/*.php（独立文件，同 id 覆盖）。后台「数据库升级」与 CLI migrate:run 共用它，
// 从根上消除 CLI/Web 迁移不一致（见 yikaicms-docs/next-phase-hardening-plan.md P1）。
require_once ROOT_PATH . '/includes/Migrator.php';
$upgrades = Migrator::loadAll();

// AJAX: 控制台新版本提醒级别（all=全部 / security=仅安全更新 / off=关闭）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_update_notify') {
    verifyCsrf();
    $lv = post('level');
    if (!in_array($lv, ['all', 'security', 'off'], true)) {
        $lv = 'all';
    }
    settingModel()->set('update_notify_level', $lv, 'system');
    // 兼容旧键：控制台首页与既有代码仍读它做粗粒度判断
    settingModel()->set('dashboard_update_check', $lv === 'off' ? '0' : '1', 'system');
    adminLog('setting', 'update', str_replace(':level', $lv, __('upg_log_notify_level')));
    success();
}

// AJAX: 自动升级（开关 / 范围 / 维护窗口）。真正的执行逻辑在 includes/AutoUpgrade.php，
// 与后台「在线升级」共用同一条管道，这里只管配置。
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_auto_upgrade') {
    verifyCsrf();
    $win = trim((string) post('window'));
    // 窗口格式不合规就回落默认：配置写坏不能变成「随时升」也不能变成「永不升」
    if (preg_match('/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/', $win) !== 1) {
        $win = '03:00-05:00';
    }
    settingModel()->saveBatch([
        'auto_upgrade_enabled' => post('enabled') === '1' ? '1' : '0',
        'auto_upgrade_scope'   => post('scope') === 'stable' ? 'stable' : 'security',
        'auto_upgrade_window'  => $win,
    ]);
    adminLog('setting', 'update', 'auto_upgrade: ' . (post('enabled') === '1' ? 'on' : 'off'));
    success(['window' => $win]);
}

// AJAX: 立即检查并升级（手动触发同一条无人值守管道，用于验证配置是否可用）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_auto_upgrade') {
    verifyCsrf();
    requirePermission('*');
    @set_time_limit(0);
    require_once ROOT_PATH . '/includes/AutoUpgrade.php';
    success(['result' => AutoUpgrade::run(true)]);
}

// AJAX: 更新通道（stable=正式版 / beta=测试版，默认 stable）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_update_channel') {
    verifyCsrf();
    $channel = UpdateChannel::normalize(post('channel'));
    settingModel()->set('update_channel', $channel, 'system');
    adminLog('setting', 'update', __('upg_log_update_channel', ['channel' => $channel]));
    success(['channel' => $channel]);
}

// AJAX: 在线升级检测（服务端代理，避免 CORS）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_update') {
    header('Content-Type: application/json; charset=utf-8');
    $currentVersion = defined('CMS_VERSION') ? CMS_VERSION : '1.0.0';
    $updateServerUrl = 'https://update.yikaicms.com';
    // 带上本站域名/站名/PHP：检查更新时顺便在 update 服务器登记安装（按域名）
    $apiUrl = $updateServerUrl . '/api/update/check.php?version=' . urlencode($currentVersion)
        . '&channel=' . urlencode(UpdateChannel::current())
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
                echo json_encode(['code' => 1, 'msg' => __('upg_server_unreachable') . ($error ? ': ' . $error : ' (HTTP ' . $httpCode . ')')], JSON_UNESCAPED_UNICODE);
                exit;
            }
        } else {
            echo json_encode(['code' => 1, 'msg' => __('upg_server_unreachable_2')], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $data = json_decode($response, true);
    if ($data === null) {
        echo json_encode(['code' => 1, 'msg' => __('upg_server_bad_json')], JSON_UNESCAPED_UNICODE);
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
            $results[$up['id']] = ['status' => 'skipped', 'message' => __('upg_already_applied')];
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
            try { adminLog('upgrade', 'execute', str_replace(':name', (string) ($up['title'] ?? $up['id']), __('upg_log_execute'))); } catch (\Throwable $e) {}
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

// 显式 tab=check 才进入数据库升级；无 tab 已在上方兼容重定向到在线升级。
$tab = $_GET['tab'] ?? (!empty($pendingUpgrades) ? 'check' : 'config');
// ── 升级记录：操作日志里 module='upgrade' 的两类条目（程序文件覆盖 / 数据库迁移）──
// 升完级最想知道的是「刚才到底动了什么、什么时候动的」，这些 adminLog 早就在记，
// 只是一直没有地方看。另附升级前的 config 备份清单，需要比对时不用翻 FTP。
$upgLogs = [];
$upgBackups = [];
if ($tab === 'welcome') {
    // 升级完成页要展示的三样：升到哪个版本、什么时候升的、这一版更新了什么
    $welcomeTo   = (string) config('last_upgrade_to', '');
    $welcomeFrom = (string) config('last_upgrade_from', '');
    $welcomeAt   = (int) config('last_upgrade_at', 0);
    $welcomeNote = (string) config('last_upgrade_note', '');
}
if ($tab === 'history') {
    try {
        $upgLogs = db()->fetchAll(
            'SELECT admin_name, action, description, ip, created_at FROM ' . DB_PREFIX . 'admin_logs'
            . " WHERE module = 'upgrade' ORDER BY id DESC LIMIT 200"
        );
    } catch (\Throwable $e) {
        $upgLogs = [];
    }
    foreach ((array) glob(ROOT_PATH . '/storage/backups/pre-upgrade-*') as $b) {
        $upgBackups[] = ['name' => basename((string) $b), 'time' => (int) @filemtime((string) $b)];
    }
    usort($upgBackups, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);
}

$pageTitle = __('upgrade_page_title');
$currentMenu = $tab === 'online' ? 'online_upgrade' : 'upgrade';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php // 标签栏由 admin/includes/upgrade_tabs.php 统一渲染（两页共用，避免各自维护漂移）
$__upgTab = in_array($tab, ['config', 'history', 'welcome', 'manual'], true) ? $tab : 'check';
require ROOT_PATH . '/admin/includes/upgrade_tabs.php';
?>

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
        <?php echo e(str_replace(':n', (string) count($pendingUpgrades), __('upg_pending_notice'))); ?>
    </div>

    <div id="upgradeList" class="space-y-4">
    <?php foreach ($pendingUpgrades as $up): ?>
    <div class="bg-white rounded-lg shadow" data-id="<?php echo $up['id']; ?>">
        <div class="px-5 py-4 border-b flex items-center gap-3">
            <input type="checkbox" class="upgrade-check w-4 h-4" value="<?php echo $up['id']; ?>" checked>
            <span class="font-semibold flex-1"><?php echo e(Migrator::label($up) ?: (string) ($up['name'] ?? $up['id'])); ?></span>
            <span class="text-xs text-gray-400 font-mono"><?php echo $up['id']; ?></span>
            <span class="upgrade-badge inline-block px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-700"><?php echo e(__('upg_badge_pending')); ?></span>
        </div>
        <div class="px-5 py-3 text-sm text-gray-500">
            <?php echo e(Migrator::label($up, 'desc')); ?>
            <div class="upgrade-msg mt-2 hidden"></div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <div class="mt-6">
        <button id="btnUpgrade" onclick="runUpgrade()" class="bg-primary hover:bg-secondary text-white px-8 py-2.5 rounded transition inline-flex items-center gap-2">
            <i class="ti ti-refresh text-base"></i>
            <?php echo e(__('upg_run')); ?>
        </button>
    </div>
    <?php endif; ?>

    <?php if (!empty($doneUpgrades)): ?>
    <div class="mt-8">
        <h3 class="text-sm font-semibold text-gray-500 mb-3"><?php echo e(str_replace(':n', (string) count($doneUpgrades), __('upg_done_count'))); ?></h3>
        <div class="space-y-3">
        <?php foreach (array_reverse($doneUpgrades) as $up): ?>
        <div class="bg-white rounded-lg shadow">
            <div class="px-5 py-3.5 flex items-center gap-3">
                <i class="ti ti-circle-check text-lg text-green-500 flex-shrink-0"></i>
                <span class="font-medium flex-1 text-sm"><?php echo e(Migrator::label($up) ?: (string) ($up['name'] ?? $up['id'])); ?></span>
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
    if (!checks.length) { showMessage(<?php echo json_encode(__('upg_pick_items'), JSON_UNESCAPED_UNICODE); ?>, 'error'); return; }

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
            showMessage(<?php echo json_encode(__('upgrade_all_applied'), JSON_UNESCAPED_UNICODE); ?>);
            if (allSuccess) {
                // 落到「升级记录」而不是刷回本页：刷回来只会看到一句「全部已应用」，
                // 而人这会儿想知道的是刚才到底动了什么。
                setTimeout(function() { location.href = 'upgrade.php?tab=welcome'; }, 1500);
            }
        } else {
            showMessage(data.msg || '<?php echo __('upgrade_failed'); ?>', 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
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
            <span class="text-sm text-gray-400"><?php echo e(__('upg_server_label')); ?><?php echo e($updateServerUrl); ?></span>
        </div>
        <div class="px-6 py-5">
            <div class="flex items-center gap-4 mb-4">
                <div class="flex-shrink-0 w-12 h-12 bg-primary/10 rounded-lg flex items-center justify-center">
                    <i class="ti ti-bolt text-xl text-primary"></i>
                </div>
                <div>
                    <?php // 白标：有效授权不露 CMS 品牌名（同 footer / 系统信息页惯例） ?>
                    <p class="text-gray-800 font-medium"><?php echo (function_exists('license_valid') && license_valid()) ? e(config('site_name', 'YikaiCMS')) : 'YikaiCMS'; ?></p>
                    <p class="text-sm text-gray-500"><?php echo e(__('upg_current_version')); ?><span class="font-mono font-medium text-primary">v<?php echo e($currentVersion); ?></span></p>
                </div>
            </div>

            <div id="updateResult" class="hidden"></div>

            <button id="btnCheckUpdate" onclick="checkUpdate()" class="bg-primary hover:bg-secondary text-white px-6 py-2.5 rounded transition inline-flex items-center gap-2">
                <i class="ti ti-refresh text-base"></i>
                <?php echo e(__('upg_check_now')); ?>
            </button>
        </div>
    </div>

    <!-- 升级说明 -->
    <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b">
            <h3 class="font-medium text-gray-700"><?php echo e(__('upg_help_title')); ?></h3>
        </div>
        <div class="px-6 py-4 text-sm text-gray-500 space-y-2">
            <p>1. <strong class="text-gray-700"><?php echo e(__('upg_help_1')); ?></strong></p>
            <p>2. <?php
                // 「从 X 检测新版本」在英日语序不同，整句走 :server 占位，回填时套上 <code> 样式
                echo strtr(e(__('upg_help_2')), [
                    ':server' => '<code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">' . e($updateServerUrl) . '</code>',
                ]);
            ?></p>
            <p>3. <?php echo e(__('upg_help_3')); ?></p>
            <p>4. 升级完成后，建议访问 <a href="/admin/upgrade.php?tab=check" class="text-primary hover:underline"><?php echo __('upgrade_check'); ?></a> 页面执行数据库升级。</p>
        </div>
    </div>
</div>

<script>
var currentVersion = <?php echo json_encode($currentVersion); ?>;

async function checkUpdate() {
    var btn = document.getElementById('btnCheckUpdate');
    var result = document.getElementById('updateResult');
    btn.disabled = true;
    btn.innerHTML = '<i class="ti ti-loader-2 text-base animate-spin"></i> ' + <?php echo json_encode(__('upg_checking'), JSON_UNESCAPED_UNICODE); ?>;

    try {
        var formData = new FormData();
        formData.append('action', 'check_update');
        var response = await fetch('', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        if (!response.ok) {
            throw new Error(<?php echo json_encode(__('upg_bad_response'), JSON_UNESCAPED_UNICODE); ?> + ' (HTTP ' + response.status + ')');
        }

        var data = await safeJson(response);
        result.classList.remove('hidden');

        if (data.code === 0 && data.data && data.data.has_update) {
            var d = data.data;
            result.innerHTML = '<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">'
                + '<div class="flex items-start gap-3">'
                + '<i class="ti ti-info-circle text-lg text-blue-500 mt-0.5 flex-shrink-0"></i>'
                + '<div class="flex-1">'
                + '<p class="font-medium text-blue-800 mb-1">' + <?php echo json_encode(__('upg_new_version_found'), JSON_UNESCAPED_UNICODE); ?> + ' <span class="font-mono">v' + escapeHtml(d.latest_version) + '</span></p>'
                + (d.release_date ? '<p class="text-sm text-blue-600 mb-2">' + <?php echo json_encode(__('upg_release_date'), JSON_UNESCAPED_UNICODE); ?> + escapeHtml(d.release_date) + '</p>' : '')
                + (d.changelog ? '<div class="text-sm text-blue-700 mb-3 whitespace-pre-line">' + escapeHtml(d.changelog) + '</div>' : '')
                + (d.download_url ? '<a href="' + escapeHtml(d.download_url) + '" target="_blank" class="inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded text-sm transition"><i class="ti ti-download text-base"></i> ' + <?php echo json_encode(__('upg_download_pkg'), JSON_UNESCAPED_UNICODE); ?> + '</a>' : '')
                + '</div></div></div>';
        } else if (data.code === 0) {
            result.innerHTML = '<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4 flex items-center gap-3">'
                + '<i class="ti ti-circle-check text-lg text-green-500 flex-shrink-0"></i>'
                + '<p class="text-green-700">' + <?php echo json_encode(__('upg_up_to_date_msg'), JSON_UNESCAPED_UNICODE); ?> + ' <span class="font-mono font-medium">v' + escapeHtml(currentVersion) + '</span></p>'
                + '</div>';
        } else {
            throw new Error(data.msg || <?php echo json_encode(__('upg_check_failed'), JSON_UNESCAPED_UNICODE); ?>);
        }
    } catch (err) {
        result.classList.remove('hidden');
        result.innerHTML = '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4 flex items-center gap-3">'
            + '<i class="ti ti-alert-circle text-lg text-red-500 flex-shrink-0"></i>'
            + '<div><p class="text-red-700 font-medium">' + <?php echo json_encode(__('upg_check_failed'), JSON_UNESCAPED_UNICODE); ?> + '</p><p class="text-red-600 text-sm mt-0.5">' + escapeHtml(err.message) + '</p></div>'
            + '</div>';
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="ti ti-refresh text-base"></i> ' + <?php echo json_encode(__('upg_check_again'), JSON_UNESCAPED_UNICODE); ?>;
}

function escapeHtml(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>
<?php endif; ?>

<?php if ($tab === 'manual'): ?>
<?php
// 手动升级向导。由来：不少客户站点主机不支持在线升级（PHP 无写权限、跨境下载被
// 网关掐断），只能 FTP 覆盖——而多数用户并不知道「哪些文件不能覆盖」，
// 覆盖掉 config.php 或 uploads/ 就是事故。把步骤和禁区写死在这里。
$__mCur = defined('CMS_VERSION') ? CMS_VERSION : '?';
?>
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
            <i class="ti ti-file-zip text-blue-500"></i><?php echo e(__('upgrade_manual_title')); ?>
        </h2>
    </div>
    <div class="p-6">
        <p class="text-sm text-gray-500 leading-relaxed mb-5"><?php echo e(__('upgrade_manual_intro')); ?></p>

        <?php
        // 这里只列**真实存在的**风险，不吓唬人：
        //  · config.php / uploads/ / storage/ 根本不在安装包里（包内只有 config.sample.php
        //    与两个空占位文件），正常覆盖动不到它们 —— 危险的是「先删后传」那种操作。
        //  · 真正会被盖掉的是包里确实有、而客户又常改的那几个文件。今天两起真事故：
        //    xcidcn 的 style.css 追加规则、cile.cn 的 list.php 垫片，都是这么丢的。
        ?>
        <div class="bg-red-50 border border-red-200 rounded-lg px-4 py-3 mb-4">
            <p class="text-sm font-medium text-red-800 mb-1.5">
                <i class="ti ti-alert-triangle mr-1"></i><?php echo e(__('upgrade_manual_danger_title')); ?>
            </p>
            <p class="text-xs text-red-700 leading-relaxed"><?php echo e(__('upgrade_manual_danger_delete')); ?></p>
        </div>

        <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 mb-5">
            <p class="text-sm font-medium text-amber-800 mb-1.5">
                <i class="ti ti-file-pencil mr-1"></i><?php echo e(__('upgrade_manual_custom_title')); ?>
            </p>
            <p class="text-xs text-amber-700 leading-relaxed mb-2"><?php echo e(__('upgrade_manual_custom_tip')); ?></p>
            <ul class="text-xs text-amber-700 space-y-1 pl-5 list-disc">
                <li><code class="bg-white px-1 rounded">.htaccess</code> — <?php echo e(__('upgrade_manual_custom_htaccess')); ?></li>
                <li><code class="bg-white px-1 rounded">robots.txt</code> — <?php echo e(__('upgrade_manual_custom_seo')); ?></li>
                <li><code class="bg-white px-1 rounded">themes/</code> · <code class="bg-white px-1 rounded">assets/css/style.css</code> — <?php echo e(__('upgrade_manual_custom_theme')); ?></li>
            </ul>
        </div>

        <ol class="space-y-4 text-sm text-gray-700">
            <li class="flex gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-primary text-white text-xs flex items-center justify-center">1</span>
                <div>
                    <div class="font-medium"><?php echo e(__('upgrade_manual_s1')); ?></div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo e(__('upgrade_manual_s1_tip')); ?></p>
                    <a href="/admin/database.php?tab=backup" target="_blank"
                       class="inline-flex items-center gap-1 mt-2 text-xs text-primary hover:underline">
                        <i class="ti ti-database-export"></i><?php echo e(__('upgrade_manual_s1_go')); ?>
                    </a>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-primary text-white text-xs flex items-center justify-center">2</span>
                <div>
                    <div class="font-medium"><?php echo e(__('upgrade_manual_s2')); ?></div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo e(__('upgrade_manual_s2_tip')); ?></p>
                    <a href="https://www.yikaicms.com/changelog.html" target="_blank" rel="noopener"
                       class="inline-flex items-center gap-1 mt-2 text-xs text-primary hover:underline">
                        <i class="ti ti-external-link"></i><?php echo e(__('upgrade_manual_s2_go')); ?>
                    </a>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-primary text-white text-xs flex items-center justify-center">3</span>
                <div>
                    <div class="font-medium"><?php echo e(__('upgrade_manual_s3')); ?></div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo e(__('upgrade_manual_s3_tip')); ?></p>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-primary text-white text-xs flex items-center justify-center">4</span>
                <div>
                    <div class="font-medium"><?php echo e(__('upgrade_manual_s4')); ?></div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo e(__('upgrade_manual_s4_tip')); ?></p>
                </div>
            </li>
            <li class="flex gap-3">
                <span class="shrink-0 w-6 h-6 rounded-full bg-green-600 text-white text-xs flex items-center justify-center">5</span>
                <div>
                    <div class="font-medium"><?php echo e(__('upgrade_manual_s5')); ?></div>
                    <p class="text-xs text-gray-500 mt-1"><?php echo e(__('upgrade_manual_s5_tip')); ?></p>
                    <a href="/admin/upgrade.php?tab=check"
                       class="inline-flex items-center gap-1 mt-2 text-xs text-primary hover:underline">
                        <i class="ti ti-database-cog"></i><?php echo e(__('upgrade_manual_s5_go')); ?>
                    </a>
                </div>
            </li>
        </ol>

        <div class="mt-6 pt-5 border-t border-gray-100">
            <p class="text-xs text-gray-500 leading-relaxed">
                <i class="ti ti-info-circle mr-1"></i>
                <?php echo e(str_replace(':version', $__mCur, __('upgrade_manual_current'))); ?>
                <?php echo e(__('upgrade_manual_prefer_online')); ?>
                <a href="/admin/upgrade_online.php" class="text-primary hover:underline"><?php echo e(__('upgrade_online')); ?></a>
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'config'): ?>
<!-- 升级配置 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="px-6 py-4 border-b">
        <h2 class="font-bold text-gray-800"><?php echo __('upgrade_config_title'); ?></h2>
    </div>
    <div class="px-6 py-5">
        <?php
        // 级别：新键优先；未设置时兼容旧的布尔开关
        $__lv = (string) config('update_notify_level', '');
        if ($__lv === '') {
            $__lv = config('dashboard_update_check', '1') === '0' ? 'off' : 'all';
        }
        ?>
        <label class="block text-sm text-gray-700 mb-2"><?php echo __('upgrade_notify_label'); ?></label>
        <select id="notifyLevel" onchange="saveNotifyLevel(this)" class="border rounded px-3 py-2 text-sm bg-white w-full sm:w-72">
            <option value="all" <?php echo $__lv === 'all' ? 'selected' : ''; ?>><?php echo __('upgrade_notify_all'); ?></option>
            <option value="security" <?php echo $__lv === 'security' ? 'selected' : ''; ?>><?php echo __('upgrade_notify_security'); ?></option>
            <option value="off" <?php echo $__lv === 'off' ? 'selected' : ''; ?>><?php echo __('upgrade_notify_off'); ?></option>
        </select>
        <p class="text-xs text-gray-400 mt-2"><?php echo __('upgrade_notify_tip'); ?></p>

        <?php $__updateChannel = UpdateChannel::current(); ?>
        <div class="border-t border-gray-100 mt-5 pt-5">
            <label class="inline-flex items-center gap-3 cursor-pointer" data-testid="update-beta-control">
                <input id="betaUpdateToggle" type="checkbox" class="sr-only peer" data-testid="update-beta-toggle"
                       onchange="saveUpdateChannel(this)" <?php echo $__updateChannel === UpdateChannel::BETA ? 'checked' : ''; ?>>
                <span class="relative w-10 h-6 rounded-full bg-gray-200 peer-checked:bg-primary transition-colors
                             after:content-[''] after:absolute after:top-1 after:left-1 after:w-4 after:h-4 after:bg-white after:rounded-full after:shadow after:transition-transform peer-checked:after:translate-x-4"></span>
                <span>
                    <span class="block text-sm font-medium text-gray-700"><?php echo e(__('upgrade_beta_label')); ?></span>
                    <span class="block text-xs text-gray-400 mt-0.5"><?php echo e(__('upgrade_beta_tip')); ?></span>
                </span>
            </label>
        </div>

        <?php
        require_once ROOT_PATH . '/includes/Cron.php';   // 本页不走 init.php，Cron::tasks() 要显式引入
        Cron::boot();
        require_once ROOT_PATH . '/includes/AutoUpgrade.php';
        $__auOn = AutoUpgrade::enabled();
        $__auScope = AutoUpgrade::scope();
        $__auWindow = (string) config('auto_upgrade_window', '03:00-05:00');
        // 定时任务近 24 小时跑过没有——没配 crontab 的主机上自动升级不会自行触发，要明说
        $__cronAlive = false;
        try {
            foreach (Cron::tasks() as $__t) {
                if ((int) ($__t['last'] ?? 0) > time() - 86400) { $__cronAlive = true; break; }
            }
        } catch (\Throwable $e) {
        }
        ?>
        <div class="border-t border-gray-100 mt-5 pt-5">
            <label class="inline-flex items-center gap-3 cursor-pointer" data-testid="auto-upgrade-control">
                <input id="autoUpgradeToggle" type="checkbox" class="sr-only peer" data-testid="auto-upgrade-toggle"
                       onchange="saveAutoUpgrade()" <?php echo $__auOn ? 'checked' : ''; ?>>
                <span class="relative w-10 h-6 rounded-full bg-gray-200 peer-checked:bg-primary transition-colors
                             after:content-[''] after:absolute after:top-1 after:left-1 after:w-4 after:h-4 after:bg-white after:rounded-full after:shadow after:transition-transform peer-checked:after:translate-x-4"></span>
                <span>
                    <span class="block text-sm font-medium text-gray-700">
                        <?php echo e(__('upgrade_auto_label')); ?>
                        <span class="ml-1.5 align-middle text-[10px] font-medium bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded"><?php echo e(__('upgrade_auto_beta_badge')); ?></span>
                    </span>
                    <span class="block text-xs text-gray-400 mt-0.5"><?php echo e(__('upgrade_auto_tip')); ?></span>
                </span>
            </label>

            <?php // 功能测试阶段：真实站点闭环演练尚未完成，必须让人知道自己在用什么 ?>
            <div id="autoUpgradeBeta" class="mt-3 bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded px-3 py-2 <?php echo $__auOn ? '' : 'hidden'; ?>">
                <i class="ti ti-flask mr-1"></i><?php echo e(__('upgrade_auto_beta_warn')); ?>
            </div>

            <div id="autoUpgradeOptions" class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4 <?php echo $__auOn ? '' : 'hidden'; ?>">
                <div>
                    <label class="block text-xs text-gray-500 mb-1.5"><?php echo e(__('upgrade_auto_scope_label')); ?></label>
                    <select id="autoUpgradeScope" onchange="saveAutoUpgrade()" class="w-full border rounded px-3 py-2 text-sm bg-white">
                        <option value="security" <?php echo $__auScope === 'security' ? 'selected' : ''; ?>><?php echo e(__('upgrade_auto_scope_security')); ?></option>
                        <option value="stable" <?php echo $__auScope === 'stable' ? 'selected' : ''; ?>><?php echo e(__('upgrade_auto_scope_stable')); ?></option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs text-gray-500 mb-1.5"><?php echo e(__('upgrade_auto_window_label')); ?></label>
                    <input id="autoUpgradeWindow" type="text" value="<?php echo e($__auWindow); ?>" placeholder="03:00-05:00"
                           onchange="saveAutoUpgrade()" class="w-full border rounded px-3 py-2 text-sm">
                </div>
            </div>

            <?php if (!$__cronAlive): ?>
            <p id="autoUpgradeCronWarn" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2 mt-3 <?php echo $__auOn ? '' : 'hidden'; ?>">
                <i class="ti ti-alert-triangle mr-1"></i><?php echo e(__('upgrade_auto_need_cron')); ?>
                <a href="/admin/cron.php" class="underline font-medium"><?php echo e(__('cron_setup_title')); ?></a>
            </p>
            <?php endif; ?>

            <p class="text-xs text-gray-400 mt-2"><?php echo e(__('upgrade_auto_safety')); ?></p>

            <?php $__auLog = AutoUpgrade::log(); ?>
            <div class="mt-4 flex items-center gap-3 flex-wrap">
                <button type="button" id="autoUpgradeRunBtn" onclick="runAutoUpgrade()"
                        class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-200 hover:border-primary hover:text-primary rounded text-sm disabled:opacity-50">
                    <i class="ti ti-player-play text-base"></i><span><?php echo e(__('upgrade_auto_run_now')); ?></span>
                </button>
                <span id="autoUpgradeRunMsg" class="text-xs text-gray-500"></span>
            </div>

            <?php if ($__auLog): ?>
            <?php // 设置与结果放在同一屏：开了之后到底有没有跑、结果如何，不用另找地方 ?>
            <div class="mt-4">
                <div class="text-xs font-medium text-gray-500 mb-2"><?php echo e(__('upgrade_auto_history')); ?></div>
                <div class="overflow-x-auto border border-gray-100 rounded-lg">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap"><?php echo e(__('upgrade_auto_time')); ?></th>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap"><?php echo e(__('upgrade_auto_result')); ?></th>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap"><?php echo e(__('upgrade_auto_versions')); ?></th>
                                <th class="px-3 py-2 text-left font-medium"><?php echo e(__('upgrade_auto_detail')); ?></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($__auLog as $__row):
                                $__res = (string) ($__row['result'] ?? '');
                                [$__tone, $__label] = match ($__res) {
                                    'ok' => ['text-green-600', __('upgrade_auto_res_ok')],
                                    'rolled_back' => ['text-amber-600', __('upgrade_auto_res_rolled')],
                                    'failed' => ['text-red-500', __('upgrade_auto_res_failed')],
                                    default => ['text-gray-400', $__res],
                                };
                            ?>
                            <tr>
                                <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?php echo e((string) ($__row['time'] ?? '')); ?></td>
                                <td class="px-3 py-2 whitespace-nowrap <?php echo $__tone; ?>"><?php echo e($__label); ?></td>
                                <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?php echo e((string) ($__row['from'] ?? '')); ?> → <?php echo e((string) ($__row['to'] ?? '')); ?></td>
                                <td class="px-3 py-2 text-gray-600"><?php echo e((string) ($__row['msg'] ?? '')); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
async function saveNotifyLevel(sel) {
    var fd = new FormData();
    fd.append('action', 'save_update_notify');
    fd.append('level', sel.value);
    try {
        await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        showMessage('<?php echo e(__('admin_saved')); ?>');
    } catch (e) { showMessage('<?php echo e(__('admin_save_failed')); ?>', 'error'); }
}

async function saveAutoUpgrade() {
    var on = document.getElementById('autoUpgradeToggle').checked;
    var opts = document.getElementById('autoUpgradeOptions');
    var warn = document.getElementById('autoUpgradeCronWarn');
    var beta = document.getElementById('autoUpgradeBeta');
    opts.classList.toggle('hidden', !on);
    if (beta) beta.classList.toggle('hidden', !on);
    if (warn) warn.classList.toggle('hidden', !on);

    var fd = new FormData();
    fd.append('action', 'save_auto_upgrade');
    fd.append('_token', '<?php echo csrfToken(); ?>');
    fd.append('enabled', on ? '1' : '0');
    fd.append('scope', document.getElementById('autoUpgradeScope').value);
    fd.append('window', document.getElementById('autoUpgradeWindow').value);
    try {
        var r = await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        var d = await r.json();
        if (!d || d.code !== 0) throw new Error(d && d.msg ? d.msg : 'save failed');
        // 服务端可能把非法窗口回落成默认值，回填让界面与实际一致
        if (d.data && d.data.window) document.getElementById('autoUpgradeWindow').value = d.data.window;
        showMessage('<?php echo e(__('admin_saved')); ?>');
    } catch (e) {
        showMessage('<?php echo e(__('admin_save_failed')); ?>', 'error');
    }
}

async function runAutoUpgrade() {
    var btn = document.getElementById('autoUpgradeRunBtn');
    var msg = document.getElementById('autoUpgradeRunMsg');
    btn.disabled = true;
    msg.textContent = '<?php echo e(__('upgrade_auto_running')); ?>';
    var fd = new FormData();
    fd.append('action', 'run_auto_upgrade');
    fd.append('_token', '<?php echo csrfToken(); ?>');
    try {
        var r = await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        var d = await r.json();
        msg.textContent = (d && d.data && d.data.result) ? d.data.result : '';
        // 真升级了就刷新：版本号与历史都变了
        if (d && d.data && /upgraded|rolled back/.test(String(d.data.result))) {
            setTimeout(function () { location.reload(); }, 1500);
        }
    } catch (e) {
        msg.textContent = '<?php echo e(__('admin_save_failed')); ?>';
    } finally {
        btn.disabled = false;
    }
}

async function saveUpdateChannel(toggle) {
    var fd = new FormData();
    fd.append('action', 'save_update_channel');
    fd.append('channel', toggle.checked ? 'beta' : 'stable');
    toggle.disabled = true;
    try {
        var response = await fetch('', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        var data = await response.json();
        if (!response.ok || !data || data.code !== 0) throw new Error(data && data.msg ? data.msg : 'save failed');
        try {
            Object.keys(localStorage).filter(function (key) { return key.indexOf('yk_upd_') === 0; })
                .forEach(function (key) { localStorage.removeItem(key); });
        } catch (e) {}
        showMessage(toggle.checked ? '<?php echo e(__('upgrade_beta_enabled')); ?>' : '<?php echo e(__('admin_saved')); ?>');
    } catch (e) {
        toggle.checked = !toggle.checked;
        showMessage('<?php echo e(__('admin_save_failed')); ?>', 'error');
    }
    toggle.disabled = false;
}
</script>
<?php endif; ?>

<?php if ($tab === 'welcome'): ?>
<div class="bg-white rounded-lg shadow overflow-hidden mb-6">
    <div class="px-8 py-12 text-center border-b">
        <div class="w-16 h-16 mx-auto mb-5 rounded-full bg-green-50 flex items-center justify-center">
            <i class="ti ti-circle-check text-3xl text-green-500"></i>
        </div>
        <h2 class="text-2xl font-semibold text-gray-900 mb-2">
            <?php echo e(str_replace(':version', 'v' . (defined('CMS_VERSION') ? CMS_VERSION : ''), __('upgrade_welcome_title'))); ?>
        </h2>
        <p class="text-gray-500">
            <?php if ($welcomeFrom !== '' && $welcomeTo !== ''): ?>
                <?php echo e(str_replace([':from', ':to'], ['v' . $welcomeFrom, 'v' . $welcomeTo], __('upgrade_welcome_from_to'))); ?>
            <?php else: ?>
                <?php echo e(__('upgrade_welcome_sub')); ?>
            <?php endif; ?>
            <?php if ($welcomeAt > 0): ?>
            <span class="text-gray-300 mx-1">·</span><?php echo e(formatTime($welcomeAt, 'Y-m-d H:i')); ?>
            <?php endif; ?>
        </p>
    </div>

    <?php if (trim($welcomeNote) !== ''): ?>
    <div class="px-8 py-6 border-b bg-gray-50">
        <h3 class="text-sm font-semibold text-gray-700 mb-3"><?php echo e(__('upgrade_welcome_whats_new')); ?></h3>
        <pre class="text-sm text-gray-600 whitespace-pre-wrap leading-relaxed max-h-80 overflow-y-auto"><?php echo e($welcomeNote); ?></pre>
    </div>
    <?php endif; ?>

    <div class="px-8 py-6 grid grid-cols-1 sm:grid-cols-3 gap-4">
        <a href="/admin/index.php" class="block px-4 py-4 rounded-lg border hover:border-primary hover:shadow-sm transition text-center">
            <i class="ti ti-dashboard text-xl text-gray-400 block mb-2"></i>
            <span class="text-sm text-gray-700"><?php echo e(__('upgrade_welcome_go_dashboard')); ?></span>
        </a>
        <a href="/" target="_blank" class="block px-4 py-4 rounded-lg border hover:border-primary hover:shadow-sm transition text-center">
            <i class="ti ti-world text-xl text-gray-400 block mb-2"></i>
            <span class="text-sm text-gray-700"><?php echo e(__('upgrade_welcome_view_site')); ?></span>
        </a>
        <a href="/admin/upgrade.php?tab=history" class="block px-4 py-4 rounded-lg border hover:border-primary hover:shadow-sm transition text-center">
            <i class="ti ti-history text-xl text-gray-400 block mb-2"></i>
            <span class="text-sm text-gray-700"><?php echo e(__('upgrade_tab_history')); ?></span>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if ($tab === 'history'): ?>
<div class="bg-white rounded-lg shadow mb-6 px-6 py-4 flex items-center justify-between flex-wrap gap-2">
    <div class="text-sm text-gray-600">
        <?php echo e(__('upgrade_hist_current')); ?>
        <span class="ml-1 font-mono font-medium text-gray-900">v<?php echo e(defined('CMS_VERSION') ? CMS_VERSION : '—'); ?></span>
    </div>
    <div class="text-sm text-gray-400"><?php echo e(__('upgrade_hist_tip')); ?></div>
</div>

<div class="bg-white rounded-lg shadow overflow-hidden mb-6">
    <?php if (empty($upgLogs)): ?>
    <div class="p-12 text-center">
        <i class="ti ti-history text-base mx-auto text-gray-300 mb-4"></i>
        <p class="text-gray-500"><?php echo e(__('upgrade_hist_empty')); ?></p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500">
                <tr>
                    <th class="px-6 py-3 text-left font-medium whitespace-nowrap"><?php echo e(__('upgrade_hist_time')); ?></th>
                    <th class="px-6 py-3 text-left font-medium whitespace-nowrap"><?php echo e(__('upgrade_hist_type')); ?></th>
                    <th class="px-6 py-3 text-left font-medium"><?php echo e(__('upgrade_hist_detail')); ?></th>
                    <th class="px-6 py-3 text-left font-medium whitespace-nowrap"><?php echo e(__('upgrade_hist_operator')); ?></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                <?php foreach ($upgLogs as $lg): ?>
                <?php
                    // online_apply = 覆盖程序文件；execute = 跑了一条数据库迁移
                    $isFiles = ($lg['action'] ?? '') === 'online_apply';
                    $isDb    = ($lg['action'] ?? '') === 'execute';
                ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-3 whitespace-nowrap text-gray-600 font-mono text-xs">
                        <?php echo e(formatTime((int) ($lg['created_at'] ?? 0), 'Y-m-d H:i:s')); ?>
                    </td>
                    <td class="px-6 py-3 whitespace-nowrap">
                        <span class="inline-block px-2 py-0.5 rounded text-xs <?php echo $isFiles ? 'bg-blue-50 text-blue-700' : ($isDb ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-600'); ?>">
                            <?php echo e($isFiles ? __('upgrade_hist_type_files') : ($isDb ? __('upgrade_hist_type_db') : (string) ($lg['action'] ?? ''))); ?>
                        </span>
                    </td>
                    <td class="px-6 py-3 text-gray-700 break-all"><?php echo e((string) ($lg['description'] ?? '')); ?></td>
                    <td class="px-6 py-3 whitespace-nowrap text-gray-500">
                        <?php echo e((string) ($lg['admin_name'] ?? '')); ?>
                        <span class="text-gray-300 ml-1 font-mono text-xs"><?php echo e((string) ($lg['ip'] ?? '')); ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($upgBackups)): ?>
<div class="bg-white rounded-lg shadow overflow-hidden">
    <div class="px-6 py-4 border-b">
        <h3 class="font-medium text-gray-800"><?php echo e(__('upgrade_hist_backups')); ?></h3>
        <p class="text-sm text-gray-400 mt-1"><?php echo e(__('upgrade_hist_backups_tip')); ?></p>
    </div>
    <ul class="divide-y">
        <?php foreach ($upgBackups as $bk): ?>
        <li class="px-6 py-3 flex items-center justify-between text-sm">
            <span class="font-mono text-gray-700 break-all">storage/backups/<?php echo e($bk['name']); ?></span>
            <span class="text-gray-400 whitespace-nowrap ml-4"><?php echo e(formatTime($bk['time'], 'Y-m-d H:i')); ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
