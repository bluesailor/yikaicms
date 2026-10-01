<?php
/**
 * 语言域名模式端到端：一次性沙盒站点（当前工作树 + 真实安装器 + 演示数据），
 * 用不同 Host 头模拟主域名 main.test 与英文域名 en.test。
 * 用法：php tests/e2e/language-domains-sandbox.php <仓库目录> [端口]
 *   不给端口时自动挑空闲端口；E2E_SERVE=1 时测完保留服务器与沙盒，供浏览器查看后台页面
 *   （服务器进程号写在 %TEMP%/yk-langdom-serving.json，看完自行结束）。
 *   沙盒复制 git ls-files 列出的文件：新文件要先 git add。全部通过时删除沙盒，失败时保留供排查。
 */
declare(strict_types=1);

$repo = rtrim(str_replace('\\', '/', $argv[1] ?? ''), '/');
$port = (int) ($argv[2] ?? 0);
if ($port === 0) {   // 挑一个空闲端口（本机 nginx 占着 81xx 的一些端口）
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
    fclose($sock);
}
if (!is_file($repo . '/index.php')) { fwrite(STDERR, "bad repo\n"); exit(2); }
$php = PHP_BINARY;
$sandbox = str_replace('\\', '/', sys_get_temp_dir()) . '/yk-langdom-' . bin2hex(random_bytes(4));
mkdir($sandbox, 0700, true);
$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n     " . substr($detail, 0, 600)) . "\n";
    if (!$ok) $fail++;
};

// 1. 复制仓库文件
$files = explode("\0", trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files -z')));
$n = 0;
foreach ($files as $rel) {
    if ($rel === '' || preg_match('#^(?:tests|docs|\.github|node_modules)/#', $rel)) continue;
    $src = $repo . '/' . $rel;
    if (!is_file($src)) continue;
    $dst = $sandbox . '/' . $rel;
    if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0700, true);
    copy($src, $dst);
    $n++;
}
file_put_contents($sandbox . '/router.php', '<?php
$p = (string) parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($p !== "/" && is_file(__DIR__ . $p) && !str_ends_with($p, ".php")) return false;
if ($p !== "/" && is_file(__DIR__ . $p) && str_ends_with($p, ".php")) { $_SERVER["SCRIPT_NAME"] = $p; chdir(dirname(__DIR__ . $p)); require __DIR__ . $p; return true; }
if (is_dir(__DIR__ . $p) && is_file(rtrim(__DIR__ . $p, "/") . "/index.php")) { $f = rtrim(__DIR__ . $p, "/") . "/index.php"; $_SERVER["SCRIPT_NAME"] = rtrim($p, "/") . "/index.php"; chdir(dirname($f)); require $f; return true; }
$_SERVER["SCRIPT_NAME"] = "/index.php";
require __DIR__ . "/index.php";
');
echo "sandbox $sandbox ($n files)\n";

$log = $sandbox . '/server.log';
$proc = proc_open([$php, '-S', '127.0.0.1:' . $port, '-t', $sandbox, $sandbox . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $sandbox, null, ['bypass_shell' => true]);

$GLOBALS['e2eJar'] = $sandbox . '/cookies.txt';
$http = static function (string $method, string $host, string $path, array $post = []) use ($port): array {
    $ch = curl_init('http://127.0.0.1:' . $port . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => ['Host: ' . $host . ':' . $port],
        CURLOPT_COOKIEFILE => $GLOBALS['e2eJar'], CURLOPT_COOKIEJAR => $GLOBALS['e2eJar']]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hsize);
    preg_match('/^Location:\s*(.+)$/mi', $headers, $m);
    return ['status' => $status, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $hsize), 'headers' => $headers];
};

try {
    $ready = false;
    for ($i = 0; $i < 120 && !$ready; $i++) { usleep(250000); $probe = $http('GET', 'main.test', '/install/index.php'); $ready = $probe['status'] === 200 && stripos($probe['body'], 'yikai') !== false; }
    $check($ready, 'sandbox server up', (string) @file_get_contents($log));
    if (!$ready) exit(1);

    $main = 'http://main.test:' . $port;
    $en = 'http://en.test:' . $port;
    $adminUser = 'e2e' . bin2hex(random_bytes(2));
    $adminPass = 'E2e#' . bin2hex(random_bytes(8));
    file_put_contents($sandbox . '/e2e-admin.json', json_encode(['user' => $adminUser, 'pass' => $adminPass]));
    $inst = json_decode($http('POST', 'main.test', '/install/index.php', [
        'action' => 'install', 'db_driver' => 'sqlite', 'db_prefix' => 'yikai_', 'admin_user' => $adminUser,
        'admin_pass' => $adminPass, 'admin_email' => 'e2e@example.test', 'site_name' => 'E2E',
        'site_url' => $main, 'site_lang' => 'zh-CN', 'admin_lang' => 'zh-CN', 'install_demo' => '1'])['body'], true);
    $check(is_array($inst) && !empty($inst['success']), 'install with demo data', json_encode($inst));

    // 设置：启用三语 + 英文用 en.test
    $driver = $sandbox . '/e2e-driver.php';
    file_put_contents($driver, '<?php
define("ROOT_PATH", __DIR__);
$_SERVER["HTTP_HOST"] = $argv[2] ?? "";
require ROOT_PATH . "/config/config.php";
require ROOT_PATH . "/includes/functions.php";
require ROOT_PATH . "/includes/models/autoload.php";
require ROOT_PATH . "/includes/hooks.php";
if ($argv[1] === "setup") {
    settingModel()->set("enabled_languages", json_encode(["zh-CN", "en", "ja"]));
    settingModel()->set("show_lang_switcher", "1");
    settingModel()->set("url_mode", "pretty");   // 内置服务器 + router 等同 WordPress 式单入口
    settingModel()->set("language_domains", json_encode(["en" => $argv[3]]));
    settingModel()->set("language_domains_enabled", "1");
    echo "ok";
} elseif ($argv[1] === "answer") {
    echo LanguageDomains::probeAnswer($argv[3]);
} elseif ($argv[1] === "license") {
    require ROOT_PATH . "/includes/License.php";
    echo license_domain();
} elseif ($argv[1] === "static") {
    require ROOT_PATH . "/includes/StaticHtml.php";
    echo StaticHtml::enabled() ? "on" : "off";
}
');
    $run = static function (array $args) use ($php, $driver): string {
        return trim((string) shell_exec(escapeshellarg($php) . ' ' . escapeshellarg($driver) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1'));
    };
    $check($run(['setup', '', 'en.test:' . $port]) === 'ok', 'configure language domain en → en.test');

    // 2. 主域名上的 /en/ → 301 到英文域名
    $r = $http('GET', 'main.test', '/en/');
    $check($r['status'] === 301 && $r['location'] === $en . '/', 'main /en/ redirects to en.test', $r['status'] . ' ' . $r['location']);
    $r = $http('GET', 'main.test', '/en/contact.html?utm=x');
    $check($r['status'] === 301 && $r['location'] === $en . '/contact.html?utm=x', 'main /en/contact.html keeps path and query', $r['location']);

    // 3. 英文域名首页
    $r = $http('GET', 'en.test', '/');
    $body = $r['body'];
    $check($r['status'] === 200, 'en.test / is 200', $r['status'] . ' ' . substr($body, 0, 300));
    $check(preg_match('/<html[^>]*lang="en"/i', $body) === 1, 'en.test renders English (html lang=en)', substr($body, 0, 200));
    $check(str_contains($body, 'hreflang="en" href="' . $en . '/"'), 'hreflang en → en.test');
    $check(str_contains($body, 'hreflang="zh-CN" href="' . $main . '/"'), 'hreflang zh-CN → main');
    $check(str_contains($body, 'hreflang="ja" href="' . $main . '/ja/"'), 'hreflang ja → main /ja/');
    $check(str_contains($body, 'hreflang="x-default" href="' . $main . '/"'), 'x-default → main');
    preg_match_all('/<a\s[^>]*href="(\/en\/[^"]*)"/i', $body, $bad);
    $check($bad[1] === [], 'no /en/ prefixed links on en.test', implode(' ', array_slice($bad[1], 0, 5)));
    preg_match('/<link rel="canonical" href="([^"]+)"/i', $body, $canon);
    $check(($canon[1] ?? '') === '' || str_starts_with($canon[1], $en), 'canonical on en.test points to en.test', $canon[1] ?? '');

    // 4. 英文域名上的内页（取首页里第一个站内 .html 链接）
    preg_match('/<a\s[^>]*href="(\/[a-z0-9_\/-]+\.html)"/i', $body, $inner);
    if (isset($inner[1])) {
        $r = $http('GET', 'en.test', $inner[1]);
        if (in_array($r['status'], [301, 302], true) && str_starts_with($r['location'], '/')) {
            // 父栏目跳到首个子页：跳转必须留在 en.test（站内相对地址），再看落地页
            $r = $http('GET', 'en.test', $r['location']);
        }
        $check($r['status'] === 200 && preg_match('/<html[^>]*lang="en"/i', $r['body']) === 1, 'en.test inner page ' . $inner[1] . ' is English', $r['status'] . ' ' . $r['location']);
    } else {
        $check(false, 'found an internal .html link on en.test home');
    }

    // 5. 走错主机
    $r = $http('GET', 'en.test', '/ja/');
    $check($r['status'] === 301 && $r['location'] === $main . '/ja/', 'en.test /ja/ → main /ja/', $r['location']);
    $r = $http('GET', 'en.test', '/zh-CN/contact.html');
    $check($r['status'] === 301 && $r['location'] === $main . '/contact.html', 'en.test /zh-CN/x → main /x', $r['location']);
    $r = $http('GET', 'en.test', '/admin/login.php');
    $check(in_array($r['status'], [301, 302], true) && str_starts_with($r['location'], $main . '/admin/'), 'en.test /admin/ goes to main', $r['status'] . ' ' . $r['location']);

    // 6. 主域名：中文首页，切换到英文的链接是完整的 en.test 地址
    $r = $http('GET', 'main.test', '/');
    $check($r['status'] === 200 && preg_match('/<html[^>]*lang="zh-CN"/i', $r['body']) === 1, 'main / is Chinese');
    $check(str_contains($r['body'], 'href="' . $en . '/'), 'main page links to en.test with full URL');
    $check(!preg_match('/href="\/en\//', $r['body']), 'main page has no /en/ relative links');
    $r = $http('GET', 'main.test', '/ja/');
    $check($r['status'] === 200 && preg_match('/<html[^>]*lang="ja"/i', $r['body']) === 1, 'main /ja/ still Japanese (prefix mode)');

    // 7. sitemap 分主机
    $r = $http('GET', 'en.test', '/sitemap.php');
    preg_match_all('#<loc>([^<]+)</loc>#', $r['body'], $locs);
    $check($locs[1] !== [] && array_filter($locs[1], static fn($u) => !str_starts_with($u, $en)) === [], 'en.test sitemap lists only en.test URLs', implode(' ', array_slice($locs[1], 0, 4)));
    $r = $http('GET', 'main.test', '/sitemap.php');
    preg_match_all('#<loc>([^<]+)</loc>#', $r['body'], $locs);
    $check($locs[1] !== [] && array_filter($locs[1], static fn($u) => str_starts_with($u, $en) || str_contains($u, '/en/')) === [], 'main sitemap has no English URLs', (string) count($locs[1]));

    // 8. 检测端点
    $nonce = bin2hex(random_bytes(16));
    $r = $http('GET', 'en.test', '/index.php?yk_lang_domain_probe=' . $nonce);
    $data = json_decode($r['body'], true);
    $check(($data['answer'] ?? '') !== '' && $data['answer'] === $run(['answer', '', $nonce]), 'probe endpoint answers with this install\'s HMAC', $r['body']);
    $r = $http('GET', 'en.test', '/index.php?yk_lang_domain_probe=bad');
    $check($r['status'] === 404, 'probe rejects malformed nonce');

    // 9. 授权按主域名上报；静态直出关闭
    $check($run(['license', 'en.test:' . $port]) === 'main.test:' . $port, 'license_domain() reports the main domain on en.test', $run(['license', 'en.test:' . $port]));
    $check($run(['static', '']) === 'off', 'static HTML serving is off in domain mode');

    // 10. 后台：登录 → 语言设置页 → 保存校验 → 检测
    $token = static function (string $html): string {
        preg_match('/name="_token" value="([^"]+)"/', $html, $m);
        return $m[1] ?? '';
    };
    $login = $http('GET', 'main.test', '/admin/login.php');
    $r = $http('POST', 'main.test', '/admin/login.php', ['_token' => $token($login['body']), 'username' => $adminUser, 'password' => $adminPass]);
    $check(in_array($r['status'], [301, 302], true), 'admin login on main', $r['status'] . ' ' . $r['location']);
    $page = $http('GET', 'main.test', '/admin/setting_lang.php');
    $check($page['status'] === 200 && str_contains($page['body'], 'id="domainForm"') && str_contains($page['body'], 'value="en.test:' . $port . '"'),
        'settings page shows the language domain card with the saved domain', (string) $page['status']);
    $csrf = $token($page['body']);
    $post = static fn(array $fields) => json_decode($http('POST', 'main.test', '/admin/setting_lang.php', $fields + ['_token' => $csrf])['body'], true);
    $res = $post(['action' => 'save_domains', 'domains' => ['en' => 'main.test:' . $port]]);
    $check(($res['code'] ?? 0) !== 0, 'saving the main domain as a language domain is refused', json_encode($res, JSON_UNESCAPED_UNICODE));
    $res = $post(['action' => 'save_domains', 'domains' => ['en' => 'bad host!']]);
    $check(($res['code'] ?? 0) !== 0, 'saving an invalid host is refused', json_encode($res, JSON_UNESCAPED_UNICODE));
    $res = $post(['action' => 'save_domains', 'language_domains_enabled' => '1', 'domains' => ['en' => '', 'ja' => '']]);
    $check(($res['code'] ?? 0) !== 0, 'turning the switch on without any domain is refused', json_encode($res, JSON_UNESCAPED_UNICODE));
    $res = $post(['action' => 'save_domains', 'domains' => ['en' => 'EN.test:' . $port, 'ja' => '']]);
    $check(($res['code'] ?? 1) === 0, 'saving with the switch off keeps the domain', json_encode($res, JSON_UNESCAPED_UNICODE));
    $r = $http('GET', 'main.test', '/en/');
    $check($r['status'] === 200 && preg_match('/<html[^>]*lang="en"/i', $r['body']) === 1, 'switch off: /en/ on the main domain is served again', $r['status'] . ' ' . $r['location']);
    $r = $http('GET', 'en.test', '/');
    $check(preg_match('/<html[^>]*lang="zh-CN"/i', $r['body']) === 1, 'switch off: the language domain no longer picks English');
    $res = $post(['action' => 'save_domains', 'language_domains_enabled' => '1', 'domains' => ['en' => 'EN.test:' . $port, 'ja' => '']]);
    $check(($res['code'] ?? 1) === 0, 'saving a valid domain works', json_encode($res, JSON_UNESCAPED_UNICODE));
    $res = $post(['action' => 'probe_domain', 'host' => 'no-such-host.invalid']);
    $check(($res['data']['result'] ?? '') === 'unreachable', 'probe reports an unresolvable domain as unreachable', json_encode($res, JSON_UNESCAPED_UNICODE));
    $r = $http('GET', 'main.test', '/en/');
    $check($r['status'] === 301 && $r['location'] === $en . '/', 'redirect still works after saving through the admin', $r['location']);

    $errors = array_filter(file($log) ?: [], static fn($l) => preg_match('/PHP (Fatal|Warning|Parse)/', $l));
    $check($errors === [], 'no PHP fatals or warnings in server log', implode('', array_slice($errors, 0, 5)));
} finally {
    if (getenv('E2E_SERVE') === '1' && $fail === 0) {
        // 手动查看：服务器留着，沙盒保留；看完用 E2E_STOP 结束
        file_put_contents(sys_get_temp_dir() . '/yk-langdom-serving.json', json_encode(['port' => $port, 'sandbox' => $sandbox, 'pid' => proc_get_status($proc)['pid']]));
        echo "SERVING http://127.0.0.1:$port sandbox $sandbox
";
        exit(0);
    }
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED (sandbox kept: $sandbox)\n";
    if ($fail === 0) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
        @rmdir($sandbox);
    }
}
exit($fail === 0 ? 0 : 1);
