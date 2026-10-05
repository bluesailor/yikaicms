<?php
/**
 * 网址 / 路由 / 301 回归矩阵（2.0.5 §5.3a，P0）：一次性沙盒站点（当前工作树 git ls-files + 真实安装器 + 演示数据），
 * 只看 HTTP 层（状态码、Location、canonical、hreflang、站点地图、正文里的链接），不开浏览器。
 *
 * 维度两两组合（不做全排列）：部署（根目录 / 子目录 /sub）× 网址模式（伪静态 / 动态）× 语言（单语 / 前缀 / 语言域名），
 * 共 6 组（语言域名只与伪静态组合，动态网址模式不支持语言域名）；每组里四类网址（默认 / 自定义登记 / 301 跳转 / WordPress 旧网址 ?p=）都测。断言：
 *   旧网址一跳 301 到规范网址且保留查询串；canonical 自洽（规范网址本身 200、不再跳）；hreflang 目标都直接 200；
 *   站点地图只出规范网址（跳转来源、被登记网址取代的默认网址、回收站内容都不进，条目都直接 200）；
 *   分页链接直接 200、不退回旧路径；目录级跳转不成环；子目录不出现重复前缀 /sub/sub。
 *
 * 用法：php tests/e2e/url-matrix-sandbox.php <仓库目录> [只跑的组号,逗号分隔]
 *   沙盒复制 git ls-files 列出的文件：新文件要先 git add。全部通过时删除沙盒，失败时保留供排查。
 */
declare(strict_types=1);

$repo = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
$only = isset($argv[2]) ? array_map('intval', explode(',', $argv[2])) : [];
if (!is_file($repo . '/index.php')) { fwrite(STDERR, "bad repo\n"); exit(2); }
$php = PHP_BINARY;

$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n     " . substr($detail, 0, 700)) . "\n";
    if (!$ok) $fail++;
};

// 两两组合：每对取值至少同时出现一次
$matrix = [
    1 => ['deploy' => 'root', 'mode' => 'pretty', 'lang' => 'single'],
    2 => ['deploy' => 'root', 'mode' => 'query', 'lang' => 'prefix'],
    3 => ['deploy' => 'root', 'mode' => 'pretty', 'lang' => 'domain'],
    4 => ['deploy' => 'sub', 'mode' => 'query', 'lang' => 'single'],
    5 => ['deploy' => 'sub', 'mode' => 'pretty', 'lang' => 'prefix'],
    // 语言域名只支持伪静态（后台在动态网址模式下拒绝开启，slang_domain_need_pretty_urls），不组合「动态 × 语言域名」
    6 => ['deploy' => 'sub', 'mode' => 'pretty', 'lang' => 'domain'],
];
if ($only !== []) $matrix = array_intersect_key($matrix, array_flip($only));

$freePort = static function (): int {
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
    return $port;
};

/** 建一个部署：复制文件、写路由、起服务器、安装演示站。返回 [沙盒根, 站点目录, 端口, 进程, 前缀] */
$deploy = static function (string $kind) use ($repo, $php, $freePort, $check): ?array {
    $web = str_replace('\\', '/', sys_get_temp_dir()) . '/yk-urlmatrix-' . $kind . '-' . bin2hex(random_bytes(4));
    $base = $kind === 'sub' ? '/sub' : '';
    $site = $web . $base;
    mkdir($site, 0700, true);
    $files = explode("\0", trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files -z')));
    foreach ($files as $rel) {
        if ($rel === '' || preg_match('#^(?:tests|docs|\.github|node_modules)/#', $rel)) continue;
        if (!is_file($repo . '/' . $rel)) continue;
        $dst = $site . '/' . $rel;
        if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0700, true);
        copy($repo . '/' . $rel, $dst);
    }
    // 单入口路由（等同伪静态重写）：真实文件直出，.php 直接执行，其余交给站点的 index.php；子目录外的请求 404
    file_put_contents($web . '/router.php', '<?php
$base = ' . var_export($base, true) . ';
$p = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($base !== "" && $p !== $base && !str_starts_with($p, $base . "/")) { http_response_code(404); echo "outside"; return true; }
$run = static function (string $rel): bool {
    $f = __DIR__ . $rel;
    $_SERVER["SCRIPT_NAME"] = $rel; $_SERVER["SCRIPT_FILENAME"] = $f; $_SERVER["PHP_SELF"] = $rel;
    chdir(dirname($f)); require $f; return true;
};
if ($p !== "/" && is_file(__DIR__ . $p) && !str_ends_with($p, ".php")) return false;
if ($p !== "/" && is_file(__DIR__ . $p)) return $run($p);
if (is_dir(__DIR__ . $p) && is_file(rtrim(__DIR__ . $p, "/") . "/index.php") && rtrim($p, "/") !== $base) return $run(rtrim($p, "/") . "/index.php");
return $run($base . "/index.php");
');
    $port = $freePort();
    $log = $web . '/server.log';
    $proc = proc_open([$php, '-S', '127.0.0.1:' . $port, '-t', $web, $web . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $web, null, ['bypass_shell' => true]);
    $ready = false;
    for ($i = 0; $i < 120 && !$ready; $i++) {
        usleep(250000);
        $probe = http('GET', 'main.test', $port, $base . '/install/index.php');
        $ready = $probe['status'] === 200 && stripos($probe['body'], 'yikai') !== false;
    }
    $check($ready, "[$kind] sandbox server up", (string) @file_get_contents($log));
    if (!$ready) return null;
    $inst = json_decode(http('POST', 'main.test', $port, $base . '/install/index.php', [
        'action' => 'install', 'db_driver' => 'sqlite', 'db_prefix' => 'yikai_', 'admin_user' => 'e2e' . bin2hex(random_bytes(2)),
        'admin_pass' => 'E2e#' . bin2hex(random_bytes(8)), 'admin_email' => 'e2e@example.test', 'site_name' => 'E2E',
        'site_url' => 'http://main.test:' . $port . $base, 'site_lang' => 'zh-CN', 'admin_lang' => 'zh-CN', 'install_demo' => '1'])['body'], true);
    $check(is_array($inst) && !empty($inst['success']), "[$kind] install with demo data", json_encode($inst));
    if (!is_array($inst) || empty($inst['success'])) return null;
    file_put_contents($site . '/e2e-driver.php', DRIVER);
    return ['web' => $web, 'site' => $site, 'port' => $port, 'proc' => $proc, 'base' => $base];
};

function http(string $method, string $host, int $port, string $path, array $post = []): array
{
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Host: ' . $host . ':' . $port]]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hsize);
    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);
    return ['status' => $status, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $hsize), 'headers' => $headers];
}

// 沙盒里的命令行驱动：配置组合、造数据、算期望网址
const DRIVER = <<<'PHP'
<?php
define("ROOT_PATH", __DIR__);
$_SERVER["HTTP_HOST"] = $argv[2] ?? "";
require ROOT_PATH . "/config/config.php";
require ROOT_PATH . "/includes/functions.php";
require ROOT_PATH . "/includes/models/autoload.php";
require ROOT_PATH . "/includes/hooks.php";
$t = DB_PREFIX;
if ($argv[1] === "seed") {
    $migration = require ROOT_PATH . "/migrations/20260910_product_custom_urls.php";
    ($migration["php"])();
    // 有英文译文的文章 A（登记自定义网址）、另一篇 B（只走默认网址）、回收站里的 C
    $a = db()->fetchOne("SELECT c.* FROM {$t}contents c WHERE c.type = 'article' AND c.lang = 'zh-CN' AND c.status = 1 AND c.deleted_at IS NULL
        AND EXISTS (SELECT 1 FROM {$t}contents x WHERE x.translation_group_id = c.translation_group_id AND x.lang = 'en' AND x.status = 1) ORDER BY c.id LIMIT 1");
    $b = db()->fetchOne("SELECT * FROM {$t}contents WHERE type = 'article' AND lang = 'zh-CN' AND status = 1 AND deleted_at IS NULL AND id <> ? ORDER BY id LIMIT 1", [(int) $a["id"]]);
    if (!$a || !$b) { echo "{}"; exit(1); }
    // 回收站里的 C：自建一篇没有译文的（演示文章都有译文；有译文时删掉中文版按严格策略去英文版，那是对的）
    $c = $b; unset($c["id"]);
    $c = array_merge($c, ["slug" => "matrix-recycled", "title" => "Matrix recycled", "translation_group_id" => 0]);
    $c["id"] = (int) db()->insert("contents", $c);
    db()->update("contents", ["translation_group_id" => $c["id"]], "id = ?", [$c["id"]]);
    $defaultA = contentDefaultPrettyUrl($a);
    productRouteModel()->assign("content", (int) $a["id"], "/guides/matrix-custom/", "zh-CN");
    db()->execute("UPDATE {$t}contents SET deleted_at = ? WHERE id = ?", [time(), (int) $c["id"]]);
    // 新闻栏目凑够分页（演示数据只有几篇），再登记网址：分页链接要跟着走
    $copy = $b; unset($copy["id"]);
    $copy["channel_id"] = (int) db()->fetchColumn("SELECT id FROM {$t}channels WHERE slug = 'news' AND lang = 'zh-CN'");
    for ($i = 1; $i <= 30; $i++) {
        $id = (int) db()->insert("contents", array_merge($copy, ["slug" => "matrix-filler-" . $i, "title" => "Matrix filler " . $i, "translation_group_id" => 0]));
        db()->update("contents", ["translation_group_id" => $id], "id = ?", [$id]);
    }
    $news = db()->fetchOne("SELECT * FROM {$t}channels WHERE slug = 'news' AND lang = 'zh-CN'");
    productRouteModel()->assign("channel", (int) $news["id"], "/category/news/", "zh-CN");
    // 301：单条（带查询串）与目录级前缀规则（目标在规则之外，不成环）
    Redirects::save(0, "/old-page.html", contentDefaultPrettyUrl($b), 301);
    Redirects::save(0, "/old-dir/*", "/category/news/", 301);
    // WordPress 旧网址：?p=4242 → 文章 A
    setMeta("wp_import", 4242, "post", (string) $a["id"]);
    settingModel()->set("wp_legacy_urls", "1");
    settingModel()->set("html_cache_enabled", "0");
    settingModel()->rotateHtmlCacheGeneration();
    echo json_encode(["a" => (int) $a["id"], "aSlug" => $a["slug"], "defaultA" => $defaultA, "b" => (int) $b["id"], "bSlug" => $b["slug"],
        "defaultB" => contentDefaultPrettyUrl($b), "cSlug" => $c["slug"]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} elseif ($argv[1] === "configure") {
    [$mode, $lang, $enHost] = [$argv[3], $argv[4], $argv[5] ?? ""];
    settingModel()->set("url_mode", $mode);
    settingModel()->set("enabled_languages", json_encode($lang === "single" ? ["zh-CN"] : ["zh-CN", "en", "ja"]));
    settingModel()->set("show_lang_switcher", $lang === "single" ? "0" : "1");
    settingModel()->set("language_domains", json_encode($lang === "domain" ? ["en" => $enHost] : []));
    settingModel()->set("language_domains_enabled", $lang === "domain" ? "1" : "0");
    settingModel()->rotateHtmlCacheGeneration();
    echo "ok";
}
PHP;

$deployments = [];
try {
    foreach ($matrix as $no => $combo) {
        $label = "#$no {$combo['deploy']}/{$combo['mode']}/{$combo['lang']}";
        echo "\n== $label ==\n";
        if (!isset($deployments[$combo['deploy']])) {
            $d = $deploy($combo['deploy']);
            if ($d === null) { $check(false, "$label deployment"); continue; }
            $deployments[$combo['deploy']] = $d;   // 先登记，失败也能在 finally 里结束服务器
            $seed = json_decode(trim((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($d['site'] . '/e2e-driver.php') . ' seed ""')), true);
            $check(is_array($seed) && isset($seed['a']), "[{$combo['deploy']}] seed data", json_encode($seed));
            if (!is_array($seed) || !isset($seed['a'])) continue;
            $deployments[$combo['deploy']]['seed'] = $seed;
        }
        $d = $deployments[$combo['deploy']];
        if (!isset($d['seed'])) continue;
        $s = $d['seed'];
        $port = $d['port'];
        $B = $d['base'];
        $mainOrigin = 'http://main.test:' . $port;
        $enHost = 'en.test:' . $port;
        $configured = trim((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($d['site'] . '/e2e-driver.php') . ' configure "" '
            . escapeshellarg($combo['mode']) . ' ' . escapeshellarg($combo['lang']) . ' ' . escapeshellarg($enHost)));
        $check($configured === 'ok', "$label configure", $configured);

        // 绝对 / 根相对地址 → [主机, 路径]
        $split = static function (string $url) use ($port): array {
            if (preg_match('#^https?://([^/:]+)(?::\d+)?(/.*)?$#', $url, $m)) return [$m[1], $m[2] ?? '/'];
            return ['main.test', $url];
        };
        $get = static fn(string $url): array => http('GET', ...[$split($url)[0], $port, $split($url)[1]]);
        $canonicalOf = static function (string $body): string {
            return preg_match('/<link rel="canonical" href="([^"]+)"/i', $body, $m) ? html_entity_decode($m[1]) : '';
        };
        $noDouble = static function (array $r, string $what) use ($check, $B, $label): void {
            if ($B === '') return;
            $hit = str_contains($r['location'], $B . $B . '/') || str_contains($r['body'], '"' . $B . $B . '/');
            $check(!$hit, "$label no duplicated $B prefix: $what", $r['location']);
        };
        /** 跟跳转（最多 5 跳），返回 [最终响应, 跳数, 经过的地址] */
        $follow = static function (string $url) use ($get): array {
            $seen = [$url];
            $r = $get($url);
            for ($hops = 0; in_array($r['status'], [301, 302], true) && $hops < 5; $hops++) {
                $next = $r['location'];
                if (!preg_match('#^https?://#', $next)) $next = (str_starts_with($next, '/') ? '' : '/') . $next;
                if (in_array($next, $seen, true)) return [$r, 99, $seen];
                $seen[] = $next;
                $r = $get($next);
            }
            return [$r, count($seen) - 1, $seen];
        };

        // 1. 文章 A：自定义网址是规范网址
        $customA = $B . '/guides/matrix-custom/';
        $r = http('GET', 'main.test', $port, $customA);
        $check($r['status'] === 200, "$label custom URL serves A", $r['status'] . ' ' . $r['location']);
        $canonA = $canonicalOf($r['body']);
        if ($combo['mode'] === 'pretty') $check($canonA !== '' && str_ends_with($canonA, $customA) && !str_contains($canonA, '?'), "$label canonical of A is the custom URL", $canonA);
        else $check(str_contains($canonA, $B . '/index.php?yk_route=article&') && !str_contains($canonA, 'lang='), "$label canonical of A is its dynamic URL", $canonA);
        $r = $get($canonA);
        $check($r['status'] === 200, "$label canonical of A is 200 directly", $canonA . ' → ' . $r['status'] . ' ' . $r['location']);
        $noDouble($r, 'A page');
        // 默认网址 → 一跳 301 到自定义网址，保留查询串
        $r = http('GET', 'main.test', $port, $B . $s['defaultA'] . '?utm=matrix');
        if ($combo['mode'] === 'pretty') $check($r['status'] === 301 && str_ends_with($split($r['location'])[1], $customA . '?utm=matrix'), "$label default URL of A 301s to custom with query", $r['status'] . ' ' . $r['location']);
        $noDouble($r, 'A default redirect');
        // 动态入口（与伪静态同一条目）
        $r = http('GET', 'main.test', $port, $B . '/index.php?yk_route=article&slug=' . rawurlencode($s['aSlug']));
        [$final] = $follow($B . '/index.php?yk_route=article&slug=' . rawurlencode($s['aSlug']));
        $check($final['status'] === 200 && $canonicalOf($final['body']) === $canonA, "$label dynamic entry reaches the same canonical A", $r['status'] . ' ' . $r['location'] . ' → ' . $canonicalOf($final['body']));

        // 2. hreflang 目标都直接 200
        $page = http('GET', 'main.test', $port, $customA);
        preg_match_all('/<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"/i', $page['body'], $alts, PREG_SET_ORDER);
        if ($combo['lang'] === 'single') {
            $check($alts === [], "$label single language has no hreflang", (string) count($alts));
        } else {
            $langs = array_column($alts, 1);
            $check(in_array('en', $langs, true) && in_array('zh-CN', $langs, true), "$label A lists zh-CN and en hreflang", implode(',', $langs));
            foreach ($alts as [, $hl, $href]) {
                $href = html_entity_decode($href);
                $r = $get($href);
                $check($r['status'] === 200, "$label hreflang $hl target is 200 directly", $href . ' → ' . $r['status'] . ' ' . $r['location']);
                $noDouble($r, "hreflang $hl");
                if ($hl === 'en' && $combo['lang'] === 'domain') $check(str_starts_with($href, 'http://' . $enHost . $B . '/'), "$label en hreflang uses the language domain", $href);
                if ($hl === 'en' && $combo['lang'] === 'prefix') $check($combo['mode'] === 'query' ? str_contains($href, 'lang=en') : str_contains($href, $B . '/en/'), "$label en hreflang carries the language", $href);
            }
        }

        // 3. 301 跳转：单条保留查询串；目录级规则不成环
        $r = http('GET', 'main.test', $port, $B . '/old-page.html?ref=x');
        $check($r['status'] === 301 && str_contains($r['location'], '?ref=x'), "$label redirect keeps the query string", $r['status'] . ' ' . $r['location']);
        $noDouble($r, 'redirect');
        [$final, $hops] = $follow($B . '/old-page.html?ref=x');
        $check($final['status'] === 200 && $hops <= 2, "$label redirect lands on a 200 page within 2 hops", $hops . ' hops, ' . $final['status']);
        [$final, $hops, $trail] = $follow($B . '/old-dir/some/thing.html');
        $check($hops !== 99 && $final['status'] === 200, "$label directory redirect reaches a page without looping", implode(' → ', $trail) . ' = ' . $final['status']);

        // 4. WordPress 旧网址 ?p=
        [$final, $hops, $trail] = $follow($B . '/?p=4242');
        $check($final['status'] === 200 && $hops >= 1 && $hops <= 2 && $canonicalOf($final['body']) === $canonA, "$label WordPress ?p= reaches A", implode(' → ', $trail));

        // 5. 站点地图：只出规范网址，条目直接 200
        $sm = http('GET', 'main.test', $port, $B . '/sitemap.xml');
        $check($sm['status'] === 200, "$label sitemap.xml is served", (string) $sm['status']);
        preg_match_all('#<loc>([^<]+)</loc>#', $sm['body'], $locs);
        $locs = array_map('html_entity_decode', $locs[1]);
        $check($locs !== [], "$label sitemap has entries");
        $check(in_array($canonA, $locs, true), "$label sitemap lists A's canonical URL", $canonA);
        $bad = array_values(array_filter($locs, static fn(string $u): bool => str_contains($u, '/old-page.html') || str_contains($u, '/old-dir/')
            || str_ends_with($u, $B . $s['defaultA']) || preg_match('#[/=]' . preg_quote((string) $s['cSlug'], '#') . '(?:\.html|&|/|$)#', $u) === 1 || ($B !== '' && str_contains($u, $B . $B . '/'))));
        $check($bad === [], "$label sitemap has no redirect sources, superseded or recycled URLs", implode(' ', $bad));
        $broken = [];
        foreach (array_slice($locs, 0, 40) as $loc) {
            $r = $get($loc);
            if ($r['status'] !== 200) $broken[] = $loc . ' → ' . $r['status'] . ' ' . $r['location'];
        }
        $check($broken === [], "$label sitemap entries (first 40) are 200 directly", implode("\n     ", $broken));
        if ($combo['lang'] === 'domain') {
            $sm = http('GET', 'en.test', $port, $B . '/sitemap.xml');
            preg_match_all('#<loc>([^<]+)</loc>#', $sm['body'], $enLocs);
            $off = array_filter(array_map('html_entity_decode', $enLocs[1]), static fn(string $u): bool => !str_starts_with($u, 'http://' . $enHost . $B . '/'));
            $check($enLocs[1] !== [] && $off === [], "$label language-domain sitemap lists only its own host", implode(' ', array_slice($off, 0, 3)));
        }

        // 6. 回收站内容不可访问
        [$final] = $follow($B . '/index.php?yk_route=article&slug=' . rawurlencode($s['cSlug']));
        $check($final['status'] === 404, "$label recycled article is 404", (string) $final['status']);

        // 7. 分页：新闻列表第 1 页里的分页链接直接 200，伪静态下跟着登记网址
        [$list, , $trail] = $follow($B . ($combo['mode'] === 'pretty' ? '/category/news/' : '/index.php?yk_route=news'));
        $check($list['status'] === 200, "$label news list is served", implode(' → ', $trail) . ' = ' . $list['status']);
        if (preg_match_all('/href="([^"]*(?:page[=\/-]|_)2[^"]*)"/i', $list['body'], $pages) && $pages[1] !== []) {
            $next = html_entity_decode($pages[1][0]);
            $r = $get($next);
            $check($r['status'] === 200, "$label page-2 link is 200 directly", $next . ' → ' . $r['status'] . ' ' . $r['location']);
            if ($combo['mode'] === 'pretty') $check(str_contains($next, '/category/news/'), "$label page-2 link keeps the registered channel path", $next);
            $noDouble($r, 'page 2');
        } else {
            echo "     (no page-2 link on news list — skipped)\n";
        }
    }
} finally {
    foreach ($deployments as $d) {
        // Windows 上 proc_terminate 结束不了 php -S，服务器会一直挂着：按进程号连子进程一起结束
        $pid = (int) (proc_get_status($d['proc'])['pid'] ?? 0);
        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) exec('taskkill /F /T /PID ' . $pid . ' 2>NUL');
        else proc_terminate($d['proc']);
        proc_close($d['proc']);
    }
}

echo "\n" . ($fail === 0 ? 'ALL PASS' : $fail . ' FAILED') . "\n";
foreach ($deployments as $d) {
    if ($fail === 0) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d['web'], FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($d['web']);
    } else {
        echo "kept sandbox {$d['web']} (port {$d['port']} stopped)\n";
    }
}
exit($fail === 0 ? 0 : 1);
