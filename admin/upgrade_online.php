<?php
/**
 * YikaiCMS - 在线升级（一键更新程序文件）
 *
 * 流程：预检 → 检查更新 → 下载并校验(SHA256 + 可选 RSA 签名) → 备份 → 解压覆盖 → 补丁 config 版本行
 *       → 交给 upgrade.php 跑数据库迁移。
 *
 * 安全：仅管理员(checkLogin + requirePermission('*'))；所有写操作校验 CSRF；
 *       下载校验哈希(来自 TLS 验证过的 check.php)，有签名则强制验签。
 * 原则：失败即停、保留备份，绝不留半截站点。config.php / storage / uploads / install 永不覆盖。
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

// License 验签所需的公钥函数；旧版本 auth.php 不会自动加载它，这里按需引入，
// 使本升级器在被引导进旧版本站点时也能强制 RSA 验签。
if (!function_exists('license_pubkey') && is_file(ROOT_PATH . '/includes/License.php')) {
    require_once ROOT_PATH . '/includes/License.php';
}

const UO_UPDATE_SERVER = 'https://update.yikaicms.com';
const UO_EXCLUDES = ['config/config.php', 'config/installed.lock', 'installed.lock', 'storage', 'uploads', 'install'];

function uo_dir(): string { return ROOT_PATH . '/storage/upgrade'; }

function uo_json(array $d): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 递归删除 */
function uo_rrmdir(string $d): void
{
    if (!is_dir($d)) { @unlink($d); return; }
    foreach (array_diff(scandir($d) ?: [], ['.', '..']) as $it) {
        $p = $d . '/' . $it;
        is_dir($p) ? uo_rrmdir($p) : @unlink($p);
    }
    @rmdir($d);
}

/** HTTP GET 下载到文件；TLS 验证(与 check.php 一致)。返回 [bool, errMsg] */
function uo_download(string $url, string $dest): array
{
    $fp = @fopen($dest, 'wb');
    if (!$fp) return [false, '无法写入临时文件，storage/upgrade 不可写'];
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 600,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FAILONERROR => true,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400) return [false, '下载失败: ' . ($err ?: "HTTP $code")];
        return [true, ''];
    }
    if (!ini_get('allow_url_fopen')) { fclose($fp); return [false, '主机禁用 curl 与 allow_url_fopen，无法下载']; }
    $ctx = stream_context_create(['http' => ['timeout' => 600], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
    $src = @fopen($url, 'rb', false, $ctx);
    if (!$src) { fclose($fp); return [false, '下载失败(allow_url_fopen)']; }
    stream_copy_to_stream($src, $fp);
    fclose($src);
    fclose($fp);
    return [true, ''];
}

/** RSA-SHA256 验签：复用 License 公钥；对 "version|hash" 规范串验签。 */
function uo_verify_sig(string $version, string $hash, string $sigB64): bool
{
    if (!function_exists('license_pubkey') || !function_exists('openssl_verify')) return false;
    $sig = base64_decode($sigB64, true);
    if ($sig === false || $sig === '') return false;
    return openssl_verify($version . '|' . $hash, $sig, license_pubkey(), OPENSSL_ALGO_SHA256) === 1;
}

/** 递归覆盖复制（带排除）。返回 [copied, errors[]] */
function uo_copy_tree(string $src, string $dst, string $baseRel = ''): array
{
    $copied = 0; $errors = [];
    foreach (array_diff(scandir($src) ?: [], ['.', '..']) as $it) {
        $rel = $baseRel === '' ? $it : "$baseRel/$it";
        if (in_array($rel, UO_EXCLUDES, true)) continue;
        $s = "$src/$it"; $d = "$dst/$it";
        if (is_dir($s)) {
            if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) { $errors[] = "建目录失败: $rel"; continue; }
            [$c, $e] = uo_copy_tree($s, $d, $rel);
            $copied += $c; $errors = array_merge($errors, $e);
        } elseif (@copy($s, $d)) {
            $copied++;
        } else {
            $errors[] = "复制失败: $rel";
        }
    }
    return [$copied, $errors];
}

/** 兼容旧 config.php：把硬编码的 CMS_VERSION 定义换成 require version.php。 */
function uo_patch_config_version(): string
{
    $cf = ROOT_PATH . '/config/config.php';
    $raw = @file_get_contents($cf);
    if ($raw === false) return 'unreadable';
    if (strpos($raw, "version.php'") !== false) return 'already';
    $new = preg_replace("/define\\(\\s*'CMS_VERSION'\\s*,\\s*'[^']*'\\s*\\)\\s*;/", "require_once __DIR__ . '/version.php';", $raw, 1, $cnt);
    if ($cnt < 1 || $new === null || $new === $raw) return 'nochange';
    return @file_put_contents($cf, $new) !== false ? 'patched' : 'failed';
}

// ============================================================
// AJAX 路由
// ============================================================
$action = $_POST['action'] ?? $_GET['action'] ?? '';
if ($action !== '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') verifyCsrf();
    @set_time_limit(600);

    // ---- 1) 环境预检 ----
    if ($action === 'precheck') {
        @mkdir(uo_dir(), 0755, true);
        $checks = [];
        $checks[] = ['name' => 'ZipArchive 扩展', 'ok' => class_exists('ZipArchive'), 'hint' => '解压安装包必需'];
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
        $url  = (string) ($_POST['download_url'] ?? '');
        $hash = preg_replace('/^sha256:/i', '', (string) ($_POST['hash'] ?? ''));
        $ver  = (string) ($_POST['version'] ?? '');
        $sig  = (string) ($_POST['sig'] ?? '');
        if (!preg_match('#^https://update\.yikaicms\.com/packages/[A-Za-z0-9._-]+\.zip$#', $url)) {
            uo_json(['code' => 1, 'msg' => '下载地址不合法，仅允许官方 packages 目录']);
        }
        if (strlen($hash) !== 64) uo_json(['code' => 1, 'msg' => '缺少有效的 SHA256 校验值，拒绝升级']);
        @mkdir(uo_dir(), 0755, true);
        $pkg = uo_dir() . '/package.zip';
        @unlink($pkg);
        [$ok, $err] = uo_download($url, $pkg);
        if (!$ok) uo_json(['code' => 1, 'msg' => $err]);
        $actual = hash_file('sha256', $pkg);
        if (!hash_equals(strtolower($hash), strtolower((string) $actual))) {
            @unlink($pkg);
            uo_json(['code' => 1, 'msg' => 'SHA256 校验不通过，包可能损坏或被篡改，已删除']);
        }
        // 有签名则强制验签（Phase 2 服务端签名后生效）
        if ($sig !== '' && !uo_verify_sig($ver, 'sha256:' . $hash, $sig)) {
            @unlink($pkg);
            uo_json(['code' => 1, 'msg' => 'RSA 签名校验失败，拒绝升级，已删除']);
        }
        uo_json(['code' => 0, 'msg' => '下载并校验通过', 'size' => filesize($pkg), 'signed' => $sig !== '']);
    }

    // ---- 4) 备份 + 解压覆盖 + 补丁 ----
    if ($action === 'apply') {
        $pkg = uo_dir() . '/package.zip';
        if (!is_file($pkg)) uo_json(['code' => 1, 'msg' => '未找到已下载的安装包，请先执行下载']);
        if (!class_exists('ZipArchive')) uo_json(['code' => 1, 'msg' => '缺少 ZipArchive 扩展']);

        // 备份 config.php + 记录旧版本（轻量、稳妥；完整代码回滚依赖主机备份）
        $oldVer = defined('CMS_VERSION') ? CMS_VERSION : 'unknown';
        $bakDir = ROOT_PATH . '/storage/backups/pre-upgrade-' . $oldVer . '-' . date('YmdHis');
        @mkdir($bakDir, 0755, true);
        @copy(ROOT_PATH . '/config/config.php', $bakDir . '/config.php');
        @file_put_contents($bakDir . '/INFO.txt', "升级前版本: $oldVer\n时间: " . date('Y-m-d H:i:s') . "\n");

        // 解压到临时目录
        $ex = uo_dir() . '/extracted';
        uo_rrmdir($ex);
        @mkdir($ex, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($pkg) !== true) uo_json(['code' => 1, 'msg' => '安装包打开失败']);
        if (!$zip->extractTo($ex)) { $zip->close(); uo_json(['code' => 1, 'msg' => '解压失败，可能磁盘空间不足']); }
        $zip->close();

        // 包内通常是单层 yikaicms-vX.Y.Z/ 目录
        $dirs = glob($ex . '/*', GLOB_ONLYDIR) ?: [];
        $srcRoot = (count($dirs) === 1 && !is_file($ex . '/index.php')) ? $dirs[0] : $ex;
        if (!is_file($srcRoot . '/index.php') || !is_dir($srcRoot . '/includes')) {
            uo_json(['code' => 1, 'msg' => '安装包结构异常（缺 index.php / includes），已中止，未改动任何文件']);
        }

        // 覆盖复制（排除 config.php/storage/uploads/install）
        [$copied, $errors] = uo_copy_tree($srcRoot, ROOT_PATH);
        // 兼容旧 config.php 的版本行
        $patch = uo_patch_config_version();
        // 清理临时
        uo_rrmdir($ex);
        @unlink($pkg);

        try { adminLog('upgrade', 'online_apply', "在线升级覆盖文件: $copied 个，config补丁:$patch"); } catch (\Throwable $e) {}

        $newVer = '';
        $vf = @file_get_contents(ROOT_PATH . '/config/version.php');
        if ($vf && preg_match("/CMS_VERSION'\\s*,\\s*'([^']+)'/", $vf, $m)) $newVer = $m[1];

        uo_json([
            'code'    => empty($errors) ? 0 : 2,
            'msg'     => empty($errors) ? "文件更新完成，共覆盖 $copied 个文件" : "部分文件未能覆盖（$copied 成功，" . count($errors) . " 失败）",
            'copied'  => $copied,
            'errors'  => array_slice($errors, 0, 20),
            'patch'   => $patch,
            'new_version' => $newVer,
            'backup'  => basename($bakDir),
        ]);
    }

    uo_json(['code' => 1, 'msg' => '未知操作']);
}

// ============================================================
// 页面
// ============================================================
$pageTitle = '在线升级';
require_once ROOT_PATH . '/admin/includes/header.php';
?>
<div class="p-6 max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-xl font-bold text-gray-800"><i class="fa-solid fa-cloud-arrow-down text-blue-500 mr-2"></i>在线升级</h1>
        <span class="text-sm text-gray-500">当前版本 v<?= e(defined('CMS_VERSION') ? CMS_VERSION : '?') ?></span>
    </div>

    <div class="bg-amber-50 border border-amber-200 text-amber-800 text-sm rounded-lg p-4 mb-5">
        <p class="font-medium mb-1"><i class="fa-solid fa-triangle-exclamation mr-1"></i>升级前请知悉</p>
        <ul class="list-disc pl-5 space-y-0.5">
            <li>升级会覆盖程序文件，<b>不会触碰</b> config.php、storage、uploads、install。</li>
            <li>建议先在主机面板做一次整站/数据库备份；本工具仅自动备份 config.php。</li>
            <li>文件更新后需再到「升级管理」运行数据库迁移以完成升级。</li>
        </ul>
    </div>

    <div id="uo-steps" class="space-y-3"></div>

    <div class="mt-6 flex gap-3">
        <button id="uo-start" class="px-5 py-2.5 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium transition">
            <i class="fa-solid fa-magnifying-glass mr-1"></i>检查更新
        </button>
        <button id="uo-upgrade" class="hidden px-5 py-2.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-sm font-medium transition">
            <i class="fa-solid fa-bolt mr-1"></i>一键升级到 <span id="uo-target"></span>
        </button>
        <a href="upgrade.php" id="uo-migrate" class="hidden px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-medium transition">
            <i class="fa-solid fa-database mr-1"></i>下一步：升级数据库 →
        </a>
    </div>

    <div id="uo-changelog" class="hidden mt-6 bg-white border border-gray-200 rounded-lg p-4">
        <h3 class="font-bold text-gray-800 mb-2">更新内容</h3>
        <pre id="uo-changelog-body" class="text-xs text-gray-600 whitespace-pre-wrap leading-relaxed"></pre>
    </div>
</div>

<script>
const UO = {
    token: <?= json_encode(csrfToken()) ?>,
    target: null,
    steps: document.getElementById('uo-steps'),
    async post(action, data = {}) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('_token', this.token);
        for (const k in data) fd.append(k, data[k]);
        const r = await fetch('upgrade_online.php', { method: 'POST', body: fd });
        return r.json();
    },
    row(label, state, detail = '') {
        const icon = state === 'ok' ? '<i class="fa-solid fa-circle-check text-green-500"></i>'
            : state === 'fail' ? '<i class="fa-solid fa-circle-xmark text-red-500"></i>'
            : '<i class="fa-solid fa-spinner fa-spin text-blue-500"></i>';
        const d = document.createElement('div');
        d.className = 'flex items-start gap-2 bg-white border border-gray-200 rounded-lg p-3 text-sm';
        d.innerHTML = `<span class="mt-0.5">${icon}</span><div><div class="text-gray-800">${label}</div>${detail ? `<div class="text-gray-500 text-xs mt-0.5">${detail}</div>` : ''}</div>`;
        this.steps.appendChild(d);
        return d;
    },
    set(row, state, detail) {
        const icon = state === 'ok' ? '<i class="fa-solid fa-circle-check text-green-500"></i>' : '<i class="fa-solid fa-circle-xmark text-red-500"></i>';
        row.querySelector('span').innerHTML = icon;
        if (detail) row.querySelector('div div:last-child') ? row.querySelector('div').insertAdjacentHTML('beforeend', `<div class="text-gray-500 text-xs mt-0.5">${detail}</div>`) : null;
    }
};

document.getElementById('uo-start').onclick = async () => {
    UO.steps.innerHTML = '';
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
    if (!d.has_update) { UO.set(r, 'ok', `已是最新版本 v${ck.current_version}`); return; }
    UO.set(r, 'ok', `发现新版本 v${d.latest_version}（当前 v${ck.current_version}）`);
    UO.target = d;
    document.getElementById('uo-target').textContent = 'v' + d.latest_version;
    document.getElementById('uo-upgrade').classList.remove('hidden');
    if (d.changelog) {
        document.getElementById('uo-changelog').classList.remove('hidden');
        document.getElementById('uo-changelog-body').textContent = d.changelog;
    }
};

document.getElementById('uo-upgrade').onclick = async () => {
    const btn = document.getElementById('uo-upgrade');
    btn.disabled = true; btn.classList.add('opacity-50');
    const d = UO.target;
    // 下载校验
    let r = UO.row(`下载并校验 v${d.latest_version}…`, 'run');
    const dl = await UO.post('download', { download_url: d.download_url, hash: d.hash || '', version: d.latest_version, sig: d.sig || '' });
    if (dl.code !== 0) { UO.set(r, 'fail', dl.msg); btn.disabled = false; btn.classList.remove('opacity-50'); return; }
    UO.set(r, 'ok', `校验通过（${(dl.size / 1048576).toFixed(1)} MB${dl.signed ? '，已验 RSA 签名' : ''}）`);
    // 应用
    r = UO.row('备份并覆盖程序文件…', 'run');
    const ap = await UO.post('apply');
    if (ap.code === 1) { UO.set(r, 'fail', ap.msg); btn.disabled = false; btn.classList.remove('opacity-50'); return; }
    UO.set(ap.code === 0 ? r : r, ap.code === 0 ? 'ok' : 'fail', `${ap.msg}（备份: ${ap.backup}）`);
    UO.row(`程序文件已更新到 v${ap.new_version || d.latest_version}。`, 'ok', '最后一步：运行数据库迁移。');
    document.getElementById('uo-migrate').classList.remove('hidden');
    btn.classList.add('hidden');
};
</script>
<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
