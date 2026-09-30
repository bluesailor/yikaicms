<?php
declare(strict_types=1);

/**
 * 整站模板往返验收（2.0.3）：全新安装 → 导入整站模板包 → 前台页面与编辑画布冒烟，
 * 输出绑定两份包 SHA-256 的 JSON 报告。
 *
 * 为什么需要它：ThemeValidator 与 exportCheck 全绿不等于模板可用。英文模板一批里，
 * 社媒设置不随包导出、单独编辑页头时画布缺主题样式，都只有「真的装一遍、导进去、打开看」才发现。
 *
 * 用法（在 CMS 仓库根目录运行）：
 *   php tools/site-template-roundtrip.php --package=<整站模板.zip>
 *       [--cms=tree|<CMS 发行包.zip>]   默认 tree：用当前仓库已跟踪的文件搭临时站
 *       [--db=sqlite|mysql]             默认 sqlite；mysql 读环境变量 YK_ROUNDTRIP_MYSQL_HOST/PORT/USER/PASS
 *       [--php=<php 可执行文件>]         临时站用的 PHP，默认当前 PHP
 *       [--port=8097] [--keep] [--report=<输出.json>]
 *
 * 安全：只在 127.0.0.1 起临时站；管理员账号与密码每次随机生成、只在本进程内使用、不输出；
 * MySQL 只建并删除名为 yk_roundtrip_<随机> 的临时库；结束后删除临时目录（--keep 保留现场）。
 * 不检查首屏位置等需要真实浏览器的项目——那部分用浏览器验收（见模板制作说明）。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const RT_ROOT = __DIR__ . '/..';

$opts = getopt('', ['package:', 'cms::', 'db::', 'php::', 'port::', 'keep', 'report::']);
$package = realpath((string) ($opts['package'] ?? '')) ?: '';
if ($package === '' || !is_file($package)) {
    fwrite(STDERR, "usage: php tools/site-template-roundtrip.php --package=<site-template.zip> [--cms=tree|<cms.zip>] [--db=sqlite|mysql] [--php=<php>] [--port=8097] [--keep] [--report=<out.json>]\n");
    exit(2);
}
$cms = (string) ($opts['cms'] ?? 'tree');
$dbKind = (string) ($opts['db'] ?? 'sqlite');
$php = (string) ($opts['php'] ?? PHP_BINARY);
$port = (int) ($opts['port'] ?? 8097);
$keep = isset($opts['keep']);
$reportPath = (string) ($opts['report'] ?? preg_replace('/\.zip$/i', '', $package) . '.roundtrip.json');
if (!in_array($dbKind, ['sqlite', 'mysql'], true) || $port < 1024 || $port > 65535) {
    fwrite(STDERR, "invalid --db or --port\n");
    exit(2);
}

$report = [
    'generated_at' => gmdate('c'),
    'package' => ['file' => basename($package), 'sha256' => hash_file('sha256', $package)],
    'cms' => ['mode' => $cms === 'tree' ? 'tree' : 'zip'],
    'php' => trim((string) shell_exec(escapeshellarg($php) . ' -r "echo PHP_VERSION;"')),
    'db' => $dbKind,
    'steps' => [],
    'pages' => [],
    'canvases' => [],
    'passed' => false,
];
$sandbox = rtrim(sys_get_temp_dir(), '/\\') . '/yk-roundtrip-' . bin2hex(random_bytes(5));
$server = null;
$mysqlDb = '';

function rt_step(array &$report, string $name, bool $ok, string $detail = ''): void
{
    $report['steps'][] = ['step' => $name, 'ok' => $ok] + ($detail !== '' ? ['detail' => mb_substr($detail, 0, 500)] : []);
    fwrite(STDOUT, ($ok ? '  ✓ ' : '  ✗ ') . $name . ($detail !== '' && !$ok ? ' — ' . mb_substr($detail, 0, 200) : '') . "\n");
    if (!$ok) {
        throw new RuntimeException($name);
    }
}

/** @return array{status:int,headers:array<int,string>,body:string} */
function rt_http(string $method, string $url, array $form = [], array &$cookies = []): array
{
    $headers = ['Accept: text/html,application/json'];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(static fn ($k, $v) => $k . '=' . $v, array_keys($cookies), $cookies));
    }
    $content = '';
    if ($method === 'POST') {
        $content = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content,
        'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string) ($responseHeaders[0] ?? ''), $m) ? (int) $m[1] : 0;
    foreach ($responseHeaders as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=;\s]+)=([^;]*)/i', $line, $c)) {
            $cookies[$c[1]] = $c[2];
        }
    }
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => is_string($body) ? $body : ''];
}

/** 页面里的 PHP 报错与未解析的动态标签。 @return list<string> */
function rt_issues(string $html): array
{
    $issues = [];
    if (preg_match('/<b>(?:Fatal error|Parse error|Warning|Notice|Deprecated)<\/b>:|Uncaught (?:Error|Exception|TypeError)/', $html, $m)) {
        $issues[] = 'php_error: ' . $m[0];
    }
    if (preg_match('/\{\{\s*(?:loop|site|parent|article|product|page)\.[a-z_]+/', $html, $m)) {
        $issues[] = 'unresolved_tag: ' . $m[0];
    }
    return $issues;
}

function rt_rmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

function rt_mysql(): PDO
{
    $host = getenv('YK_ROUNDTRIP_MYSQL_HOST') ?: '127.0.0.1';
    $port = getenv('YK_ROUNDTRIP_MYSQL_PORT') ?: '3306';
    return new PDO("mysql:host={$host};port={$port};charset=utf8mb4", getenv('YK_ROUNDTRIP_MYSQL_USER') ?: 'root',
        (string) getenv('YK_ROUNDTRIP_MYSQL_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

/** 临时站里执行的导入与取样脚本：用临时站自己的 SiteTemplateService 走与后台相同的 prepare → stage → apply。 */
const RT_DRIVER = <<<'PHP'
<?php
declare(strict_types=1);
define('IK_CLI', true);
$_SERVER['HTTP_HOST'] = '127.0.0.1';
$_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/SiteTemplateService.php';
$service = new SiteTemplateService(__DIR__);
// 两个阶段分进程：导入改了站点语言等设置，取样必须在读到新设置的新进程里做
if (($argv[1] ?? '') === 'import') {
if (!$service->canApply()) { fwrite(STDERR, "site is not a fresh install\n"); exit(1); }
$preview = $service->prepare($argv[2], 1);
if (!empty($preview['missing_plugins'])) { fwrite(STDERR, 'missing plugins: ' . json_encode($preview['missing_plugins']) . "\n"); exit(1); }
do { $staged = $service->stage($preview['token'], 1); } while (empty($staged['complete']));
$brand = [];
foreach (['site_name', 'contact_phone', 'contact_email', 'contact_address'] as $key) $brand[$key] = (string) ($preview['brand'][$key] ?? '');
if ($brand['site_name'] === '') $brand['site_name'] = 'Roundtrip';
$service->apply($preview['token'], 1, $brand, true, true);
echo "imported\n";
exit(0);
}
$urls = ['/'];
foreach (channelModel()->all() as $channel) {
    if (empty($channel['status']) || in_array((string) $channel['type'], ['link'], true)) continue;
    if ((string) ($channel['lang'] ?? siteLang()) !== siteLang()) continue;
    $urls[] = channelUrl($channel);
}
foreach (db()->fetchAll('SELECT * FROM ' . DB_PREFIX . "contents WHERE status = 1 AND deleted_at IS NULL AND type <> 'page' ORDER BY id LIMIT 5") as $row) $urls[] = contentUrl($row);
if (db()->tableExists('products')) foreach (db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'products WHERE status = 1 AND deleted_at IS NULL ORDER BY id LIMIT 5') as $row) $urls[] = productUrl($row);
$canvases = [];
if ((string) config('home_blox_active', '0') === '1' && (string) config('home_blox_published', '') !== '') {
    $canvases[] = ['kind' => 'home', 'query' => 'home=1', 'doc' => (string) config('home_blox_published')];
}
foreach (db()->fetchAll('SELECT c.channel_id, c.blocks_data FROM ' . DB_PREFIX . "contents c JOIN " . DB_PREFIX . "channels ch ON ch.id = c.channel_id WHERE ch.type = 'page' AND c.content_type = 'blocks' AND c.blocks_data <> '' ORDER BY c.id LIMIT 5") as $row) {
    $canvases[] = ['kind' => 'page', 'query' => 'id=' . (int) $row['channel_id'], 'doc' => (string) $row['blocks_data']];
}
if (db()->tableExists('blox_templates')) foreach (['header', 'footer'] as $area) {
    $row = db()->fetchOne('SELECT published_data FROM ' . DB_PREFIX . 'blox_templates WHERE type = ? AND status = 1 AND published_data <> \'\' ORDER BY id LIMIT 1', [$area]);
    if ($row) $canvases[] = ['kind' => $area, 'query' => 'home=1&template_area=' . $area . '&area_only=1', 'doc' => (string) $row['published_data']];
}
$theme = currentTheme();
$themeCss = [];
foreach (glob(__DIR__ . '/themes/' . $theme . '/assets/{,*/,*/*/}*.css', GLOB_BRACE) ?: [] as $file) $themeCss[] = basename($file);
echo json_encode(['theme' => $theme, 'theme_css' => $themeCss, 'urls' => array_values(array_unique($urls)), 'canvases' => $canvases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
PHP;

$exit = 1;
try {
    fwrite(STDOUT, "整站模板往返验收：{$report['package']['file']}（sha256 " . substr($report['package']['sha256'], 0, 12) . "…）\n");

    // ── 1. 临时站 ──
    mkdir($sandbox, 0700, true);
    if ($cms === 'tree') {
        $files = explode("\0", (string) shell_exec('git -C ' . escapeshellarg(RT_ROOT) . ' -c core.quotepath=off ls-files -z'));
        $copied = 0;
        foreach ($files as $relative) {
            if ($relative === '' || preg_match('#^(?:tests|docs|\.github|node_modules)/#', $relative)) continue;
            $source = RT_ROOT . '/' . $relative;
            if (!is_file($source)) continue;
            $target = $sandbox . '/' . $relative;
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0700, true);
            copy($source, $target);
            $copied++;
        }
        $report['cms']['git_head'] = trim((string) shell_exec('git -C ' . escapeshellarg(RT_ROOT) . ' rev-parse HEAD'));
        rt_step($report, "临时站：当前仓库 {$copied} 个文件", $copied > 100);
    } else {
        $zipPath = realpath($cms) ?: '';
        $zip = new ZipArchive();
        rt_step($report, '打开 CMS 发行包', $zipPath !== '' && $zip->open($zipPath) === true, $cms);
        $report['cms']['sha256'] = hash_file('sha256', $zipPath);
        $zip->extractTo($sandbox);
        $zip->close();
        $inner = glob($sandbox . '/*', GLOB_ONLYDIR) ?: [];
        if (!is_file($sandbox . '/install/index.php') && count($inner) === 1 && is_file($inner[0] . '/install/index.php')) {
            $sandbox = $inner[0];
        }
        rt_step($report, '解包 CMS 发行包', is_file($sandbox . '/install/index.php'));
    }

    // ── 2. 起临时站点 ──
    $logFile = $sandbox . '/roundtrip-server.log';
    $server = proc_open([$php, '-S', '127.0.0.1:' . $port, '-t', $sandbox], [0 => ['pipe', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes, $sandbox);
    $base = 'http://127.0.0.1:' . $port;
    $ready = false;
    for ($i = 0; $i < 40 && !$ready; $i++) {
        usleep(250000);
        $ready = rt_http('GET', $base . '/install/index.php')['status'] === 200;
    }
    rt_step($report, "临时站点 {$base}", $ready, (string) @file_get_contents($logFile));

    // ── 3. 真实安装器 ──
    $adminUser = 'rt' . bin2hex(random_bytes(3));
    $adminPass = 'Rt#' . bin2hex(random_bytes(8));
    $install = ['action' => 'install', 'db_prefix' => 'yikai_', 'admin_user' => $adminUser, 'admin_pass' => $adminPass,
        'admin_email' => 'roundtrip@example.test', 'site_name' => 'Roundtrip', 'site_url' => $base,
        'site_lang' => 'zh-CN', 'admin_lang' => 'zh-CN', 'install_demo' => '0'];
    if ($dbKind === 'mysql') {
        $mysqlDb = 'yk_roundtrip_' . bin2hex(random_bytes(5));
        rt_mysql()->exec('CREATE DATABASE `' . $mysqlDb . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $install += ['db_driver' => 'mysql', 'db_host' => getenv('YK_ROUNDTRIP_MYSQL_HOST') ?: '127.0.0.1',
            'db_port' => getenv('YK_ROUNDTRIP_MYSQL_PORT') ?: '3306', 'db_name' => $mysqlDb,
            'db_user' => getenv('YK_ROUNDTRIP_MYSQL_USER') ?: 'root', 'db_pass' => (string) getenv('YK_ROUNDTRIP_MYSQL_PASS')];
    } else {
        $install['db_driver'] = 'sqlite';
    }
    $installed = json_decode(rt_http('POST', $base . '/install/index.php', $install)['body'], true);
    rt_step($report, "全新安装（{$dbKind}）", is_array($installed) && !empty($installed['success']), json_encode($installed, JSON_UNESCAPED_UNICODE) ?: '');

    // ── 4. 导入整站模板（临时站自己的服务、与后台同一流程） ──
    file_put_contents($sandbox . '/roundtrip-driver.php', RT_DRIVER);
    $driverOut = [];
    $driverCode = 0;
    exec(escapeshellarg($php) . ' ' . escapeshellarg($sandbox . '/roundtrip-driver.php') . ' import ' . escapeshellarg($package) . ' 2>&1', $driverOut, $driverCode);
    rt_step($report, '导入整站模板', $driverCode === 0 && end($driverOut) === 'imported', implode("\n", array_slice($driverOut, -8)));
    $driverOut = [];
    exec(escapeshellarg($php) . ' ' . escapeshellarg($sandbox . '/roundtrip-driver.php') . ' sample 2>&1', $driverOut, $driverCode);
    $sample = json_decode((string) end($driverOut), true);
    rt_step($report, '读取导入后的页面与画布清单', $driverCode === 0 && is_array($sample), implode("\n", array_slice($driverOut, -8)));
    $report['theme'] = $sample['theme'];

    // ── 5. 前台页面 ──
    $cookies = [];
    foreach ($sample['urls'] as $url) {
        $response = rt_http('GET', $base . $url, [], $cookies);
        $issues = rt_issues($response['body']);
        if ($response['status'] !== 200) $issues[] = 'http_' . $response['status'];
        $report['pages'][] = ['url' => $url, 'status' => $response['status'], 'h1' => preg_match_all('/<h1[\s>]/i', $response['body']), 'issues' => $issues];
    }
    $pageFailures = array_filter($report['pages'], static fn (array $page): bool => $page['issues'] !== []);
    rt_step($report, '前台页面 ' . count($report['pages']) . ' 个', $pageFailures === [], json_encode(array_values($pageFailures), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

    // ── 6. 编辑画布（登录临时站后台） ──
    $session = [];
    $login = rt_http('GET', $base . '/admin/login.php', [], $session);
    preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $login['body'], $token)
        || preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $login['body'], $token);
    $loggedIn = rt_http('POST', $base . '/admin/login.php', ['username' => $adminUser, 'password' => $adminPass, '_token' => $token[1] ?? ''], $session);
    $editor = rt_http('GET', $base . '/admin/blox_editor.php?home=1', [], $session);
    $csrf = preg_match('/csrf:\s*"([a-f0-9]+)"/', $editor['body'], $m) ? $m[1] : '';
    rt_step($report, '登录临时站后台', $csrf !== '', 'login ' . $loggedIn['status'] . ', editor ' . $editor['status']);
    foreach ($sample['canvases'] as $canvas) {
        $response = rt_http('POST', $base . '/admin/blox_preview.php?' . $canvas['query'],
            ['action' => 'preview', 'blox' => '1', 'blocks_data' => $canvas['doc'], '_token' => $csrf], $session);
        $issues = rt_issues($response['body']);
        if ($response['status'] !== 200 || stripos($response['body'], '<html') === false) $issues[] = 'http_' . $response['status'];
        // 主题有自己的样式表时，每种画布都必须加载它（2.0.3 英文模板反馈 #2 的回归）
        if ($sample['theme_css'] !== [] && !str_contains($response['body'], '/themes/' . $sample['theme'] . '/')) {
            $issues[] = 'canvas_missing_theme_css';
        }
        $report['canvases'][] = ['kind' => $canvas['kind'], 'query' => $canvas['query'], 'status' => $response['status'], 'issues' => $issues];
    }
    $canvasFailures = array_filter($report['canvases'], static fn (array $canvas): bool => $canvas['issues'] !== []);
    rt_step($report, '编辑画布 ' . count($report['canvases']) . ' 个', $canvasFailures === [], json_encode(array_values($canvasFailures), JSON_UNESCAPED_UNICODE) ?: '');

    $report['passed'] = true;
    $exit = 0;
} catch (Throwable $e) {
    if (($report['steps'] === [] || end($report['steps'])['ok']) && $e->getMessage() !== '') {
        $report['steps'][] = ['step' => 'error', 'ok' => false, 'detail' => $e->getMessage()];
        fwrite(STDERR, '  ✗ ' . $e->getMessage() . "\n");
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    if ($mysqlDb !== '' && !$keep) {
        try { rt_mysql()->exec('DROP DATABASE IF EXISTS `' . $mysqlDb . '`'); } catch (Throwable) {}
    }
    if ($keep) {
        fwrite(STDOUT, "  · 保留现场：{$sandbox}\n");
    } else {
        rt_rmdir(str_contains($sandbox, 'yk-roundtrip-') ? preg_replace('#(yk-roundtrip-[0-9a-f]+).*$#', '$1', $sandbox) : $sandbox);
    }
    file_put_contents($reportPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    fwrite(STDOUT, ($report['passed'] ? "通过" : "未通过") . "，报告：{$reportPath}\n");
}
exit($exit);
