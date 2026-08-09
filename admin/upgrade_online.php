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
require_once ROOT_PATH . '/includes/security.php';   // zipUnsafeEntry（zip-slip 防护）；admin 页不走 init.php，须显式引入
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// License 验签所需的公钥函数；旧版本 auth.php 不会自动加载它，这里按需引入，
// 使本升级器在被引导进旧版本站点时也能强制 RSA 验签。
if (!function_exists('license_pubkey') && is_file(ROOT_PATH . '/includes/License.php')) {
    require_once ROOT_PATH . '/includes/License.php';
}

const UO_UPDATE_SERVER = 'https://update.yikaicms.com';

/**
 * 升级时永不覆盖、也永不删除的路径（相对站点根）。
 *
 * 后三项是站点覆盖层：各站的模板/配置/文案/逻辑定制都放在这里，是「升级安全的
 * per-site 定制」的立身之本。它们本就被 gitignore、不会进发行包，所以此前不写进
 * 排除表也没出过事——但那是「碰巧安全」：哪天包里出现同名文件，或增量包的删除
 * 清单扫到这些路径，客户的定制就会被静默抹掉。写进契约，不靠巧合。
 */
const UO_EXCLUDES = [
    'config/config.php', 'config/installed.lock', 'installed.lock',
    'storage', 'uploads', 'install',
    'overrides', 'config/overrides.php', 'lang/overrides',
];

/**
 * 相对路径是否落在受保护路径内（自身或其任一层父目录命中 UO_EXCLUDES）。
 *
 * 不能只比完整路径 + 首段：那样 `lang/overrides/zh-CN.php` 会漏网——完整路径不等于
 * `lang/overrides`，首段是 `lang` 也不在表里，于是客户改的文案会被增量包的删除清单抹掉。
 */
function uo_is_protected(string $rel): bool
{
    $rel = trim(str_replace('\\', '/', $rel), '/');
    if ($rel === '') return false;

    $parts = explode('/', $rel);
    for ($i = count($parts); $i > 0; $i--) {
        if (in_array(implode('/', array_slice($parts, 0, $i)), UO_EXCLUDES, true)) {
            return true;
        }
    }
    return false;
}

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
        if (uo_is_protected($rel)) continue;
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

/**
 * 列出 zip 内 $prefix 下的所有「文件」条目，返回 [['name'=>zip内条目名, 'rel'=>目标相对路径], ...]。
 * 套用 UO_EXCLUDES；跳过目录条目与越界路径。不解压——供逐条流式写入用（规避共享主机上 extractTo 失败/挂起）。
 */
function uo_zip_entries(ZipArchive $zip, string $prefix): array
{
    $out = [];
    $plen = strlen($prefix);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false) continue;
        if ($prefix !== '' && strncmp($name, $prefix, $plen) !== 0) continue;
        $rel = $prefix === '' ? $name : substr($name, $plen);
        if ($rel === '' || substr($rel, -1) === '/') continue;              // 目录条目
        if ($rel[0] === '/' || strpos($rel, '..') !== false) continue;      // 越界防护
        if (uo_is_protected($rel)) continue;
        $out[] = ['name' => $name, 'rel' => $rel];
    }
    return $out;
}

/** 分批覆盖的进度状态文件路径 */
function uo_state_file(): string { return uo_dir() . '/apply_state.json'; }

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

    // ---- 1) 环境预检 ----
    if ($action === 'precheck') {
        if (!is_dir(uo_dir())) {
            @mkdir(uo_dir(), 0755, true);
        }
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
        if (!is_dir(uo_dir())) {
            @mkdir(uo_dir(), 0755, true);
        }
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

    // ---- 4a) 准备：备份 config + 校验结构 + 建 zip 条目清单（不解压，写状态文件）----
    //   不用 extractTo（共享主机上常失败/挂起）；后续 batch 从 zip 逐条流式写入目标。
    if ($action === 'apply_prepare') {
        $pkg = uo_dir() . '/package.zip';
        if (!is_file($pkg)) uo_json(['code' => 1, 'msg' => '未找到已下载的安装包，请先执行下载']);
        if (!class_exists('ZipArchive')) uo_json(['code' => 1, 'msg' => '缺少 ZipArchive 扩展']);

        // 备份 config.php + 记录旧版本（轻量、稳妥；完整代码回滚依赖主机备份）
        $oldVer = defined('CMS_VERSION') ? CMS_VERSION : 'unknown';
        $bakDir = ROOT_PATH . '/storage/backups/pre-upgrade-' . $oldVer . '-' . date('YmdHis');
        if (!is_dir($bakDir)) {
            @mkdir($bakDir, 0755, true);
        }
        @copy(ROOT_PATH . '/config/config.php', $bakDir . '/config.php');
        @file_put_contents($bakDir . '/INFO.txt', "升级前版本: $oldVer\n时间: " . date('Y-m-d H:i:s') . "\n");

        $zip = new ZipArchive();
        if ($zip->open($pkg) !== true) uo_json(['code' => 1, 'msg' => '安装包打开失败']);
        // zip-slip 防护：条目名越界则中止
        $unsafe = zipUnsafeEntry($zip);
        if ($unsafe !== null) { $zip->close(); uo_json(['code' => 1, 'msg' => '安装包含非法路径条目，已中止：' . $unsafe]); }

        // 判定增量 / 全量 + 定位包内前缀（不解压，只读条目名/manifest）
        $deleted = []; $from = ''; $to = '';
        $manifestRaw = $zip->getFromName('.delta-manifest.json');
        if ($manifestRaw !== false) {
            $manifest = json_decode((string) $manifestRaw, true);
            if (!is_array($manifest)) { $zip->close(); uo_json(['code' => 1, 'msg' => '增量包 manifest 解析失败，已中止，未改动任何文件']); }
            $mode = 'delta'; $prefix = 'payload/';
            $deleted = (array) ($manifest['deleted'] ?? []);
            $from = (string) ($manifest['from'] ?? '');
            $to   = (string) ($manifest['to'] ?? '');
        } else {
            // 全量包通常是单层 yikaicms-vX.Y.Z/ 目录；找含 index.php 的那层作前缀
            $prefix = '';
            if ($zip->locateName('index.php') === false) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = $zip->getNameIndex($i);
                    if ($n !== false && preg_match('#^([^/]+)/index\.php$#', $n, $mm)) { $prefix = $mm[1] . '/'; break; }
                }
            }
            if ($zip->locateName($prefix . 'index.php') === false || $zip->locateName($prefix . 'includes/functions.php') === false) {
                $zip->close();
                uo_json(['code' => 1, 'msg' => '安装包结构异常（缺 index.php / includes），已中止，未改动任何文件']);
            }
            $mode = 'full';
        }

        $entries = uo_zip_entries($zip, $prefix);
        $zip->close();
        if (empty($entries)) uo_json(['code' => 1, 'msg' => '安装包内无可覆盖文件，已中止']);
        $state = [
            'mode' => $mode, 'pkg' => $pkg, 'prefix' => $prefix, 'entries' => $entries, 'deleted' => $deleted,
            'backup' => basename($bakDir), 'total' => count($entries), 'done' => 0,
            'errors' => [], 'from' => $from, 'to' => $to,
        ];
        @file_put_contents(uo_state_file(), json_encode($state, JSON_UNESCAPED_UNICODE));
        uo_json(['code' => 0, 'mode' => $mode, 'total' => count($entries), 'backup' => basename($bakDir)]);
    }

    // ---- 4b) 分批覆盖：从 offset 起，从 zip 逐条读出并直接写入目标，返回下一个 offset ----
    if ($action === 'apply_batch') {
        $sf = uo_state_file();
        if (!is_file($sf)) uo_json(['code' => 1, 'msg' => '升级状态丢失，请重新开始']);
        $state = json_decode((string) @file_get_contents($sf), true);
        if (!is_array($state) || !isset($state['entries'], $state['pkg'])) uo_json(['code' => 1, 'msg' => '升级状态损坏，请重新开始']);
        $entries = $state['entries'];
        $total = count($entries);
        $offset = max(0, (int) ($_POST['offset'] ?? 0));
        $batch = 80;                           // 每批条目数；从 zip 读+写，单请求足够快
        $end = min($total, $offset + $batch);
        $zip = new ZipArchive();
        if ($zip->open((string) $state['pkg']) !== true) uo_json(['code' => 1, 'msg' => '安装包打开失败']);
        $copied = 0; $errors = [];
        for ($i = $offset; $i < $end; $i++) {
            $rel  = (string) $entries[$i]['rel'];
            $name = (string) $entries[$i]['name'];
            $data = $zip->getFromName($name);
            if ($data === false) { $errors[] = "读取失败: $rel"; continue; }
            $d = ROOT_PATH . '/' . $rel;
            $dir = dirname($d);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) { $errors[] = "建目录失败: $rel"; continue; }
            if (@file_put_contents($d, $data) !== false) $copied++; else $errors[] = "写入失败: $rel";
        }
        $zip->close();
        $state['done'] = (int) ($state['done'] ?? 0) + $copied;
        $state['errors'] = array_slice(array_merge($state['errors'] ?? [], $errors), 0, 50);
        @file_put_contents($sf, json_encode($state, JSON_UNESCAPED_UNICODE));
        uo_json(['code' => 0, 'copied' => $copied, 'next' => $end, 'total' => $total, 'errors' => $errors]);
    }

    // ---- 4c) 收尾：删除废弃文件（delta）+ 补 config 版本行 + 清理临时 ----
    if ($action === 'apply_finalize') {
        $sf = uo_state_file();
        if (!is_file($sf)) uo_json(['code' => 1, 'msg' => '升级状态丢失，请重新开始']);
        $state = json_decode((string) @file_get_contents($sf), true);
        if (!is_array($state)) uo_json(['code' => 1, 'msg' => '升级状态损坏，请重新开始']);

        // 删除清单（仅增量有）：拒绝绝对路径/越界/受保护路径，仅删普通文件
        $deletedCount = 0;
        foreach ((array) ($state['deleted'] ?? []) as $rel) {
            $rel = (string) $rel;
            if ($rel === '' || $rel[0] === '/' || strpos($rel, '..') !== false) continue;
            if (uo_is_protected($rel)) continue;
            if (is_file(ROOT_PATH . '/' . $rel) && @unlink(ROOT_PATH . '/' . $rel)) $deletedCount++;
        }
        $patch = uo_patch_config_version();

        // 清理临时（本版本不再产生 extracted/ 目录；顺手清理旧版本可能残留的）
        uo_rrmdir(uo_dir() . '/extracted');
        @unlink(uo_dir() . '/package.zip');
        @unlink($sf);

        $errors = $state['errors'] ?? [];
        $copied = (int) ($state['done'] ?? $state['total'] ?? 0);
        $mode   = $state['mode'] ?? 'full';
        // 失败清单写入持久日志（收尾已删状态文件，名单本会丢失）；供事后排查/手动补文件。
        if (!empty($errors)) {
            $verPair = $mode === 'delta' ? "{$state['from']}→{$state['to']}" : ('→' . ($state['to'] ?? ''));
            @file_put_contents(
                uo_dir() . '/upgrade-failures.log',
                '[' . date('Y-m-d H:i:s') . "] {$verPair} 覆盖 {$copied}，失败 " . count($errors) . "：\n  - " . implode("\n  - ", $errors) . "\n",
                FILE_APPEND
            );
        }
        $failNote = empty($errors) ? '' : ('，失败 ' . count($errors) . '：' . implode('; ', array_slice($errors, 0, 10)));
        try { adminLog('upgrade', 'online_apply', ($mode === 'delta' ? "增量升级 {$state['from']}→{$state['to']}" : '在线升级') . "：覆盖 {$copied} / 删 {$deletedCount}，config补丁:{$patch}{$failNote}"); } catch (\Throwable $e) {}

        $newVer = '';
        $vf = @file_get_contents(ROOT_PATH . '/config/version.php');
        if ($vf && preg_match("/CMS_VERSION'\\s*,\\s*'([^']+)'/", $vf, $m)) $newVer = $m[1];

        // 还有几条迁移要跑？多数版本一条都没有，那就不该把人赶去「数据库升级」页
        // 只为看一句「全部已应用」。注意这里读的是刚覆盖上去的**新** migrations/。
        $pending = null;
        try {
            require_once ROOT_PATH . '/includes/Migrator.php';
            $pending = 0;
            foreach (Migrator::loadAll() as $mg) {
                if (!Migrator::isApplied($mg)) $pending++;
            }
        } catch (Throwable $e) {
            $pending = null;   // 数不出来就按老路子跳，宁可多跳一趟也别漏掉迁移
        }

        // 记下这次升级的落点，供「升级完成」页展示（版本号、时间、更新说明）。
        // 说明文字来自更新服务器，渲染时一律 e() 转义。
        try {
            settingModel()->set('last_upgrade_from', (string) ($state['from'] ?? ''), 'system');
            settingModel()->set('last_upgrade_to', $newVer ?: (string) ($state['to'] ?? ''), 'system');
            settingModel()->set('last_upgrade_at', (string) time(), 'system');
            settingModel()->set('last_upgrade_note', mb_substr(trim((string) post('note')), 0, 4000), 'system');
        } catch (Throwable $e) {
            // 记不上不影响升级本身
        }

        uo_json([
            'code'    => empty($errors) ? 0 : 2,
            'pending' => $pending,
            'msg'     => (empty($errors) ? "文件更新完成，共覆盖 $copied 个文件" : "部分文件未能覆盖（$copied 成功，" . count($errors) . " 失败）") . ($deletedCount ? "，删除 $deletedCount 个" : ''),
            'mode'    => $mode,
            'copied'  => $copied,
            'deleted' => $deletedCount,
            'errors'  => array_slice($errors, 0, 20),
            'patch'   => $patch,
            'new_version' => $newVer,
            'backup'  => $state['backup'] ?? '',
        ]);
    }

    uo_json(['code' => 1, 'msg' => '未知操作']);
}

// ============================================================
// 页面
// ============================================================
$pageTitle = __('upgrade_online');
$currentMenu = 'upgrade';   // 与「系统升级」共用菜单（升级页两个标签之一）
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
    const dlSig  = useDelta ? '' : (d.sig || '');   // 增量包只校验 SHA256（RSA 签名仅全量包）
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
    UO.set(r, 'ok', `已备份 config、解压完成（${pre.mode === 'delta' ? '增量' : '全量'}，共 ${pre.total} 个文件，备份: ${pre.backup}）`);
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
