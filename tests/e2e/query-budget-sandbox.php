<?php
/**
 * 关键页查询次数（2.0.5 §5.3a 性能回归）：一次性沙盒站点（当前工作树 git ls-files + 真实安装器 + 演示数据），
 * 服务器带 YK_QUERY_COUNT=1 起，每个请求执行的语句数写进 storage/logs/query-count.log（Database::enableQueryCount）。
 *
 * 两类断言：
 *   1. N+1：列表页每页 6 条与 24 条的查询数之差不超过 N1_SLACK——条目多了查询不该跟着涨（每条都有一批 metas）；
 *   2. 预算：每页不超过 BUDGET 里的上限（按 2026-10-06 实测留余量；改大要说明原因）。
 *
 * 用法：php tests/e2e/query-budget-sandbox.php <仓库目录> [--report] [--sql]
 *   --report 只打印实测表、不判预算（调预算时用）；--sql 另把每页的语句存成 sql-<页>-<条数>.log 并保留沙盒（排查用）。全部通过时删除沙盒，失败时保留供排查。
 */
declare(strict_types=1);

$repo = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
$reportOnly = in_array('--report', $argv, true);
$sqlMode = in_array('--sql', $argv, true);
if (!is_file($repo . '/index.php')) { fwrite(STDERR, "bad repo\n"); exit(2); }
$php = PHP_BINARY;

/**
 * 页面 → 查询数上限（每页 24 条时）。2026-10-06 实测（导航一次取出 + 只读请求内记住栏目之后）再留约 15%：
 * 首页 53、英文首页 64、新闻列表 46、文章 42、产品列表 45、产品 51、案例列表 46、单页 36、搜索 35、站点地图 6。
 * 改动前同样的页面是 154–210 条（导航每个栏目、子栏目各查一次，一页渲染 4 次）。
 */
const BUDGET = [
    'home' => 62, 'home-en' => 74, 'news-list' => 54, 'article' => 50, 'product-list' => 54, 'product' => 60,
    'case-list' => 54, 'page' => 44, 'search' => 42, 'sitemap' => 10,
    // v2.1：12 行产品循环的 Blox 单页，2026-10-07 实测普通卡片 34、组件卡片 35（母版目录整页只取一次），留约 15%
    'plain-cards' => 40, 'component-cards' => 41,
];
const N1_SLACK = 4;

$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n     " . substr($detail, 0, 700)) . "\n";
    if (!$ok) $fail++;
};

function http(int $port, string $path, array $post = []): array
{
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Host: main.test:' . $port]]);
    if ($post !== []) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $hsize), $m);
    return ['status' => $status, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $hsize)];
}

$site = str_replace('\\', '/', sys_get_temp_dir()) . '/yk-querybudget-' . bin2hex(random_bytes(4));
mkdir($site, 0700, true);
foreach (explode("\0", trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files -z'))) as $rel) {
    if ($rel === '' || preg_match('#^(?:tests|docs|\.github|node_modules)/#', $rel) || !is_file($repo . '/' . $rel)) continue;
    if (!is_dir(dirname($site . '/' . $rel))) mkdir(dirname($site . '/' . $rel), 0700, true);
    copy($repo . '/' . $rel, $site . '/' . $rel);
}
file_put_contents($site . '/router.php', '<?php
$p = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$run = static function (string $rel): bool {
    $f = __DIR__ . $rel;
    $_SERVER["SCRIPT_NAME"] = $rel; $_SERVER["SCRIPT_FILENAME"] = $f; $_SERVER["PHP_SELF"] = $rel;
    chdir(dirname($f)); require $f; return true;
};
if ($p !== "/" && is_file(__DIR__ . $p) && !str_ends_with($p, ".php")) return false;
if ($p !== "/" && is_file(__DIR__ . $p)) return $run($p);
if ($p !== "/" && is_dir(__DIR__ . $p) && is_file(rtrim(__DIR__ . $p, "/") . "/index.php")) return $run(rtrim($p, "/") . "/index.php");
return $run("/index.php");
');
file_put_contents($site . '/e2e-driver.php', <<<'PHP'
<?php
define("ROOT_PATH", __DIR__);
require ROOT_PATH . "/config/config.php";
require ROOT_PATH . "/includes/functions.php";
require ROOT_PATH . "/includes/models/autoload.php";
require ROOT_PATH . "/includes/hooks.php";
$t = DB_PREFIX;
if ($argv[1] === "seed") {
    settingModel()->set("url_mode", "pretty");
    settingModel()->set("enabled_languages", json_encode(["zh-CN", "en", "ja"]));
    settingModel()->set("show_lang_switcher", "1");
    settingModel()->set("html_cache_enabled", "0");
    // 凑够条目：新闻 30 篇、产品 30 个、案例 30 个，每条带 6 个 metas（模拟高级字段）
    $copy = static function (string $table, array $row, string $prefix, int $n) use ($t): array {
        unset($row["id"]);
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $id = (int) db()->insert($table, array_merge($row, ["slug" => $prefix . $i, "title" => ucfirst($prefix) . " " . $i, "translation_group_id" => 0]));
            db()->update($table, ["translation_group_id" => $id], "id = ?", [$id]);
            $ids[] = $id;
        }
        return $ids;
    };
    $article = db()->fetchOne("SELECT * FROM {$t}contents WHERE type = 'article' AND lang = 'zh-CN' AND status = 1 ORDER BY id LIMIT 1");   // 演示文章在新闻的子分类里
    $case = db()->fetchOne("SELECT * FROM {$t}contents WHERE type = 'case' AND lang = 'zh-CN' AND status = 1 ORDER BY id LIMIT 1");
    $product = db()->fetchOne("SELECT * FROM {$t}products WHERE lang = 'zh-CN' AND status = 1 ORDER BY id LIMIT 1");
    if (!$article || !$product || !$case) { echo "{}"; exit(1); }
    foreach ($copy("contents", $article, "qb-article-", 30) as $id) for ($k = 1; $k <= 6; $k++) setMeta("content", $id, "qb_field_" . $k, "v" . $k);
    foreach ($copy("contents", $case, "qb-case-", 30) as $id) for ($k = 1; $k <= 6; $k++) setMeta("content", $id, "qb_field_" . $k, "v" . $k);
    foreach ($copy("products", $product, "qb-product-", 30) as $id) for ($k = 1; $k <= 6; $k++) setMeta("product", $id, "qb_field_" . $k, "v" . $k);
    // v2.1 组件：同一个 12 行产品循环，一页放普通卡片、一页放组件卡片——组件不能带来按实例的查询
    require_once ROOT_PATH . "/includes/builder/bootstrap.php";
    $master = BloxComponents::processMaster(json_encode(["schema" => 1,
        "settings" => ["component" => ["props" => [["key" => "title", "type" => "text", "label" => "Title", "default" => "x", "targets" => [["node" => "qbc-t", "field" => "text"]]]]]],
        "sections" => [["id" => "qbc-s", "settings" => [], "columns" => [["id" => "qbc-c", "elements" => [["id" => "qbc-root", "type" => "container", "data" => ["children" => [
            ["id" => "qbc-t", "type" => "heading", "data" => ["text" => "x", "level" => "h3"]],
            ["id" => "qbc-b", "type" => "button", "data" => ["text" => "More", "url" => "{{loop.url}}"]],
        ]]]]]]]],
    ]), "tpl0");
    $tpl = bloxTemplateModel()->createDraft("component", "QB card", $master["json"]);
    bloxTemplateModel()->publishDraft($tpl);
    $uuid = (string) json_decode((string) bloxTemplateModel()->find($tpl)["metadata"], true)["component"]["uuid"];
    $loopPage = static function (string $slug, array $card): string {
        $id = (int) channelModel()->create(["name" => $slug, "slug" => $slug, "type" => "page", "lang" => "zh-CN", "status" => 1, "parent_id" => 0, "content" => "", "created_at" => time(), "updated_at" => time()]);
        $json = json_encode(["schema" => 1, "sections" => [["id" => $slug . "-s", "settings" => [], "columns" => [["id" => $slug . "-c", "elements" => [
            ["id" => $slug . "-loop", "type" => "container", "data" => ["_query" => ["source" => "type:product", "limit" => 12], "children" => [$card]]],
        ]]]]]]);
        BloxFeaturePolicy::asTrustedWrite(static fn () => PageBloxDocument::saveAndPublish($id, (string) $json));
        return (string) parse_url(channelUrl(channelModel()->find($id) ?? []), PHP_URL_PATH);
    };
    $plain = $loopPage("qb-plain-cards", ["id" => "qbp-card", "type" => "container", "data" => ["children" => [
        ["id" => "qbp-t", "type" => "heading", "data" => ["text" => "{{loop.title}}", "level" => "h3"]],
        ["id" => "qbp-b", "type" => "button", "data" => ["text" => "More", "url" => "{{loop.url}}"]],
    ]]]);
    $components = $loopPage("qb-component-cards", ["id" => "qbk-card", "type" => "component", "data" => ["component" => $uuid, "props" => ["title" => "{{loop.title}}"]]]);
    settingModel()->rotateHtmlCacheGeneration();
    echo json_encode(["article" => contentDefaultPrettyUrl($article + ["type" => "article"]), "product" => productPrettyUrl($product),
        "plain_cards" => $plain, "component_cards" => $components], JSON_UNESCAPED_SLASHES);
} elseif ($argv[1] === "pagesize") {
    foreach (["product", "article", "case"] as $kind) settingModel()->set("catalog_" . $kind . "_page_size", $argv[2]);
    settingModel()->rotateHtmlCacheGeneration();
    echo "ok";
}
PHP);

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$log = $site . '/server.log';
$proc = proc_open([$php, '-S', '127.0.0.1:' . $port, '-t', $site, $site . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $site, ['YK_QUERY_COUNT' => $sqlMode ? 'sql' : '1'] + getenv(), ['bypass_shell' => true]);
$counts = $site . '/storage/logs/query-count.log';

try {
    $ready = false;
    for ($i = 0; $i < 120 && !$ready; $i++) { usleep(250000); $probe = http($port, '/install/index.php'); $ready = $probe['status'] === 200; }
    $check($ready, 'sandbox server up', (string) @file_get_contents($log));
    if (!$ready) throw new RuntimeException('server');
    $inst = json_decode(http($port, '/install/index.php', [
        'action' => 'install', 'db_driver' => 'sqlite', 'db_prefix' => 'yikai_', 'admin_user' => 'e2e' . bin2hex(random_bytes(2)),
        'admin_pass' => 'E2e#' . bin2hex(random_bytes(8)), 'admin_email' => 'e2e@example.test', 'site_name' => 'E2E',
        'site_url' => 'http://main.test:' . $port, 'site_lang' => 'zh-CN', 'admin_lang' => 'zh-CN', 'install_demo' => '1'])['body'], true);
    $check(is_array($inst) && !empty($inst['success']), 'install with demo data', json_encode($inst));
    $drive = static fn(string ...$args): string => trim((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($site . '/e2e-driver.php') . ' ' . implode(' ', array_map('escapeshellarg', $args))));
    $seed = json_decode($drive('seed'), true);
    $check(is_array($seed) && isset($seed['article']), 'seed 90 entries with 6 metas each', json_encode($seed));
    if (!is_array($seed) || !isset($seed['article'])) throw new RuntimeException('seed');

    $pages = [
        'home' => '/', 'home-en' => '/en/', 'news-list' => '/news.html', 'article' => $seed['article'], 'product-list' => '/product.html',
        'product' => $seed['product'], 'case-list' => '/cases.html', 'page' => '/about/company.html', 'search' => '/search.php?keyword=qb', 'sitemap' => '/sitemap.xml',
        'plain-cards' => $seed['plain_cards'], 'component-cards' => $seed['component_cards'],
    ];
    // 搜索每页条数不随栏目设置变，结果链接也不带别名，不参与「条目多了查询数不涨」的对比
    $listPages = ['news-list', 'product-list', 'case-list'];
    /** 请求一次（先热一次：首个请求会建缓存表等），返回这次请求的语句数 */
    $sqlLog = $site . '/storage/logs/query-sql.log';
    $measure = static function (string $path, string $keep) use ($port, $counts, $sqlLog, $site): array {
        http($port, $path);
        @unlink($counts);
        @unlink($sqlLog);
        $r = http($port, $path);
        if (is_file($sqlLog)) rename($sqlLog, $site . '/sql-' . $keep . '.log');
        $lines = file_exists($counts) ? file($counts, FILE_IGNORE_NEW_LINES) : [];
        $last = (string) end($lines);
        // 列表页实际列出了多少条造出来的条目（确认每页条数设置真的生效）
        $items = count(array_unique(preg_match_all('#href="[^"]*qb-(?:article|case|product)-\d+#', $r['body'], $m) ? $m[0] : []));
        return [$r['status'], (int) substr($last, (int) strrpos($last, "\t") + 1), $items];
    };

    $table = [];
    $listed = [];
    foreach ([6, 24] as $size) {
        $check($drive('pagesize', (string) $size) === 'ok', "page size $size");
        foreach ($pages as $name => $path) {
            [$status, $n, $items] = $measure($path, $name . '-' . $size);
            $check($status === 200, "$name ($path) is 200 at page size $size", (string) $status);
            $table[$name][$size] = $n;
            $listed[$name][$size] = $items;
        }
    }
    echo "\npage            q@6   q@24  budget  items@6  items@24\n";
    foreach ($table as $name => $row) printf("%-14s %5d %6d %7d %8d %9d\n", $name, $row[6], $row[24], BUDGET[$name] ?? 0, $listed[$name][6], $listed[$name][24]);
    echo "\n";
    foreach ($listPages as $name) {
        $check($listed[$name][24] > $listed[$name][6], "$name lists more entries at page size 24 ({$listed[$name][6]} → {$listed[$name][24]})");
        $check($table[$name][24] - $table[$name][6] <= N1_SLACK, "$name has no per-item queries (6 → 24 items: {$table[$name][6]} → {$table[$name][24]})");
    }
    // 组件卡片页 vs 普通卡片页：同样 12 行循环，组件整页最多多一次查询（母版目录按请求只取一次）
    $componentRendered = substr_count(http($port, $seed['component_cards'])['body'], 'data-yk-component=');
    $check($componentRendered >= 12, "component loop renders one instance per row ($componentRendered)");
    $check($table['component-cards'][24] <= $table['plain-cards'][24] + 1,
        "component cards cost at most one extra query ({$table['plain-cards'][24]} → {$table['component-cards'][24]})");
    if (!$reportOnly) {
        foreach ($table as $name => $row) {
            $check($row[24] <= (BUDGET[$name] ?? 0), "$name stays within its budget ({$row[24]} ≤ " . (BUDGET[$name] ?? 0) . ')');
        }
    }
} catch (RuntimeException) {
    // 已在 $check 里记过失败
} finally {
    $pid = (int) (proc_get_status($proc)['pid'] ?? 0);
    if (PHP_OS_FAMILY === 'Windows' && $pid > 0) exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
    else proc_terminate($proc);
    proc_close($proc);
}

echo "\n" . ($fail === 0 ? 'ALL PASS' : $fail . ' FAILED') . "\n";
if ($fail === 0 && !$sqlMode) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($site);
} else {
    echo "kept sandbox $site\n";
}
exit($fail === 0 ? 0 : 1);
