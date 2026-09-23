<?php
/**
 * 后台页面 GET 渲染冒烟：登录后逐页 GET admin/*.php，断言不出 500。
 *
 * 由来（2026-08-22）：setting_email.php 的服务商预设下拉用 '163'/'126' 作数组键，
 * PHP 自动转 int，e(int) 撞 ?string 签名 → 整页 500 带病进了主目录。
 * php -l 只查语法、单测不跑页面文件、Psalm errorLevel=5 对 possibly-invalid
 * 静默、CRUD 冒烟只测 POST 保存端点——四层防线对「页面渲染期 TypeError」
 * 全部漏空。本脚本补上第五层：真实登录态把每个后台页面渲染一遍。
 *
 * 断言口径：
 *   - 全部页面：HTTP != 500 且响应体无 Fatal error / Uncaught（403/302/400
 *     是正常业务响应，不误伤权限页与纯 POST 端点）；
 *   - 核心设置页白名单：额外要求 === 200（这些页对超管必须能打开）。
 *
 * 用法：先 tests/smoke/setup.php 装机 + php -S，再跑本脚本。失败退出码 1。
 */
declare(strict_types=1);

/** @return ?string */
function pageSmokeOption(string $name): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv ?? [] as $argument) {
        if (is_string($argument) && str_starts_with($argument, $prefix)) {
            return substr($argument, strlen($prefix));
        }
    }
    return null;
}

$BASE = pageSmokeOption('base') ?: (getenv('SMOKE_BASE') ?: 'http://127.0.0.1:8080');
$JAR  = sys_get_temp_dir() . '/smoke_pages_cookies_' . getmypid() . '.txt';
@unlink($JAR);

function pgReq(string $url): array
{
    global $JAR, $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

function pgPost(string $url, array $post): array
{
    global $JAR, $BASE;
    $ch = curl_init($BASE . $url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => false,
        CURLOPT_COOKIEJAR => $JAR, CURLOPT_COOKIEFILE => $JAR,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30,
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post),
    ]);
    $body = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

// ---- 登录（与 admin_crud.php 同一账号与判定口径）----
[$c, $loginPage] = pgReq('/admin/login.php');
if (!preg_match('/name="csrf-token"\s+content="([a-f0-9]+)"/', $loginPage, $m)
    && !preg_match('/name="_token"[^>]*value="([a-f0-9]+)"/', $loginPage, $m)) {
    fwrite(STDERR, "❌ 拿不到登录 CSRF token（HTTP {$c}）\n");
    exit(2);
}
[$c] = pgPost('/admin/login.php', ['username' => 'admin', 'password' => 'smoke@Test123', '_token' => $m[1]]);
if ($c !== 302) {
    fwrite(STDERR, "❌ 登录失败（HTTP {$c}）\n");
    exit(2);
}
echo "✓ 登录成功\n";

// ---- 页面清单：admin/*.php 全量自动发现，显式排除有副作用/非页面项 ----
// 发行包装机必须枚举解包站，而不是源码树；普通源码 smoke 不传环境变量时保持原行为。
$root = rtrim(
    pageSmokeOption('root') ?: (getenv('SMOKE_SITE_ROOT') ?: dirname(__DIR__, 2)),
    '/\\'
);
if (!is_dir($root . '/admin')) {
    fwrite(STDERR, "❌ 后台冒烟目标缺少 admin 目录：{$root}\n");
    exit(2);
}
$skip = [
    'logout.php',          // GET 即登出，会打断后续遍历
];
$pages = [];
foreach (glob($root . '/admin/*.php') ?: [] as $file) {
    $name = basename($file);
    if (in_array($name, $skip, true)) continue;
    $pages[] = '/admin/' . $name;
}
sort($pages);

// 核心设置页：超管必须 200（渲染失败=客户可感知的后台事故）
$must200 = [
    '/admin/index.php', '/admin/setting.php', '/admin/setting_email.php',
    '/admin/setting_cache.php', '/admin/setting_api.php', '/admin/setting_seo.php',
    '/admin/article.php', '/admin/product.php', '/admin/page.php', '/admin/channel.php',
    '/admin/banner.php', '/admin/media.php', '/admin/form.php', '/admin/role.php',
    '/admin/blox_templates.php', '/admin/upgrade_online.php',
    '/admin/site_setup.php', '/admin/site_templates.php', '/admin/site_template_market.php', '/admin/theme_content.php', '/admin/site_content_check.php',
];
$must200 = array_values(array_intersect($must200, $pages));

$fails = [];
$checked = 0;
foreach ($pages as $page) {
    [$code, $body] = pgReq($page);
    $checked++;
    // 匹配 PHP 真实错误输出格式（区分大小写、带冒号/异常类名）——裸子串会误伤
    // setting_translate 这类页面：译文文本里天然含 "fatal errors" 字样
    $hasFatal = preg_match('/Fatal error(<\/b>)?:|Uncaught (Error|Exception|TypeError|ValueError|ArgumentCountError|RuntimeException)/', $body) === 1;
    if ($code >= 500 || $hasFatal) {
        $fails[] = "{$page} → HTTP {$code}" . ($hasFatal ? '（响应体含 Fatal/Uncaught）' : '');
        echo "✗ {$page} HTTP {$code}\n";
        continue;
    }
    if (in_array($page, $must200, true) && $code !== 200) {
        $fails[] = "{$page} → HTTP {$code}（核心页要求 200）";
        echo "✗ {$page} HTTP {$code}（要求 200）\n";
    }
}

// ---- 栏目落地页编辑器：画布里要渲染各栏目的目录元素 ----
// 下载/职位目录直接套固定列表的视图，视图用到的变量缺一个就是 Warning——页面照样 200，
// 上面按 500 判定的循环抓不到（2026-09-24：下载目录侧栏缺 $channelId，画布里直接露出 Warning）。
// 页面正文与站点错误日志两头看：生产配置下 Warning 不显示，但 ErrorHandler 照样记日志。
$landingDb = $root . '/storage/database.sqlite';
if (is_file($landingDb)) {
    $landingLogs = static fn(): int => array_sum(array_map('filesize', glob($root . '/storage/logs/error-*.log') ?: []));
    clearstatcache();
    $landingLogBefore = $landingLogs();
    $landingPdo = new PDO('sqlite:' . $landingDb);
    $landingRows = $landingPdo->query(
        "SELECT id, type FROM yikai_channels WHERE parent_id = 0 AND lang = 'zh-CN'"
        . " AND type IN ('list', 'case', 'download', 'job') ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    // 画布不在编辑器页面里渲染：编辑器脚本把文档 POST 给保存接口的 preview 动作（blox-preview-client.js），
    // 这里照做，并要求目录元素确实渲染出来（防止检查本身什么都没看见就放行）
    $landingElements = [
        'list' => ['content-catalog', 'data-content-catalog'],
        'case' => ['content-catalog', 'data-content-catalog'],
        'download' => ['download-catalog', 'download_filename'],
        'job' => ['job-catalog', 'data-job-catalog'],
    ];
    foreach ($landingRows as $landingRow) {
        $landingId = (int) $landingRow['id'];
        [$element, $marker] = $landingElements[(string) $landingRow['type']];
        [$code, $editorPage] = pgReq('/admin/blox_editor.php?id=' . $landingId);
        preg_match('/csrf:\s*"([a-f0-9]+)"/', $editorPage, $landingToken);
        $landingDoc = json_encode([[
            'id' => 's_smoke', 'settings' => [],
            'columns' => [['id' => 'c_smoke', 'elements' => [['id' => 'e_smoke', 'type' => $element, 'data' => []]]]],
        ]]);
        [$code, $body] = pgPost('/admin/blox_page_api.php?id=' . $landingId, [
            'action' => 'preview', 'blox' => '1', 'blocks_data' => (string) $landingDoc, '_token' => $landingToken[1] ?? '',
        ]);
        $checked++;
        // download_filename 是下载表头文案的 key：表头按当前语言渲染，这里改认表格结构
        $rendered = $marker === 'download_filename' ? str_contains($body, '<table') : str_contains($body, $marker);
        if ($code !== 200 || !$rendered || preg_match('/(Warning|Notice|Deprecated|Fatal error)(<\/b>)?:/', $body) === 1) {
            $fails[] = "{$landingRow['type']} 栏目 #{$landingId} 画布预览（{$element}）→ HTTP {$code}"
                . ($rendered ? '' : '，目录元素未渲染') . '，或出现 PHP 警告';
            echo "✗ {$landingRow['type']} #{$landingId} 画布预览 HTTP {$code}\n";
        }
    }
    clearstatcache();
    if ($landingLogs() > $landingLogBefore) {
        $fails[] = '栏目落地页编辑器渲染期间站点错误日志新增了记录（storage/logs）';
        echo "✗ 栏目落地页编辑器写入了错误日志\n";
    }
}

@unlink($JAR);
if ($fails) {
    fwrite(STDERR, "\n❌ 后台页面冒烟失败 " . count($fails) . " 项：\n  - " . implode("\n  - ", $fails) . "\n");
    exit(1);
}
echo "\n✅ 后台页面冒烟通过：{$checked} 页无 500，核心 " . count($must200) . " 页全部 200\n";
