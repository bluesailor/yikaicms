<?php
declare(strict_types=1);

// Standalone preflight: intentionally never boot the CMS or read its credentials.
// Keep PHP 7.1 syntax so older servers can still display a useful result.
$originalDisplayErrors = (string) ini_get('display_errors');
@ini_set('display_errors', '0');
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'");

function ykEnvEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ykEnvBytes(string $value): float
{
    $value = trim($value);
    if ($value === '-1') {
        return INF;
    }
    if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMG]?)$/i', $value, $match)) {
        return 0;
    }
    $powers = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3];
    return (float) $match[1] * pow(1024, $powers[strtoupper($match[2])]);
}

$language = isset($_GET['lang']) && is_string($_GET['lang']) ? $_GET['lang'] : 'zh-CN';
if (!in_array($language, ['zh-CN', 'en', 'ja'], true)) {
    $language = 'zh-CN';
}
$copy = [
    'title' => ['运行环境检测', 'Environment Check', '動作環境チェック'],
    'intro' => ['部署前检查 · 只读检测', 'Deployment preflight · Read-only', '導入前の確認 · 読み取り専用'],
    'pass' => ['通过', 'Pass', '適合'],
    'warn' => ['建议调整', 'Review', '要確認'],
    'fail' => ['不满足', 'Failed', '不適合'],
    'info' => ['待确认', 'Unverified', '未確認'],
    'ready' => ['基础环境检查通过，仍需确认数据库与伪静态', 'Basic checks passed; verify the database and URL rewriting', '基本環境は適合。DB と URL 書き換えは別途確認'],
    'blocked' => ['存在不满足项，请调整后重新检测', 'Required checks failed. Resolve them and check again.', '必須項目に問題があります。修正後に再確認してください。'],
    'refresh' => ['重新检测', 'Check again', '再確認'],
    'item' => ['检测项目', 'Check', '検査項目'],
    'current' => ['当前结果', 'Result', '結果'],
    'requirement' => ['要求 / 处理建议', 'Requirement / Action', '要件 / 対応'],
    'runtime' => ['PHP 与必需扩展', 'PHP and required extensions', 'PHP と必須拡張'],
    'optional' => ['功能扩展与运行配置', 'Feature extensions and configuration', '機能拡張と設定'],
    'files' => ['安装目录', 'Installation directories', 'インストール先'],
    'manual' => ['需要人工确认', 'Manual checks', '手動確認'],
    'enabled' => ['已启用', 'Enabled', '有効'],
    'missing' => ['未启用', 'Unavailable', '利用不可'],
    'enable' => ['在主机面板启用此 PHP 扩展，然后重启 PHP 服务。', 'Enable this PHP extension in the hosting panel and restart PHP.', '管理パネルで PHP 拡張を有効にし、PHP を再起動してください。'],
    'version' => ['最低 %s；%s 及以上仅为建议，不阻止 PHP 8.0 安装。', 'Minimum %s; %s+ is recommended, not a blocker for PHP 8.0 installation.', '最低 %s。%s 以上は推奨であり、PHP 8.0 のインストールを妨げません。'],
    'snapshot' => ['独立检测清单（安装器基线快照）', 'Standalone checklist (installer baseline snapshot)', '単独チェックリスト（インストーラー要件のスナップショット）'],
    'project' => ['来自当前项目 RuntimeRequirements', 'From this project\'s RuntimeRequirements', '現在の RuntimeRequirements に基づく'],
    'driver' => ['数据库驱动', 'Database driver', 'DB ドライバー'],
    'driverHint' => ['MySQL/MariaDB 需要 pdo_mysql；SQLite 需要 pdo_sqlite。至少启用一个，并与安装时选择一致。', 'MySQL/MariaDB requires pdo_mysql; SQLite requires pdo_sqlite. Enable the driver for the database you will use.', 'MySQL/MariaDB は pdo_mysql、SQLite は pdo_sqlite が必要です。利用する DB に合わせて有効にしてください。'],
    'curl' => ['在线更新、模板市场与 AI 接口', 'Online updates, marketplace and AI APIs', 'オンライン更新、マーケット、AI API'],
    'openssl' => ['授权签名校验与加密', 'License signature verification and encryption', 'ライセンス署名の検証と暗号化'],
    'gd' => ['缩略图与图片处理', 'Thumbnails and image processing', 'サムネイルと画像処理'],
    'zip' => ['在线更新、主题 / 插件安装与整站模板导入导出', 'Updates, theme/plugin installs and site template import/export', '更新、テーマ・プラグインのインストール、サイトテンプレートの入出力'],
    'simplexml' => ['部分导入插件需要，核心运行非必需', 'Required by some import plugins, not the core', '一部のインポートプラグインで使用。コアには不要'],
    'session' => ['后台登录需要 session 扩展及 session_start；会话持久化需实际登录确认。', 'Admin login needs session and session_start; verify persistence by logging in.', '管理画面へのログインに session と session_start が必要です。保持状態は実際のログインで確認してください。'],
    'memory' => ['建议至少 128M；大型图片或导入建议 256M。', 'Recommend at least 128M; 256M for large images or imports.', '128M 以上を推奨。大きな画像・インポートには 256M を推奨。'],
    'upload' => ['建议至少 16M；视频及安装包按实际大小调整。', 'Recommend at least 16M; size this for your videos and packages.', '16M 以上を推奨。動画やパッケージのサイズに合わせて調整してください。'],
    'post' => ['应大于 upload_max_filesize，并为表单内容留出空间。', 'Set higher than upload_max_filesize to allow form overhead.', 'フォーム分の余裕を持たせ、upload_max_filesize より大きくしてください。'],
    'execution' => ['建议至少 60 秒；0 表示 PHP 不限时，代理仍可能超时。', 'Recommend 60 seconds or more; 0 means no PHP limit, not no proxy timeout.', '60 秒以上を推奨。0 は PHP の制限なし（プロキシ制限は別）。'],
    'uploads' => ['文件上传', 'File uploads', 'ファイルアップロード'],
    'uploadOn' => ['上传图片和安装包需要 file_uploads=On。', 'Images and package uploads need file_uploads=On.', '画像とパッケージのアップロードには file_uploads=On が必要です。'],
    'display' => ['生产环境建议 display_errors=Off，错误写入日志。', 'Production should use display_errors=Off and log errors.', '本番は display_errors=Off にしてエラーをログへ記録してください。'],
    'writable' => ['存在，可写（权限预检）', 'Present, writable (permission precheck)', '存在・書き込み可能（権限確認）'],
    'readonly' => ['存在，不可写', 'Present, not writable', '存在・書き込み不可'],
    'absent' => ['尚不存在', 'Not present', '未作成'],
    'rootHint' => ['安装器要在根目录写入 installed.lock，写不了会装完仍跳回安装页；这里只检查权限。', 'The installer writes installed.lock to the site root; if it cannot, every page returns to the installer. Permission check only.', 'インストーラーはサイトのルートに installed.lock を書き込みます。書き込めないと完了後もインストール画面に戻ります。権限のみ確認します。'],
    'dirHint' => ['安装时须可写；这里只检查权限，不创建文件、不修改权限。', 'Must be writable during installation. No files or permissions are changed by this check.', 'インストール時に書き込み権限が必要です。この検査はファイルや権限を変更しません。'],
    'standalone' => ['未发现 CMS 文件，只检测 PHP；请将本页放在安装目录根部检查目录权限。', 'CMS files not found. PHP-only checks; place this file in the CMS root to check directories.', 'CMS が見つからないため PHP のみ検査します。ディレクトリ確認には CMS のルートに配置してください。'],
    'db' => ['数据库连接与版本', 'Database connection and version', 'DB 接続とバージョン'],
    'dbHint' => ['目标 MySQL 5.7+ / MariaDB 10.x，或 SQLite。未连接数据库，不以 PDO 客户端版本推断服务器版本；请在安装器中验证连接与权限。', 'Target MySQL 5.7+ / MariaDB 10.x, or SQLite. No DB connection made; a PDO client version cannot prove the server version. Verify connection and privileges in the installer.', '対象は MySQL 5.7+ / MariaDB 10.x または SQLite。DB には接続しません。サーバーバージョン・接続・権限はインストーラーで確認してください。'],
    'rewrite' => ['伪静态与目录访问保护', 'URL rewriting and directory protection', 'URL 書き換えとディレクトリ保護'],
    'rewriteHint' => ['PHP 无法仅凭服务器名称确认规则生效。配置对应 Apache/Nginx 规则后，实际访问内页，并在后台站点健康中复测。', 'Server names do not prove rewrite rules work. Configure Apache/Nginx, visit an inner page, and run Site Health after installation.', 'サーバー名だけでは動作を確認できません。Apache/Nginx を設定し、内部ページとサイトヘルスで確認してください。'],
    'tls' => ['HTTPS 与外部连接', 'HTTPS and outbound access', 'HTTPS と外部接続'],
    'tlsHint' => ['本页不访问外网。浏览器确认 HTTPS 有效；市场、授权、邮件和 AI 连接须在对应后台功能中测试。', 'No outbound requests made. Verify HTTPS in the browser; test marketplace, licensing, mail and AI in their admin screens.', '外部通信は行いません。HTTPS はブラウザーで、マーケット・認証・メール・AI は各管理画面で確認してください。'],
    'notice' => ['检测完成后请从服务器删除本页。不会展示 phpinfo、密码、环境变量或绝对路径，也不会安装、连接数据库或更改站点。', 'Remove this file from the server when finished. No phpinfo, passwords, environment variables or absolute paths are shown. No installation, DB connection or site changes occur.', '完了後はサーバーから削除してください。phpinfo・パスワード・環境変数・絶対パスは表示せず、インストール・DB 接続・サイト変更も行いません。'],
];
$column = array_search($language, ['zh-CN', 'en', 'ja'], true);
$t = static function (string $key) use ($copy, $column): string {
    return $copy[$key][$column] ?? $key;
};

$root = __DIR__;
if (basename(__DIR__) === 'deploy' && is_file(dirname(__DIR__) . '/includes/RuntimeRequirements.php')) {
    $root = dirname(__DIR__);
}
$inProject = is_file($root . '/includes/RuntimeRequirements.php');
$minimum = '8.0.0';
$recommended = '8.2.0';
$required = ['pdo', 'json', 'mbstring', 'fileinfo', 'dom'];
$optional = ['curl', 'openssl', 'gd', 'zip', 'simplexml'];
$drivers = ['pdo_mysql', 'pdo_sqlite'];
$source = 'snapshot';
if ($inProject && version_compare(PHP_VERSION, '8.0.0', '>=')) {
    define('ROOT_PATH', $root);
    require_once $root . '/includes/RuntimeRequirements.php';
    $minimum = RuntimeRequirements::PHP_MINIMUM;
    $recommended = RuntimeRequirements::PHP_RECOMMENDED;
    $required = RuntimeRequirements::requiredNames();
    $optional = RuntimeRequirements::recommendedNames();
    $drivers = RuntimeRequirements::databaseNames();
    $source = 'project';
}
$checks = [];
$add = static function (string $group, string $name, string $value, string $status, string $hint) use (&$checks): void {
    $checks[$group][] = compact('name', 'value', 'status', 'hint');
};
$add('runtime', 'PHP', PHP_VERSION, version_compare(PHP_VERSION, $minimum, '<') ? 'fail' : (version_compare(PHP_VERSION, $recommended, '<') ? 'warn' : 'pass'), sprintf($t('version'), $minimum, $recommended));
foreach ($required as $ext) {
    $loaded = extension_loaded($ext);
    $add('runtime', $ext, $t($loaded ? 'enabled' : 'missing'), $loaded ? 'pass' : 'fail', $t('enable'));
}
$available = array_values(array_filter($drivers, 'extension_loaded'));
$add('runtime', $t('driver'), $available ? implode(', ', $available) : $t('missing'), $available ? 'pass' : 'fail', $t('driverHint'));
$session = extension_loaded('session') && function_exists('session_start');
$add('runtime', 'Session', $t($session ? 'enabled' : 'missing'), $session ? 'pass' : 'fail', $t('session'));
foreach ($optional as $ext) {
    $loaded = extension_loaded($ext);
    $add('optional', $ext, $t($loaded ? 'enabled' : 'missing'), $loaded ? 'pass' : 'warn', isset($copy[$ext]) ? $t($ext) : $t('enable'));
}
$memory = (string) ini_get('memory_limit');
$upload = (string) ini_get('upload_max_filesize');
$post = (string) ini_get('post_max_size');
$timeout = (string) ini_get('max_execution_time');
$add('optional', 'memory_limit', $memory, ykEnvBytes($memory) >= 128 * 1024 * 1024 ? 'pass' : 'warn', $t('memory'));
$add('optional', 'upload_max_filesize', $upload, ykEnvBytes($upload) >= 16 * 1024 * 1024 ? 'pass' : 'warn', $t('upload'));
$add('optional', 'post_max_size', $post, ykEnvBytes($post) === 0.0 || ykEnvBytes($post) > ykEnvBytes($upload) ? 'pass' : 'warn', $t('post'));
$add('optional', 'max_execution_time', $timeout . ' s', $timeout === '0' || (int) $timeout >= 60 ? 'pass' : 'warn', $t('execution'));
$uploadEnabled = filter_var(ini_get('file_uploads'), FILTER_VALIDATE_BOOLEAN);
$add('optional', $t('uploads'), $t($uploadEnabled ? 'enabled' : 'missing'), $uploadEnabled ? 'pass' : 'warn', $t('uploadOn'));
$errorsOn = !in_array(strtolower($originalDisplayErrors), ['', '0', 'off', 'false', 'none'], true);
$add('optional', 'display_errors', $errorsOn ? 'On' : 'Off', $errorsOn ? 'warn' : 'pass', $t('display'));
if ($inProject) {
    $rootWritable = is_writable($root);
    $add('files', '/ (installed.lock)', $t($rootWritable ? 'writable' : 'readonly'), $rootWritable ? 'pass' : 'fail', $t('rootHint'));
    foreach (['config', 'uploads', 'storage'] as $dir) {
        $exists = is_dir($root . '/' . $dir);
        $writable = $exists && is_writable($root . '/' . $dir);
        $add('files', $dir . '/', $t($writable ? 'writable' : ($exists ? 'readonly' : 'absent')), $writable ? 'pass' : 'fail', $t('dirHint'));
    }
} else {
    $add('files', 'YikaiCMS', $t('info'), 'info', $t('standalone'));
}
foreach (['db', 'rewrite', 'tls'] as $manual) {
    $add('manual', $t($manual), $t('info'), 'info', $t($manual . 'Hint'));
}
$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0];
foreach ($checks as $rows) {
    foreach ($rows as $row) {
        ++$counts[$row['status']];
    }
}
?>
<!doctype html>
<html lang="<?= ykEnvEscape($language) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>YikaiCMS · <?= ykEnvEscape($t('title')) ?></title>
<style>
:root{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;color:#242b35;background:#f5f6f8;font-size:15px;line-height:1.6;letter-spacing:0}*{box-sizing:border-box}body{margin:0}main{max-width:1120px;margin:auto;padding:32px 24px 48px}header{display:flex;justify-content:space-between;gap:20px;align-items:center;border-bottom:1px solid #dce1e8;padding-bottom:22px}h1{font-size:25px;margin:2px 0}h2{font-size:18px;margin:28px 0 12px}.brand{font-weight:750;color:#175dcc}p{margin:4px 0;color:#526071}nav{display:flex;gap:14px;flex-wrap:wrap}a{color:#175dcc;text-underline-offset:4px}a:focus-visible{outline:3px solid #175dcc;outline-offset:4px}a:hover{color:#123e86}.summary{padding:20px 0;border-bottom:1px solid #dce1e8}.summary strong{font-size:18px}.counts{display:flex;gap:18px;flex-wrap:wrap;margin:12px 0}.status{font-size:13px;font-weight:650}.pass{color:#166344}.warn{color:#875508}.fail{color:#b42332}.info{color:#526071}.list{background:white;border-top:1px solid #dce1e8}.row{display:grid;grid-template-columns:minmax(140px,1fr) minmax(150px,1fr) minmax(0,2fr);gap:18px;padding:14px 16px;border-bottom:1px solid #e3e6eb;align-items:start;overflow-wrap:anywhere}.heading{background:#eef1f5;font-weight:600;font-size:13px;color:#526071}.value{font-variant-numeric:tabular-nums}.hint{font-size:14px;color:#526071}small{display:block}footer{margin-top:28px;border-top:1px solid #dce1e8;padding-top:16px;font-size:13px;color:#526071}@media(max-width:640px){main{padding:20px 16px}header{align-items:flex-start;flex-direction:column;gap:12px}h1{font-size:22px}.row{grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px 14px;padding:12px}.hint{grid-column:1/-1}.heading{display:none}}
</style>
</head>
<body><main>
<header><div><span class="brand">YikaiCMS</span><h1><?= ykEnvEscape($t('title')) ?></h1><p><?= ykEnvEscape($t('intro')) ?></p></div>
<nav aria-label="Language"><a href="?lang=zh-CN" lang="zh-CN">中文</a><a href="?lang=en" lang="en">English</a><a href="?lang=ja" lang="ja">日本語</a></nav></header>
<section class="summary"><strong class="<?= $counts['fail'] ? 'fail' : 'pass' ?>"><?= ykEnvEscape($t($counts['fail'] ? 'blocked' : 'ready')) ?></strong>
<div class="counts"><?php foreach ($counts as $status => $count): ?><span class="status <?= ykEnvEscape($status) ?>"><?= ykEnvEscape($t($status)) ?> · <?= (int) $count ?></span><?php endforeach; ?></div>
<p><?= ykEnvEscape($t($source)) ?> · <?= ykEnvEscape(PHP_SAPI) ?></p><a href="?lang=<?= ykEnvEscape($language) ?>"><?= ykEnvEscape($t('refresh')) ?></a></section>
<?php foreach ($checks as $group => $rows): ?>
<section><h2><?= ykEnvEscape($t($group)) ?></h2><div class="list" role="table" aria-label="<?= ykEnvEscape($t($group)) ?>">
<div class="row heading" role="row"><span role="columnheader"><?= ykEnvEscape($t('item')) ?></span><span role="columnheader"><?= ykEnvEscape($t('current')) ?></span><span role="columnheader"><?= ykEnvEscape($t('requirement')) ?></span></div>
<?php foreach ($rows as $row): ?><div class="row" role="row"><strong role="cell"><?= ykEnvEscape($row['name']) ?></strong><div class="value" role="cell"><?= ykEnvEscape($row['value']) ?><small class="status <?= ykEnvEscape($row['status']) ?>"><?= ykEnvEscape($t($row['status'])) ?></small></div><div class="hint" role="cell"><?= ykEnvEscape($row['hint']) ?></div></div><?php endforeach; ?>
</div></section>
<?php endforeach; ?>
<footer><?= ykEnvEscape($t('notice')) ?></footer>
</main></body></html>
