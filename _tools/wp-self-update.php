<?php
/**
 * WordPress 自助升级工具（单文件版）
 *
 * 用法：
 *   1. 上传本文件到 WP 站点根目录（与 wp-config.php 同级）
 *   2. 上传 WordPress 官方 zip 到同目录，如 wordpress-6.9.4.zip
 *   3. 浏览器访问 https://你的站点/wp-self-update.php
 *      - 首次访问：会要求设置一个访问 token（写入同目录 .wp-update-token.php）
 *      - 之后访问：URL 带 ?token=xxx
 *   4. 选择 zip → 一键升级 → 完成后访问 /wp-admin/upgrade.php 跑 DB schema
 *
 * 安全：
 *   - 排除清单：wp-content/、wp-config.php、.htaccess、.user.ini、本脚本本身
 *   - 升级前自动备份 wp-admin、wp-includes、根 *.php → wp-update-backups/<timestamp>/
 *   - 升级过程写 .maintenance（WP 维护模式），完成后删除
 *   - token 独立于 WP，WP 半残也能跑此工具
 *
 * 兼容：PHP 7.4+ / WordPress 5.x+
 */

declare(strict_types=1);
session_start();
set_time_limit(0);
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');

define('WPSU_ROOT',     __DIR__);
define('WPSU_TOKEN_FILE', __DIR__ . '/.wp-update-token.php');
define('WPSU_BACKUP_DIR', __DIR__ . '/wp-update-backups');
define('WPSU_TMP_DIR',    __DIR__ . '/wp-update-tmp');
define('WPSU_SELF',       basename(__FILE__));

// 升级时绝对不能动的根级路径（黑名单）
$EXCLUDE = [
    'wp-content',        // 主题/插件/上传，整个保留
    'wp-config.php',
    'wp-config-sample.php', // 防止覆盖自定义 sample
    '.htaccess',
    '.user.ini',
    '.wp-update-token.php',
    'wp-update-backups',
    'wp-update-tmp',
    WPSU_SELF,           // 自己
];

// ─── 1. Token 鉴权 ──────────────────────────────────────────
$savedToken = file_exists(WPSU_TOKEN_FILE) ? trim(@file_get_contents(WPSU_TOKEN_FILE)) : '';
// 存储格式：首行 PHP 退出标签 + 第二行 "TOKEN xxx"，直接 HTTP 访问该文件会立刻 exit
$savedToken = preg_replace('/^.*\n/', '', $savedToken);
$savedToken = preg_replace('/^.*?\s/', '', trim($savedToken));

if ($savedToken === '') {
    // 首次访问：要求设 token
    if (($_POST['action'] ?? '') === 'set_token') {
        $newToken = trim((string)($_POST['token'] ?? ''));
        if (strlen($newToken) < 8) {
            page_error('Token 至少 8 位');
        }
        $blob = "<?php exit; ?>\nTOKEN " . $newToken;
        file_put_contents(WPSU_TOKEN_FILE, $blob);
        @chmod(WPSU_TOKEN_FILE, 0600);
        page_redirect('?token=' . urlencode($newToken));
    }
    page_setup_token();
}

$reqToken = (string)($_GET['token'] ?? $_POST['token'] ?? '');
if (!hash_equals($savedToken, $reqToken)) {
    page_login();
}

// ─── 2. 路由 ────────────────────────────────────────────────
$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'list');

switch ($action) {
    case 'upgrade':
        do_upgrade((string)($_POST['zip'] ?? ''), $reqToken, $EXCLUDE);
        break;
    case 'list':
    default:
        page_list($reqToken);
}
exit;

// ─── 3. 升级主流程 ──────────────────────────────────────────
function do_upgrade(string $zipName, string $token, array $exclude): void
{
    page_header('升级中…');

    $log = function(string $msg) {
        echo '<div class="log">' . htmlspecialchars($msg) . '</div>';
        flush();
        if (function_exists('ob_flush')) @ob_flush();
    };

    // 校验 zip 文件名（防穿越）
    if (!preg_match('/^wordpress[a-z0-9._-]*\.zip$/i', $zipName)) {
        page_error('非法的 zip 文件名：' . htmlspecialchars($zipName));
    }
    $zipPath = WPSU_ROOT . '/' . $zipName;
    if (!is_file($zipPath)) {
        page_error('ZIP 文件不存在：' . htmlspecialchars($zipName));
    }

    $log("✓ 待升级 ZIP：{$zipName}  (" . round(filesize($zipPath)/1024/1024, 2) . " MB)");

    // 完整性测试
    $zip = new ZipArchive();
    $openCode = $zip->open($zipPath, ZipArchive::CHECKCONS);
    if ($openCode !== true) {
        page_error('ZIP 损坏或无法打开（错误码 ' . $openCode . '）');
    }
    $log("✓ ZIP 完整性 OK，含 {$zip->numFiles} 个条目");

    // 探测顶层目录（WP 官方 zip 顶层是 wordpress/）
    $firstName = $zip->getNameIndex(0) ?: '';
    $topDir = strstr($firstName, '/', true) ?: '';
    if ($topDir === '' || $topDir === false) {
        $zip->close();
        page_error('ZIP 顶层结构异常（缺 wordpress/ 目录）');
    }
    $log("✓ ZIP 顶层目录：{$topDir}/");

    // ── 备份 ─────────────────────────────────────────────
    $ts = date('Ymd_His');
    $backupDir = WPSU_BACKUP_DIR . '/' . $ts;
    if (!is_dir(WPSU_BACKUP_DIR)) {
        mkdir(WPSU_BACKUP_DIR, 0755, true);
        // 备份目录拒访
        file_put_contents(WPSU_BACKUP_DIR . '/.htaccess', "Order deny,allow\nDeny from all\n");
    }
    if (!mkdir($backupDir, 0755, true)) {
        page_error('备份目录创建失败：' . $backupDir);
    }
    $log("→ 备份到：" . str_replace(WPSU_ROOT, '', $backupDir));

    // 备份 wp-admin、wp-includes、根 .php 文件
    foreach (['wp-admin', 'wp-includes'] as $d) {
        $src = WPSU_ROOT . '/' . $d;
        if (is_dir($src)) {
            rcopy($src, $backupDir . '/' . $d);
            $log("  ✓ 备份 {$d}/");
        }
    }
    foreach (glob(WPSU_ROOT . '/*.php') ?: [] as $f) {
        $name = basename($f);
        if (in_array($name, $exclude, true)) continue;
        copy($f, $backupDir . '/' . $name);
    }
    $log("  ✓ 备份根目录 *.php");

    // ── 维护模式 ─────────────────────────────────────────
    $mainFile = WPSU_ROOT . '/.maintenance';
    file_put_contents($mainFile, '<?php $upgrading = ' . time() . ';');
    $log("→ 进入维护模式（.maintenance）");

    // ── 解压 ─────────────────────────────────────────────
    if (is_dir(WPSU_TMP_DIR)) rrmdir(WPSU_TMP_DIR);
    mkdir(WPSU_TMP_DIR, 0755, true);
    if (!$zip->extractTo(WPSU_TMP_DIR)) {
        $zip->close();
        @unlink($mainFile);
        page_error('解压失败');
    }
    $zip->close();
    $log("✓ 解压到临时目录 wp-update-tmp/{$topDir}/");

    $srcRoot = WPSU_TMP_DIR . '/' . $topDir;
    if (!is_dir($srcRoot)) {
        @unlink($mainFile);
        page_error("解压后未找到 {$topDir}/ 目录");
    }

    // ── 覆盖（黑名单保护） ────────────────────────────────
    $copied = 0;
    $skipped = 0;
    foreach (new DirectoryIterator($srcRoot) as $item) {
        if ($item->isDot()) continue;
        $name = $item->getFilename();
        if (in_array($name, $exclude, true)) {
            $skipped++;
            $log("  · 跳过 {$name}（保护清单）");
            continue;
        }
        $src = $srcRoot . '/' . $name;
        $dst = WPSU_ROOT . '/' . $name;
        if ($item->isDir()) {
            // 目录：先删旧的（wp-admin / wp-includes 整个换），再 copy
            if (is_dir($dst)) rrmdir($dst);
            rcopy($src, $dst);
        } else {
            copy($src, $dst);
        }
        $copied++;
    }
    $log("✓ 覆盖完成（{$copied} 项写入，{$skipped} 项跳过）");

    // ── 收尾 ─────────────────────────────────────────────
    rrmdir(WPSU_TMP_DIR);
    @unlink($mainFile);
    $log("✓ 清理临时目录，退出维护模式");

    // 读新版本号
    $newVer = read_wp_version();

    echo '<div class="ok">';
    echo '<h2>✓ 升级完成</h2>';
    echo '<p>核心文件已更新到 <strong>' . htmlspecialchars($newVer) . '</strong></p>';
    echo '<p>备份目录：<code>' . htmlspecialchars(str_replace(WPSU_ROOT, '', $backupDir)) . '</code>（如需回滚把里面文件覆盖回根目录即可）</p>';
    echo '<p class="warn">⚠ <strong>下一步必做</strong>：访问下面这个 URL 跑数据库升级（WP 大版本会改 schema，不跑会白屏）：</p>';
    echo '<p><a href="/wp-admin/upgrade.php" target="_blank" class="btn">→ 打开 /wp-admin/upgrade.php</a></p>';
    echo '<p style="margin-top:30px;"><a href="?token=' . urlencode($token) . '">← 返回升级页</a></p>';
    echo '</div>';

    page_footer();
}

// ─── 4. 文件操作 helpers ────────────────────────────────────
function rcopy(string $src, string $dst): void
{
    if (is_dir($src)) {
        if (!is_dir($dst)) mkdir($dst, 0755, true);
        foreach (new DirectoryIterator($src) as $item) {
            if ($item->isDot()) continue;
            rcopy($src . '/' . $item->getFilename(), $dst . '/' . $item->getFilename());
        }
    } else {
        copy($src, $dst);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot()) continue;
        $p = $dir . '/' . $item->getFilename();
        if ($item->isDir()) rrmdir($p); else @unlink($p);
    }
    @rmdir($dir);
}

function read_wp_version(): string
{
    $f = WPSU_ROOT . '/wp-includes/version.php';
    if (!is_file($f)) return '未知';
    $code = file_get_contents($f);
    if (preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $code, $m)) return $m[1];
    return '未知';
}

function find_wordpress_zips(): array
{
    $out = [];
    foreach (glob(WPSU_ROOT . '/wordpress-*.zip') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['mtime'] - $a['mtime']);
    return $out;
}

// ─── 5. 页面 ────────────────────────────────────────────────
function page_header(string $title): void
{
    echo <<<HTML
<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">
<title>{$title} - WP 自助升级</title>
<style>
body { font-family: -apple-system, "Segoe UI", "Microsoft YaHei", sans-serif; max-width: 820px; margin: 30px auto; padding: 0 20px; color: #333; line-height: 1.6; }
h1 { color: #21759b; border-bottom: 2px solid #21759b; padding-bottom: 8px; }
h2 { color: #21759b; }
.btn { display: inline-block; background: #21759b; color: #fff; padding: 8px 18px; text-decoration: none; border-radius: 4px; font-weight: bold; }
.btn:hover { background: #135e7c; }
.box { background: #f6f7f7; border: 1px solid #ddd; border-radius: 4px; padding: 16px; margin: 12px 0; }
.zip { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid #eee; }
.zip:last-child { border-bottom: none; }
code { background: #eee; padding: 1px 6px; border-radius: 3px; font-size: 90%; }
.log { font-family: Consolas, monospace; font-size: 13px; padding: 4px 0; color: #555; }
.ok { background: #d4edda; border: 1px solid #c3e6cb; padding: 20px; border-radius: 4px; }
.err { background: #f8d7da; border: 1px solid #f5c6cb; padding: 20px; border-radius: 4px; color: #721c24; }
.warn { background: #fff3cd; border-left: 4px solid #ffc107; padding: 12px; margin: 12px 0; }
input[type=text], input[type=password] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
.small { color: #888; font-size: 12px; }
</style></head><body>
<h1>WordPress 自助升级</h1>
HTML;
}

function page_footer(): void
{
    echo '<p class="small" style="margin-top:40px;">wp-self-update.php — 自助升级工具，独立于 WordPress 鉴权</p></body></html>';
}

function page_setup_token(): void
{
    page_header('首次设置');
    echo '<div class="box">';
    echo '<h2>首次设置访问 Token</h2>';
    echo '<p>这是升级工具的访问密码，与 WP 管理员账号无关。设置后会以 <code>.wp-update-token.php</code> 形式保存在同目录（直接访问该文件会 exit）。</p>';
    echo '<form method="post">';
    echo '<p>Token（至少 8 位，建议混合字母数字）：<br><input type="text" name="token" autofocus required minlength="8" placeholder="例如：jpfg_upd_2026"></p>';
    echo '<p><input type="hidden" name="action" value="set_token"><button type="submit" class="btn">设置并进入</button></p>';
    echo '</form></div>';
    page_footer();
    exit;
}

function page_login(): void
{
    page_header('登录');
    echo '<div class="box"><h2>访问 Token</h2>';
    echo '<form method="get">';
    echo '<p><input type="password" name="token" autofocus required placeholder="输入访问 Token"></p>';
    echo '<p><button type="submit" class="btn">进入</button></p>';
    echo '</form></div>';
    page_footer();
    exit;
}

function page_list(string $token): void
{
    page_header('选择升级包');
    $curVer = read_wp_version();
    $zips = find_wordpress_zips();

    echo '<div class="box">';
    echo '<p><strong>当前 WP 版本：</strong> ' . htmlspecialchars($curVer) . '</p>';
    echo '<p><strong>站点根：</strong> <code>' . htmlspecialchars(WPSU_ROOT) . '</code></p>';
    echo '</div>';

    if (empty($zips)) {
        echo '<div class="warn">同目录下未找到 <code>wordpress-*.zip</code>。把 WP 官方 zip 上传到此目录后刷新。<br>下载地址：<a href="https://cn.wordpress.org/download/" target="_blank">https://cn.wordpress.org/download/</a></div>';
    } else {
        echo '<h2>可用升级包</h2><div class="box" style="padding:0;">';
        foreach ($zips as $z) {
            $sizeMB = round($z['size'] / 1024 / 1024, 2);
            $mtime  = date('Y-m-d H:i', $z['mtime']);
            echo '<div class="zip">';
            echo '<div><strong>' . htmlspecialchars($z['name']) . '</strong><br>';
            echo '<span class="small">' . $sizeMB . ' MB · 上传于 ' . $mtime . '</span></div>';
            echo '<form method="post" onsubmit="return confirm(\'确认用此 ZIP 升级？此前会先备份核心文件\');" style="margin:0;">';
            echo '<input type="hidden" name="token" value="' . htmlspecialchars($token) . '">';
            echo '<input type="hidden" name="zip" value="' . htmlspecialchars($z['name']) . '">';
            echo '<input type="hidden" name="action" value="upgrade">';
            echo '<button type="submit" class="btn">升级</button>';
            echo '</form>';
            echo '</div>';
        }
        echo '</div>';
    }

    echo '<div class="warn"><strong>保护清单</strong>（升级时不会动）：<br>';
    echo '<code>wp-content/</code>（主题/插件/上传）、<code>wp-config.php</code>、<code>.htaccess</code>、<code>.user.ini</code>、本脚本本身。<br>';
    echo '其余根目录文件 + <code>wp-admin/</code> + <code>wp-includes/</code> 会被新版替换（旧版自动备份到 <code>wp-update-backups/&lt;时间戳&gt;/</code>）。</div>';

    page_footer();
}

function page_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function page_error(string $msg): void
{
    page_header('出错');
    echo '<div class="err"><h2>✗ 错误</h2><p>' . htmlspecialchars($msg) . '</p></div>';
    page_footer();
    exit;
}
