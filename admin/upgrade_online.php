<?php
/**
 * YikaiCMS - 在线升级（一键更新程序文件）
 *
 * 流程：预检 → 检查更新 → 下载并校验(SHA256 + 强制 RSA 签名) → 备份 → 解压覆盖 → 补丁 config 版本行
 *       → 交给 upgrade.php 跑数据库迁移。
 *
 * 安全：仅管理员(checkLogin + requirePermission('*'))；所有写操作校验 CSRF；
 *       下载校验哈希，并强制验证由 CMS 内置公钥信任的官方 RSA 签名。
 * 原则：失败即停、保留备份，绝不留半截站点。config.php / storage / uploads / install 永不覆盖。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));

// 升级管道（状态机 / 路径护栏 / 下载验签 / 健康自检）已抽到 includes/UpgradeRunner.php，
// 以便 cron 的自动升级复用同一套逻辑（v1.18.6）。本文件只负责鉴权、HTTP 路由与页面渲染。
// 注：抽出后，单元测试直接 require 那个文件即可，原先为「只加载状态机」而设的
// YIKAI_UPGRADE_APPLY_STATE_ONLY 常量守卫随之取消。
require_once ROOT_PATH . '/includes/UpgradeRunner.php';

require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/UpdateChannel.php';
require_once ROOT_PATH . '/includes/UpdatePackageSignature.php';
require_once ROOT_PATH . '/includes/security.php';   // zipUnsafeEntry（zip-slip 防护）；admin 页不走 init.php，须显式引入
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// License 验签所需的公钥函数；旧版本 auth.php 不会自动加载它，这里按需引入，
// 使本升级器在被引导进旧版本站点时也能强制 RSA 验签。
if (!function_exists('license_pubkey') && is_file(ROOT_PATH . '/includes/License.php')) {
    require_once ROOT_PATH . '/includes/License.php';
}

function uo_json(array $d): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}


// ============================================================
// AJAX 路由
// ============================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') verifyCsrf();
    @set_time_limit(600);
    // 关键：绝不让 PHP 警告/通知打印进响应体（否则污染 JSON → 前端解析失败静默卡住）。
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    // 致命错误兜底：转成 JSON 返回，让前端看到原因而不是无限转圈。
    register_shutdown_function(function () {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
            echo "\n" . json_encode(['code' => 1, 'msg' => '服务器致命错误：' . $e['message']], JSON_UNESCAPED_UNICODE);
        }
    });

    // ---- 0) 自动升级：设置保存 / 手动触发 ----
    if ($action === 'auto_save' || $action === 'auto_run') {
        require_once ROOT_PATH . '/includes/AutoUpgrade.php';
        if ($action === 'auto_save') {
            $win = trim((string) post('window'));
            // 窗口格式不合规就回落默认，别把配置错误变成「永不升级」或「随时升级」
            if (preg_match('/^\d{1,2}:\d{2}\s*-\s*\d{1,2}:\d{2}$/', $win) !== 1) {
                $win = '03:00-05:00';
            }
            settingModel()->saveBatch([
                'auto_upgrade_enabled' => post('enabled') === '1' ? '1' : '0',
                'auto_upgrade_scope'   => post('scope') === 'stable' ? 'stable' : 'security',
                'auto_upgrade_window'  => $win,
            ]);
            adminLog('upgrade', 'auto_upgrade_config', '自动升级设置：' . (post('enabled') === '1' ? '开' : '关'));
            uo_json(['code' => 0, 'msg' => '已保存', 'window' => $win]);
        }
        @set_time_limit(0);
        uo_json(['code' => 0, 'msg' => AutoUpgrade::run(true)]);
    }

    // ---- 1) 环境预检 ----
    if ($action === 'precheck') {
        if (!is_dir(uo_dir())) {
            @mkdir(uo_dir(), 0755, true);
        }
        $checks = [];
        $checks[] = ['name' => 'ZipArchive 扩展', 'ok' => class_exists('ZipArchive'), 'hint' => '解压安装包必需'];
        $checks[] = ['name' => 'OpenSSL 扩展', 'ok' => function_exists('openssl_verify'), 'hint' => '验证官方升级包签名必需'];
        $checks[] = ['name' => '网络下载能力', 'ok' => function_exists('curl_init') || (bool) ini_get('allow_url_fopen'), 'hint' => 'curl 或 allow_url_fopen'];
        $rootW = is_writable(ROOT_PATH);
        $checks[] = ['name' => 'Web 根目录可写', 'ok' => $rootW, 'hint' => $rootW ? '' : 'PHP 进程无写权限，需改用 FTP 手动升级'];
        $checks[] = ['name' => 'storage/ 可写', 'ok' => is_writable(ROOT_PATH . '/storage'), 'hint' => '存放下载包与备份'];
        $df = function_exists('disk_free_space') ? @disk_free_space(ROOT_PATH) : false;
        $checks[] = ['name' => '磁盘空间', 'ok' => $df === false || $df > 120 * 1024 * 1024, 'hint' => $df ? round($df / 1048576) . ' MB 可用' : '无法检测'];
        $allOk = !in_array(false, array_column($checks, 'ok'), true);
        uo_json(['code' => 0, 'all_ok' => $allOk, 'checks' => $checks]);
    }

    // ---- 2) 检查更新（代理 check.php） ----
    if ($action === 'check') {
        $cur = defined('CMS_VERSION') ? CMS_VERSION : '1.0.0';
        $api = UO_UPDATE_SERVER . '/api/update/check.php?version=' . urlencode($cur)
            . '&channel=' . urlencode(UpdateChannel::current())
            . '&domain=' . urlencode($_SERVER['HTTP_HOST'] ?? '')
            . '&site_name=' . urlencode((string) config('site_name', ''))
            . '&php=' . urlencode(PHP_VERSION)
            . '&t=' . time();   // 缓存破坏：绕开 update 服务器 SiteGround 边缘缓存，拿实时版本
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'ignore_errors' => true], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $resp = @file_get_contents($api, false, $ctx);
        if ($resp === false && function_exists('curl_init')) {
            $ch = curl_init($api);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true]);
            $resp = curl_exec($ch);
            curl_close($ch);
        }
        $data = $resp ? json_decode($resp, true) : null;
        if (!is_array($data)) uo_json(['code' => 1, 'msg' => '无法连接更新服务器或返回格式错误']);
        $data['current_version'] = $cur;
        uo_json($data);
    }

    // ---- 3) 下载并校验 ----
    if ($action === 'download') {
        uo_json(upgrade_download_package(
            (string) ($_POST['download_url'] ?? ''),
            (string) ($_POST['hash'] ?? ''),
            (string) ($_POST['version'] ?? ''),
            (string) ($_POST['sig'] ?? '')
        ));
    }

    // ---- 4a) 准备：备份 config + 校验结构 + 建 zip 条目清单（不解压，写状态文件）----
    //   不用 extractTo（共享主机上常失败/挂起）；后续 batch 从 zip 逐条流式写入目标。
    if ($action === 'apply_prepare') {
        uo_json(upgrade_prepare());
    }

    // ---- 4b) 分批覆盖：服务端状态游标为准；客户端 offset 只用于兼容和防跳批校验 ----
    if ($action === 'apply_batch') {
        uo_json(upgrade_batch(array_key_exists('offset', $_POST) ? $_POST['offset'] : null));
    }

    // ---- 4c) 收尾：删除废弃文件（delta）+ 补 config 版本行 + 清理临时 ----
    if ($action === 'apply_finalize') {
        uo_json(upgrade_finalize((string) post('note')));
    }

    // ---- 4d) 回滚：从快照恢复被覆盖/被删除的文件，移除升级新建的文件 ----
    if ($action === 'apply_rollback') {
        uo_json(upgrade_rollback((string) post('backup')));
    }

    uo_json(['code' => 1, 'msg' => '未知操作']);
}

// ============================================================
// 页面
// ============================================================
$pageTitle = __('upgrade_online');
$currentMenu = 'upgrade';   // 与「系统升级」共用菜单（升级页两个标签之一）
require_once ROOT_PATH . '/includes/AutoUpgrade.php';
require_once ROOT_PATH . '/includes/Cron.php';   // 本页不走 init.php，Cron::tasks() 要显式引入
Cron::boot();
$autoEnabled = AutoUpgrade::enabled();
$autoScope   = AutoUpgrade::scope();
$autoWindow  = (string) config('auto_upgrade_window', '03:00-05:00');
$autoLog     = AutoUpgrade::log();
// 定时任务近 24 小时跑过没有——没配 crontab 的主机上自动升级不会自行触发，要明说
$cronAlive = false;
try {
    foreach (Cron::tasks() as $t) {
        if ((int) ($t['last'] ?? 0) > time() - 86400) { $cronAlive = true; break; }
    }
} catch (\Throwable $e) {
}

require_once ROOT_PATH . '/admin/includes/header.php';
?>
<?php // 标签栏由 admin/includes/upgrade_tabs.php 统一渲染
$__upgTab = 'online';
require ROOT_PATH . '/admin/includes/upgrade_tabs.php';
?>

<div class="p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold text-gray-800"><i class="ti ti-cloud-download text-blue-500 mr-2"></i>在线升级</h1>
        <span class="text-sm text-gray-500">当前版本 v<?= e(defined('CMS_VERSION') ? CMS_VERSION : '?') ?></span>
    </div>

    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg p-4 mb-5">
        <p class="font-medium mb-1"><i class="ti ti-alert-triangle mr-1"></i>升级前请知悉</p>
        <ul class="list-disc pl-5 space-y-0.5">
            <li>本次只覆盖<b>程序文件</b>；配置 config.php、上传文件 uploads、运行数据 storage 与 install 目录<b>保持不动</b>。</li>
            <li>升级前请先<a href="/admin/database.php?tab=backup" target="_blank" class="font-semibold underline hover:text-amber-900">备份数据库</a>——文件更新完成后还要运行数据库迁移，迁移会改动表结构。</li>
            <li>如果你直接改过核心程序文件（主题与插件不受影响），请自行留存副本：这些改动会被新版本覆盖。</li>
            <li>升级过程会自动保存一份 config.php 到 storage/backups/，供万一需要时比对。</li>
        </ul>
    </div>

    <?php // 自动升级：站点自己在维护窗口里跑同一条升级管道；失败自动回滚 ?>
    <div id="auto-upgrade" class="bg-white border border-gray-200 rounded-lg mb-5" x-data="autoUpgrade()">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3 flex-wrap">
            <h2 class="font-bold text-gray-800 inline-flex items-center gap-2">
                <i class="ti ti-refresh-dot text-blue-500"></i>自动升级
            </h2>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" class="sr-only peer" x-model="enabled" @change="save()">
                <span class="w-9 h-5 bg-gray-200 rounded-full peer peer-checked:bg-primary transition-colors"></span>
                <span class="absolute left-0.5 top-0.5 w-4 h-4 bg-white rounded-full transition-transform peer-checked:translate-x-4"></span>
            </label>
        </div>
        <div class="p-5">
            <p class="text-sm text-gray-500 leading-relaxed mb-4">
                开启后，本站会在维护窗口内自己完成升级：下载官方包（校验 SHA256 与 RSA 签名）→
                备份数据库 → 覆盖文件（逐个先快照）→ 运行数据库迁移 → 健康自检。
                <b>自检不通过会自动回滚到升级前</b>——无人值守时没人来救场。
            </p>

            <?php if (!$cronAlive): ?>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 text-xs rounded-lg px-4 py-3 mb-4">
                <i class="ti ti-alert-triangle mr-1"></i>
                本站近 24 小时没有定时任务运行记录，自动升级不会自行触发。请先到
                <a href="/admin/cron.php" class="underline font-medium">系统 → 定时任务</a> 按说明配置。
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4" :class="enabled ? '' : 'opacity-50 pointer-events-none'">
                <div>
                    <label class="block text-sm text-gray-600 mb-1.5">升级范围</label>
                    <select x-model="scope" @change="save()" class="w-full border border-gray-200 rounded px-3 py-2 text-sm bg-white">
                        <option value="security">仅安全更新（推荐）</option>
                        <option value="stable">所有正式版</option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1" x-text="scope === 'security'
                        ? '只自动安装标记为安全修复的版本，功能版仍由你手动确认。'
                        : '任何新的正式版都会自动安装，站点始终保持最新。'"></p>
                </div>
                <div>
                    <label class="block text-sm text-gray-600 mb-1.5">维护窗口</label>
                    <input type="text" x-model="window" @change="save()" placeholder="03:00-05:00"
                           class="w-full border border-gray-200 rounded px-3 py-2 text-sm">
                    <p class="text-xs text-gray-400 mt-1">按服务器时间，避开访问高峰。跨零点（如 23:00-02:00）也支持。</p>
                </div>
            </div>

            <div class="flex items-center gap-3 flex-wrap">
                <button type="button" @click="runNow()" :disabled="busy"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-white border border-gray-200 hover:border-primary hover:text-primary rounded text-sm disabled:opacity-50">
                    <i class="ti ti-player-play text-base"></i>
                    <span x-text="busy ? '执行中…' : '立即检查并升级'"></span>
                </button>
                <span class="text-xs" :class="msgOk ? 'text-green-600' : 'text-red-500'" x-text="msg"></span>
            </div>

            <?php if ($autoLog): ?>
            <div class="mt-5">
                <div class="text-xs font-medium text-gray-500 mb-2">升级历史（最近 <?= count($autoLog) ?> 次）</div>
                <div class="overflow-x-auto border border-gray-100 rounded-lg">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500">
                            <tr>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">时间</th>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">结果</th>
                                <th class="px-3 py-2 text-left font-medium whitespace-nowrap">版本</th>
                                <th class="px-3 py-2 text-left font-medium">说明</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($autoLog as $row):
                                $res = (string) ($row['result'] ?? '');
                                [$tone, $label] = match ($res) {
                                    'ok' => ['text-green-600', '成功'],
                                    'rolled_back' => ['text-amber-600', '已回滚'],
                                    'failed' => ['text-red-500', '失败'],
                                    default => ['text-gray-400', $res],
                                };
                            ?>
                            <tr>
                                <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?= e((string) ($row['time'] ?? '')) ?></td>
                                <td class="px-3 py-2 whitespace-nowrap <?= $tone ?>"><?= e($label) ?></td>
                                <td class="px-3 py-2 text-gray-500 whitespace-nowrap"><?= e((string) ($row['from'] ?? '')) ?> → <?= e((string) ($row['to'] ?? '')) ?></td>
                                <td class="px-3 py-2 text-gray-600"><?= e((string) ($row['msg'] ?? '')) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div id="uo-steps" class="space-y-3"></div>

    <!-- 新版本信息卡：进入页面自动检查后填充（版本对比 / 升级级别 / 更新内容） -->
    <div id="uo-card" class="hidden mt-5 bg-white border border-gray-200 rounded-lg overflow-hidden">
        <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-3 bg-gray-50">
            <div class="flex items-center gap-3 flex-wrap">
                <div class="flex items-baseline gap-2 font-bold">
                    <span class="text-gray-400 text-base">v<span id="uo-cur"></span></span>
                    <i class="ti ti-arrow-narrow-right text-gray-400"></i>
                    <span class="text-green-600 text-lg">v<span id="uo-new"></span></span>
                </div>
                <span id="uo-level" class="text-xs font-medium px-2 py-0.5 rounded-full"></span>
            </div>
            <span id="uo-date" class="text-xs text-gray-400"></span>
        </div>
        <div class="p-5">
            <h3 class="text-sm font-bold text-gray-700 mb-2"><i class="ti ti-list-details mr-1 text-gray-400"></i>更新内容</h3>
            <pre id="uo-changelog-body" class="text-xs text-gray-600 whitespace-pre-wrap leading-relaxed max-h-72 overflow-y-auto"></pre>
        </div>
    </div>

    <div class="mt-6 flex gap-3">
        <button id="uo-start" class="px-5 py-2.5 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium transition">
            <i class="ti ti-refresh mr-1"></i>重新检查
        </button>
        <button id="uo-upgrade" class="hidden px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition">
            <i class="ti ti-bolt mr-1"></i>一键升级到 <span id="uo-target"></span>
        </button>
        <a href="upgrade.php" id="uo-migrate" class="hidden px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition">
            <i class="ti ti-database mr-1"></i>下一步：升级数据库 →
        </a>
    </div>
</div>

<script>
// 自动升级设置卡（Alpine）
function autoUpgrade() {
    return {
        enabled: <?= $autoEnabled ? 'true' : 'false' ?>,
        scope: <?= json_encode($autoScope) ?>,
        window: <?= json_encode($autoWindow) ?>,
        busy: false,
        msg: '',
        msgOk: true,
        async _post(action, extra) {
            const fd = new FormData();
            fd.append('action', action);
            fd.append('_token', <?= json_encode(csrfToken()) ?>);
            for (const k in (extra || {})) fd.append(k, extra[k]);
            const r = await fetch('upgrade_online.php', { method: 'POST', body: fd });
            return await r.json();
        },
        async save() {
            const d = await this._post('auto_save', {
                enabled: this.enabled ? '1' : '0', scope: this.scope, window: this.window,
            });
            this.msgOk = d.code === 0;
            this.msg = d.msg || '';
            if (d.window) this.window = d.window;   // 服务端回落后的合法值
        },
        async runNow() {
            this.busy = true; this.msg = '';
            try {
                const d = await this._post('auto_run');
                this.msgOk = d.code === 0;
                this.msg = d.msg || '';
                // 真升级了就刷新页面：版本号与历史都变了
                if (d.code === 0 && /upgraded|rolled back/.test(String(d.msg))) {
                    setTimeout(() => location.reload(), 1500);
                }
            } finally {
                this.busy = false;
            }
        },
    };
}

const UO = {
    token: <?= json_encode(csrfToken()) ?>,
    target: null,
    steps: document.getElementById('uo-steps'),
    async post(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('_token', this.token);
        for (const k in data) fd.append(k, data[k]);
        // 兜底超时 + 非 JSON 响应显式报错 —— 绝不让界面无限转圈（旧版 r.json() 抛错即静默卡住）
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), 150000);
        let r;
        try {
            r = await fetch('upgrade_online.php', { method: 'POST', body: fd, signal: ctrl.signal });
        } catch (e) {
            clearTimeout(timer);
            return { code: 1, msg: '请求失败（' + (e && e.name === 'AbortError' ? '服务器长时间无响应，已超时' : (e && e.message || '网络错误')) + '）' };
        }
        clearTimeout(timer);
        const text = await r.text();
        try {
            return JSON.parse(text);
        } catch (e) {
            return { code: 1, msg: '服务器返回异常（HTTP ' + r.status + '）：' + text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300) };
        }
    },
    row(label, state, detail = '') {
        const icon = state === 'ok' ? '<i class="ti ti-circle-check text-green-500"></i>'
            : state === 'fail' ? '<i class="ti ti-circle-x text-red-500"></i>'
            : '<i class="ti ti-loader-2 animate-spin text-blue-500"></i>';
        const d = document.createElement('div');
        d.className = 'flex items-start gap-2 bg-white border border-gray-200 rounded-lg p-3 text-sm';
        d.innerHTML = `<span class="mt-0.5">${icon}</span><div><div class="text-gray-800">${label}</div>${detail ? `<div class="text-gray-500 text-xs mt-0.5">${detail}</div>` : ''}</div>`;
        this.steps.appendChild(d);
        return d;
    },
    set(row, state, detail) {
        const icon = state === 'ok' ? '<i class="ti ti-circle-check text-green-500"></i>' : '<i class="ti ti-circle-x text-red-500"></i>';
        row.querySelector('span').innerHTML = icon;
        if (detail) row.querySelector('div div:last-child') ? row.querySelector('div').insertAdjacentHTML('beforeend', `<div class="text-gray-500 text-xs mt-0.5">${detail}</div>`) : null;
    }
};

/**
 * 升级级别徽章：优先用服务端 level 字段（security/critical/feature/normal），
 * 服务端未提供时从更新说明的关键词推断，兜底为「常规更新」。
 */
function uoLevel(d, logText) {
    const lv = String(d.level || '').toLowerCase();
    if (lv === 'security' || lv === 'critical'
        || (!lv && /安全|漏洞|XSS|CSRF|SQL\s*注入|RCE|越权|提权|security|vulnerab/i.test(logText || ''))) {
        return { cls: 'bg-red-100 text-red-700', text: '关键更新（含安全修复），建议尽快升级' };
    }
    if (lv === 'feature') return { cls: 'bg-blue-100 text-blue-700', text: '功能更新' };
    if (/修复|fix/i.test(logText || '')) return { cls: 'bg-amber-100 text-amber-700', text: '常规更新（含问题修复）' };
    return { cls: 'bg-gray-100 text-gray-600', text: '常规更新' };
}

/** x.y.z 版本号比较：a>b 返回正数 */
function uoCmpVer(a, b) {
    const pa = String(a).split('.').map(Number), pb = String(b).split('.').map(Number);
    for (let i = 0; i < 3; i++) { const df = (pa[i] || 0) - (pb[i] || 0); if (df) return df; }
    return 0;
}

async function uoCheck() {
    UO.steps.innerHTML = '';
    document.getElementById('uo-card').classList.add('hidden');
    document.getElementById('uo-upgrade').classList.add('hidden');
    document.getElementById('uo-migrate').classList.add('hidden');
    // 预检
    let r = UO.row('环境预检…', 'run');
    const pc = await UO.post('precheck');
    if (pc.code !== 0) return UO.set(r, 'fail', pc.msg || '预检失败');
    UO.set(r, pc.all_ok ? 'ok' : 'fail', pc.checks.map(c => `${c.ok ? '✓' : '✗'} ${c.name}${c.hint ? '（' + c.hint + '）' : ''}`).join('　'));
    if (!pc.all_ok) { UO.row('环境不满足，请改用 FTP 手动升级。', 'fail'); return; }
    // 检查更新
    r = UO.row('检查最新版本…', 'run');
    const ck = await UO.post('check');
    if (ck.code !== 0) return UO.set(r, 'fail', ck.msg || '检查失败');
    const d = ck.data || {};
    if (!d.has_update) { UO.set(r, 'ok', `已是最新版本 v${ck.current_version}，无需升级`); return; }
    UO.set(r, 'ok', `发现新版本 v${d.latest_version}`);
    UO.target = d;
    // 版本信息卡：当前 → 最新、发布日期与包大小、升级级别、更新内容
    document.getElementById('uo-cur').textContent = ck.current_version;
    document.getElementById('uo-new').textContent = d.latest_version;
    document.getElementById('uo-date').textContent =
        [d.release_date ? '发布于 ' + d.release_date : '', d.size ? '安装包 ' + d.size : ''].filter(Boolean).join(' · ');
    // 跨版本升级时把中间每个版本的更新日志都列出来（history 由服务端提供，按版本降序）
    let logText = d.changelog || '';
    const hist = Array.isArray(d.history)
        ? d.history.filter(h => h && h.version && uoCmpVer(h.version, ck.current_version) > 0) : [];
    if (hist.length > 1) {
        logText = hist.map(h => `【v${h.version}】${h.release_date ? '（' + h.release_date + '）' : ''}\n${(h.changelog || '').trim()}`).join('\n\n');
    }
    const lv = uoLevel(d, logText);
    const lvEl = document.getElementById('uo-level');
    lvEl.className = 'text-xs font-medium px-2 py-0.5 rounded-full ' + lv.cls;
    lvEl.textContent = lv.text;
    window.UO_CHANGELOG = logText || '';   // 升级完成页要展示「本次更新了什么」
    document.getElementById('uo-changelog-body').textContent = logText || '（本次更新未提供更新说明）';
    document.getElementById('uo-card').classList.remove('hidden');
    document.getElementById('uo-target').textContent = 'v' + d.latest_version;
    document.getElementById('uo-start').classList.add('hidden');   // 已发现新版，隐藏「重新检查」，突出「一键升级」
    document.getElementById('uo-upgrade').classList.remove('hidden');
}

document.getElementById('uo-start').onclick = uoCheck;
uoCheck();   // 进入页面即自动检查：从仪表盘「查看并升级」跳来直接看到版本对比与更新内容

document.getElementById('uo-upgrade').onclick = async () => {
    const btn = document.getElementById('uo-upgrade');
    btn.disabled = true; btn.classList.add('opacity-50');
    const d = UO.target;
    // 优先增量包：当前版本正好匹配某 delta 的 from 时，check 会返回 d.delta
    const useDelta = !!(d.delta && d.delta.download_url && d.delta.hash);
    const dlUrl  = useDelta ? d.delta.download_url : d.download_url;
    const dlHash = useDelta ? d.delta.hash : (d.hash || '');
    const dlSig  = useDelta ? (d.delta.sig || '') : (d.sig || '');
    // 下载校验
    let r = UO.row(`下载并校验 v${d.latest_version}${useDelta ? '（增量包，仅传变化文件）' : ''}…`, 'run');
    const dl = await UO.post('download', { download_url: dlUrl, hash: dlHash, version: d.latest_version, sig: dlSig });
    if (dl.code !== 0) { UO.set(r, 'fail', dl.msg); btn.disabled = false; btn.classList.remove('opacity-50'); return; }
    UO.set(r, 'ok', `校验通过（${(dl.size / 1048576).toFixed(2)} MB${dl.signed ? '，已验 RSA 签名' : ''}${useDelta ? '，增量' : ''}）`);
    // 应用：准备（备份+解压+建清单）
    const fail = (row, msg) => { UO.set(row, 'fail', msg); btn.disabled = false; btn.classList.remove('opacity-50'); };
    r = UO.row('备份并解压安装包…', 'run');
    const pre = await UO.post('apply_prepare');
    if (pre.code !== 0) return fail(r, pre.msg);
    const dbNote = pre.db_backup ? `，数据库已自动备份（${pre.db_backup}）` : '';
    UO.set(r, 'ok', `已备份 config、解压完成（${pre.mode === 'delta' ? '增量' : '全量'}，共 ${pre.total} 个文件，备份: ${pre.backup}${dbNote}）`);
    if (!pre.db_backup) {
        UO.row('数据库自动备份未成功' + (pre.db_backup_error ? `（${pre.db_backup_error}）` : ''), 'fail',
            '升级仍将继续；如本次更新包含数据库迁移，建议先到「数据库管理」手动备份后再执行升级。');
    }
    // 分批覆盖（每批 150 文件，避免共享主机单请求超时）
    const total = pre.total;
    const rr = UO.row(`覆盖程序文件… 0/${total}`, 'run');
    const label = rr.querySelector('.text-gray-800');
    let offset = 0, errCount = 0;
    while (offset < total) {
        const bt = await UO.post('apply_batch', { offset: offset });
        if (bt.code !== 0) return fail(rr, bt.msg || '覆盖失败');
        offset = bt.next;
        errCount += (bt.errors ? bt.errors.length : 0);
        if (label) label.textContent = `覆盖程序文件… ${Math.min(offset, total)}/${total}`;
    }
    UO.set(rr, errCount ? 'fail' : 'ok', `覆盖完成（${total} 个文件${errCount ? '，' + errCount + ' 个失败' : ''}）`);
    // 收尾（删除废弃文件 / 补版本号 / 清理）
    const rf = UO.row('收尾…', 'run');
    const fin = await UO.post('apply_finalize', { note: (window.UO_CHANGELOG || '') });
    if (fin.code === 1) return fail(rf, fin.msg);
    UO.set(rf, fin.code === 0 ? 'ok' : 'fail', fin.msg);
    // 有失败：把未覆盖的文件逐个列出来（名字，非只显示个数），并给手动修复指引
    if (fin.errors && fin.errors.length) {
        const esc = s => String(s).replace(/[&<>]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c]));
        UO.row(`以下 ${fin.errors.length} 个文件未能覆盖（请检查其文件/目录写权限后重试，或从 v${fin.new_version || d.latest_version} 安装包手动上传覆盖）：`,
            'fail',
            fin.errors.map(esc).join('<br>') + '<br><span class="text-gray-400">完整记录已写入 storage/upgrade/upgrade-failures.log</span>');
    }
    // 健康自检失败 = 疑似新旧代码混合状态（部分文件写失败最常见的后果）。
    // 不自动跳转，就地提供「一键回滚」——从 storage/backups/<backup>/files 快照整体恢复。
    if (fin.health && !fin.health.ok) {
        const badFiles = (fin.health.checks || []).filter(c => !c.ok).map(c => c.file).join('、');
        const hr = UO.row('健康自检未通过' + (badFiles ? `（异常文件：${badFiles}）` : ''), 'fail',
            '站点可能处于新旧代码混合状态。可一键回滚到升级前的文件快照，回滚后请重试升级或改用 FTP 手动升级。');
        const rbBtn = document.createElement('button');
        rbBtn.className = 'mt-2 px-4 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg text-sm font-medium';
        rbBtn.innerHTML = '<i class="ti ti-arrow-back-up mr-1"></i>一键回滚到升级前';
        rbBtn.onclick = async () => {
            if (!confirm('确认回滚到升级前的文件状态？（数据库未动过，无需恢复）')) return;
            rbBtn.disabled = true; rbBtn.classList.add('opacity-50');
            const rbRow = UO.row('正在回滚…', 'run');
            const rb = await UO.post('apply_rollback', { backup: fin.backup || '' });
            UO.set(rbRow, rb.code === 0 ? 'ok' : 'fail', rb.msg || '');
            if (rb.code === 0 && rb.health && rb.health.ok) {
                UO.row(`已恢复到 v${rb.health.version || ''}，健康自检通过。`, 'ok');
            }
        };
        hr.querySelector('div').appendChild(rbBtn);
        btn.disabled = false; btn.classList.remove('opacity-50');
        return;
    }
    document.getElementById('uo-migrate').classList.remove('hidden');
    btn.classList.add('hidden');
    const ver = fin.new_version || d.latest_version;
    if (fin.errors && fin.errors.length) {
        UO.row(`程序文件已更新到 v${ver}。`, 'ok', '请先处理上方失败文件，然后点击「下一步：升级数据库」完成最后一步。');
    } else if (fin.pending === 0) {
        // 这一版没有待办迁移——不必把人赶去「数据库升级」页只为看一句「全部已应用」，
        // 改去「升级记录」：那里能看清刚才到底动了什么。
        let sec = 3;
        const rj = UO.row(`升级完成，当前版本 v${ver}。${sec} 秒后前往完成页…`, 'ok', '本次无需数据库迁移。');
        const lbl = rj.querySelector('.text-gray-800');
        const timer = setInterval(() => {
            sec--;
            if (lbl) lbl.textContent = `升级完成，当前版本 v${ver}。${sec} 秒后前往完成页…`;
            if (sec <= 0) { clearInterval(timer); location.href = 'upgrade.php?tab=welcome'; }
        }, 1000);
    } else {
        // 有迁移待跑（或数不出来）就自动前往，避免停在「文件已升、库未升」的中间态
        //（该状态下软删除等写操作会失效）。
        let sec = 5;
        const what = fin.pending > 0 ? `${fin.pending} 项数据库更新` : '数据库升级';
        const rj = UO.row(`程序文件已更新到 v${ver}。${sec} 秒后自动前往完成 ${what}…`, 'ok', '');
        const lbl = rj.querySelector('.text-gray-800');
        const timer = setInterval(() => {
            sec--;
            if (lbl) lbl.textContent = `程序文件已更新到 v${ver}。${sec} 秒后自动前往完成 ${what}…`;
            if (sec <= 0) { clearInterval(timer); location.href = 'upgrade.php'; }
        }, 1000);
    }
};
</script>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
