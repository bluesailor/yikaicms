<?php
/**
 * 语言前缀改写规则在真实 Apache 上的端到端验证（子目录安装，%{ENV:YK_BASE} 生效）：
 *   1. 随包通用规则：装了语言包的 ko 能按 /ko/ 访问；
 *   2. 换回老站写死语言的规则：ko 打不开、en 照常；
 *   3. LanguageRouting::fixHtaccess 一键更新后 ko 恢复，restore 能还原；
 *   4. 语言域名模式在子目录安装下的跳转带上挂载目录。
 *
 * 用法：php tests/e2e/language-routing-apache.php <仓库目录> <Web 根目录> <Web 根对应的网址>
 *   例：php tests/e2e/language-routing-apache.php D:/…/yikaicms.i18n D:/phpstudy_pro/WWW http://127.0.0.1
 *   Web 根所在的 Apache 站点须 AllowOverride All，且未知 Host 落到同一站点（默认虚拟主机）。
 *   在 Web 根下建一个临时子目录，结束时删除（失败时保留供排查）。
 */
declare(strict_types=1);

[$self, $repo, $webRoot, $webUrl] = array_pad($argv, 4, '');
$repo = rtrim(str_replace('\\', '/', $repo), '/');
$webRoot = rtrim(str_replace('\\', '/', $webRoot), '/');
$webUrl = rtrim($webUrl, '/');
if (!is_file($repo . '/index.php') || !is_dir($webRoot) || !preg_match('#^https?://#', $webUrl)) {
    fwrite(STDERR, "usage: php language-routing-apache.php <repo> <web-root> <web-url>\n");
    exit(2);
}
$sub = '_yk_langroute_' . bin2hex(random_bytes(3));
$dir = $webRoot . '/' . $sub;
$base = $webUrl . '/' . $sub;
$fail = 0;
$check = static function (bool $ok, string $label, string $detail = '') use (&$fail): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $detail === '' ? '' : "\n     " . substr($detail, 0, 500)) . "\n";
    if (!$ok) $fail++;
};
$host = (string) parse_url($webUrl, PHP_URL_HOST);
$http = static function (string $url, array $post = [], string $hostHeader = '') {
    $ch = curl_init($url);
    $headers = $hostHeader !== '' ? ['Host: ' . $hostHeader] : [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 60, CURLOPT_PROXY => '', CURLOPT_HTTPHEADER => $headers]);
    if ($post !== []) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
    $raw = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $size), $m);
    return ['status' => $status, 'location' => trim($m[1] ?? ''), 'body' => substr($raw, $size)];
};

try {
    foreach (explode("\0", trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' ls-files -z'))) as $rel) {
        if ($rel === '' || preg_match('#^(?:tests|docs|\.github|node_modules)/#', $rel) || !is_file($repo . '/' . $rel)) continue;
        if (!is_dir(dirname($dir . '/' . $rel))) mkdir(dirname($dir . '/' . $rel), 0755, true);
        copy($repo . '/' . $rel, $dir . '/' . $rel);
    }
    // 一个「新语言」的语言包：内容随意，只要文件存在，通用规则就认它
    file_put_contents($dir . '/lang/ko.php', "<?php\nreturn ['home' => '홈'];\n");

    $adminUser = 'rt' . bin2hex(random_bytes(2));
    $adminPass = 'Rt#' . bin2hex(random_bytes(8));
    $inst = json_decode($http($base . '/install/index.php', [
        'action' => 'install', 'db_driver' => 'sqlite', 'db_prefix' => 'yikai_', 'admin_user' => $adminUser,
        'admin_pass' => $adminPass, 'admin_email' => 'rt@example.test', 'site_name' => 'Routing',
        'site_url' => $base, 'site_lang' => 'zh-CN', 'admin_lang' => 'zh-CN', 'install_demo' => '1'])['body'], true);
    $check(is_array($inst) && !empty($inst['success']), 'install in a subdirectory under Apache', json_encode($inst, JSON_UNESCAPED_UNICODE));

    $driver = $dir . '/rt-driver.php';
    file_put_contents($driver, '<?php
define("ROOT_PATH", __DIR__);
require ROOT_PATH . "/config/config.php";
require ROOT_PATH . "/includes/functions.php";
require ROOT_PATH . "/includes/models/autoload.php";
switch ($argv[1]) {
    case "setup":
        settingModel()->set("enabled_languages", json_encode(["zh-CN", "en", "ja", "ko"]));
        settingModel()->set("url_mode", "pretty");
        echo "ok"; break;
    case "domains":
        settingModel()->set("language_domains", json_encode(["en" => "en.test"]));
        settingModel()->set("language_domains_enabled", "1");
        echo "ok"; break;
    case "nodomains":
        settingModel()->set("language_domains_enabled", "0");
        echo "ok"; break;
    case "status": echo json_encode(LanguageRouting::htaccessStatus(ROOT_PATH)); break;
    case "fix": echo json_encode(LanguageRouting::fixHtaccess(ROOT_PATH, ROOT_PATH . "/storage/backups/htaccess")); break;
    case "restore": echo LanguageRouting::restoreHtaccess(ROOT_PATH, ROOT_PATH . "/storage/backups/htaccess", $argv[2]) ? "ok" : "no"; break;
}
');
    $run = static fn(array $args): string => trim((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($driver) . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1'));
    $check($run(['setup']) === 'ok', 'enable zh-CN, en, ja, ko with pretty URLs');

    $probe = static function (string $code) use ($http, $base): string {
        $n = bin2hex(random_bytes(16));
        $d = json_decode($http($base . '/' . $code . '/contact.html?yk_lang_route_probe=' . $n)['body'], true);
        return is_array($d) && ($d['nonce'] ?? '') === $n ? (string) ($d['lang'] ?? '') : '?';
    };

    // 1. 随包通用规则
    $check($run(['status']) === '{"state":"current","codes":[]}', 'shipped .htaccess is the generic rule', $run(['status']));
    foreach (['en', 'ja', 'ko'] as $code) $check($probe($code) === $code, "generic rule routes /$code/", $probe($code));
    $page = $http($base . '/ko/');
    $check($page['status'] === 200 && preg_match('/<html[^>]*lang="ko"/i', $page['body']) === 1, '/ko/ renders the Korean site', (string) $page['status']);
    $page = $http($base . '/en/contact.html');
    $check($page['status'] === 200 && preg_match('/<html[^>]*lang="en"/i', $page['body']) === 1, '/en/contact.html renders English', (string) $page['status']);

    // 2. 换回老站写死语言的规则（2.0 发布时的写法）
    $ht = (string) file_get_contents($dir . '/.htaccess');
    $legacy = preg_replace('/^[ \t]*# 多语言 URL 前缀.*?\n(?:[ \t]*#.*\n)*[ \t]*RewriteCond %\{DOCUMENT_ROOT\}%\{ENV:YK_BASE\}lang\/\$1\.php -f\n[ \t]*RewriteRule \^\(\[a-z\]\{2\}.*\n/m',
        // 替换串里的 $ 写成 \$，否则被 preg_replace 当成反向引用
        '    # 多语言 URL 前缀：/ja/... /en/... /zh-CN/... /zh-TW/... → 剥离前缀，设 lang 参数' . "\n"
        . '    RewriteRule ^(ja|en|zh-CN|zh-TW)/(.*)\$ %{ENV:YK_BASE}\$2?_lang=\$1 [QSA,L,DPI]' . "\n", $ht, 1, $count);
    $check($count === 1, 'swap in the legacy language rule');
    file_put_contents($dir . '/.htaccess', $legacy);
    $check(str_starts_with($run(['status']), '{"state":"legacy"'), 'legacy rule is recognized', $run(['status']));
    $check($probe('en') === 'en' && $probe('ko') !== 'ko', 'legacy rule: en works, ko does not', 'en=' . $probe('en') . ' ko=' . $probe('ko'));

    // 3. 一键更新 → 恢复；撤销 → 回到老规则
    $fix = json_decode($run(['fix']), true);
    $check(is_array($fix) && $fix['ok'] === true, 'fixHtaccess succeeds with a backup', json_encode($fix));
    foreach (['en', 'ja', 'ko'] as $code) $check($probe($code) === $code, "after the fix /$code/ routes", $probe($code));
    $check(str_contains((string) file_get_contents($dir . '/.htaccess'), '# Sitemap'), 'the rest of .htaccess is untouched');
    $check($run(['restore', (string) ($fix['backup'] ?? '')]) === 'ok' && $probe('ko') !== 'ko' && $probe('en') === 'en', 'restore brings the legacy rule back');
    $run(['fix']);

    // 4. 语言域名 + 子目录：跳转带上挂载目录；未知 Host 落到同一站点
    $check($run(['domains']) === 'ok', 'turn on en → en.test');
    $r = $http($base . '/en/contact.html');
    $check($r['status'] === 301 && $r['location'] === 'http://en.test/' . $sub . '/contact.html', 'main /SUB/en/x → en.test/SUB/x', $r['status'] . ' ' . $r['location']);
    $r = $http('http://127.0.0.1/' . $sub . '/', [], 'en.test');
    $check($r['status'] === 200 && preg_match('/<html[^>]*lang="en"/i', $r['body']) === 1, 'en.test/SUB/ renders English under Apache', $r['status'] . ' ' . $r['location']);
    $check(str_contains($r['body'], 'hreflang="x-default" href="' . $base . '/"'), 'x-default keeps the mount directory on the main host');

    if (getenv('RT_KEEP_LEGACY') === '1') {
        // 手动查看后台「语言网址检查」：留下老规则、关掉语言域名，站点保留
        file_put_contents($dir . '/.htaccess', $legacy);
        $run(['nodomains']);
        file_put_contents($dir . '/rt-admin.json', json_encode(['user' => $adminUser, 'pass' => $adminPass, 'url' => $base]));
    }
} finally {
    echo $fail === 0 ? "\nALL PASSED\n" : "\n$fail FAILED (kept: $dir)\n";
    if ($fail === 0 && is_dir($dir) && getenv('RT_KEEP_LEGACY') !== '1') {
        for ($try = 0; $try < 5 && is_dir($dir); $try++) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($dir);
            if (is_dir($dir)) usleep(500000);
        }
        if (is_dir($dir)) echo "note: could not fully delete $dir (files still locked by the web server)\n";
    }
}
exit($fail === 0 ? 0 : 1);
