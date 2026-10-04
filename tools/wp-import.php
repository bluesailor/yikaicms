<?php
/**
 * WordPress → YikaiCMS 导入（命令行）。读 WordPress 数据库：先把原站的数据库备份导入本机 MySQL，再运行本工具。
 *
 * 用法（在站点根目录）：
 *   php tools/wp-import.php --dsn="mysql:host=127.0.0.1;dbname=wp_old;charset=utf8mb4" --user=root [选项]
 *   php tools/wp-import.php --dsn="sqlite:/path/to/wp.sqlite" [选项]
 *
 * 数据库密码只从环境变量 WP_DB_PASSWORD 读，不写在命令行里（命令行会进 shell 历史和进程列表）。
 *
 * 选项：
 *   --prefix=wp_          WordPress 表前缀
 *   --dry-run             试运行：完整跑一遍、出报告，然后回滚，不改数据
 *   --default-lang=en     WordPress 默认语言（不填则读 WPML 设置）
 *   --lang-map=pt-br:pt   WPML 语言代码与 CMS 代码不同时的对应（逗号分隔）
 *   --uploads=DIR         原站的 wp-content/uploads 目录：复制到本站 /wp-content/uploads（保留原路径，已有同大小的跳过）
 *   --urls=FILE           原站网址清单（每行一个，可直接用站点地图里的地址）：检查哪些没被导入覆盖
 *   --report=FILE         报告另存为 JSON
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__));
require ROOT_PATH . '/config/config.php';
require ROOT_PATH . '/includes/functions.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/migrate/WordPressImporter.php';
require_once ROOT_PATH . '/includes/Redirects.php';

$opts = getopt('', ['dsn:', 'user::', 'prefix::', 'dry-run', 'default-lang::', 'lang-map::', 'uploads::', 'urls::', 'report::', 'help']);
if (isset($opts['help']) || empty($opts['dsn'])) {
    fwrite(STDOUT, "用法：php tools/wp-import.php --dsn=\"mysql:host=127.0.0.1;dbname=wp_old;charset=utf8mb4\" --user=root [--dry-run] [--uploads=DIR] [--urls=FILE]\n"
        . "密码从环境变量 WP_DB_PASSWORD 读取。完整说明见文件开头。\n");
    exit(isset($opts['help']) ? 0 : 2);
}

$langMap = [];
foreach (array_filter(explode(',', (string) ($opts['lang-map'] ?? ''))) as $pair) {
    [$from, $to] = array_pad(explode(':', $pair, 2), 2, '');
    if ($from !== '' && $to !== '') $langMap[strtolower(trim($from))] = trim($to);
}

try {
    $pdo = new PDO((string) $opts['dsn'], (string) ($opts['user'] ?? ''), (string) (getenv('WP_DB_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $importer = new WordPressImporter(new WordPressSource($pdo, (string) ($opts['prefix'] ?? 'wp_')),
        array_filter(['default_lang' => $opts['default-lang'] ?? null, 'lang_map' => $langMap]));
    $dryRun = isset($opts['dry-run']);
    $report = $importer->run($dryRun);
} catch (Throwable $e) {
    fwrite(STDERR, '导入失败：' . $e->getMessage() . "\n");
    exit(1);
}

// 上传文件
if (!empty($opts['uploads'])) {
    $copy = wpImportCopyUploads((string) $opts['uploads'], ROOT_PATH . '/wp-content/uploads', $dryRun);
    $report['uploads'] = $copy;
}

// 网址覆盖检查
if (!empty($opts['urls'])) {
    $report['coverage'] = wpImportCoverage((string) $opts['urls'], $importer, $report);
}

$out = fopen('php://stdout', 'w');
fwrite($out, ($dryRun ? "【试运行，已回滚】\n" : "【已导入】\n"));
fwrite($out, '默认语言：' . $report['default_lang'] . '；语言：' . implode(', ', $report['languages']) . "\n");
foreach (['created' => '新建', 'updated' => '更新', 'skipped' => '跳过'] as $bucket => $label) {
    if ($report[$bucket] !== []) fwrite($out, $label . '：' . implode('，', array_map(static fn ($k, $v) => "{$k} {$v}", array_keys($report[$bucket]), $report[$bucket])) . "\n");
}
fwrite($out, '登记网址：' . $report['urls'] . " 个\n");
if ($report['language_domains'] !== []) {
    fwrite($out, 'WPML 用了语言子域名：' . json_encode($report['language_domains'], JSON_UNESCAPED_SLASHES)
        . "\n  → 在「设置 → 多语言 → 语言域名」里照此配置并解析、绑定这些域名，原来的子域名网址才能保留。\n");
}
foreach ($report['forms'] as $form) {
    fwrite($out, "表单：{$form['name']} → 表单模板「{$form['slug']}」" . ($form['translations'] !== [] ? '，含翻译 ' . implode('/', $form['translations']) : '')
        . "（正文里的 Contact Form 7 短代码已换成 [form-{$form['slug']}]）\n");
}
foreach ($report['menus'] as $menu) {
    fwrite($out, "菜单：{$menu['name']}（{$menu['lang']}，{$menu['items']} 项" . ($menu['locations'] !== [] ? '，原站位置 ' . implode('/', $menu['locations']) : '') . "）→ 菜单组 #{$menu['id']}\n");
}
if ($report['menus'] !== []) fwrite($out, "  在页头导航元素里选默认语言的菜单组即可，其它语言自动换成各自的菜单。\n");
foreach ($report['acf'] as $acf) {
    fwrite($out, "ACF 字段组：{$acf['group']} → " . ExtFields::ownerLabel($acf['owner']) . "（{$acf['fields']} 个字段）\n");
}
if ($report['dropped'] !== []) fwrite($out, '去掉的短代码（表单、幻灯片等，请在新站重做）：' . json_encode($report['dropped'], JSON_UNESCAPED_UNICODE) . "\n");
if ($report['unknown'] !== []) fwrite($out, '没认出的短代码（原样保留在正文里，请检查）：' . json_encode($report['unknown'], JSON_UNESCAPED_UNICODE) . "\n");
if (isset($report['uploads'])) fwrite($out, '上传文件：复制 ' . $report['uploads']['copied'] . '，已存在 ' . $report['uploads']['kept'] . "\n");
if (isset($report['coverage'])) {
    fwrite($out, '原站网址：' . $report['coverage']['total'] . ' 个，已覆盖 ' . $report['coverage']['covered'] . "，未覆盖 " . count($report['coverage']['missing']) . " 个\n");
    foreach (array_slice($report['coverage']['missing'], 0, 50) as $missing) fwrite($out, '  未覆盖：' . $missing . "\n");
}
foreach (array_slice($report['warnings'], 0, 100) as $w) fwrite($out, '提示：' . $w . "\n");
if (!empty($opts['report'])) file_put_contents((string) $opts['report'], json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit(0);

/** @return array{copied:int,kept:int} */
function wpImportCopyUploads(string $from, string $to, bool $dryRun): array
{
    $from = rtrim(str_replace('\\', '/', $from), '/');
    if (!is_dir($from)) throw new RuntimeException('上传目录不存在：' . $from);
    $copied = $kept = 0;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $rel = substr(str_replace('\\', '/', $file->getPathname()), strlen($from) + 1);
        // 只复制媒体与文档，不复制原站可能残留的脚本
        if (preg_match('/\.(php\d?|phtml|phar|cgi|pl|py|sh|htaccess|ini)$/i', $rel) || str_contains($rel, '..')) continue;
        $target = $to . '/' . $rel;
        if (is_file($target) && filesize($target) === $file->getSize()) { $kept++; continue; }
        if (!$dryRun) {
            if (!is_dir(dirname($target))) mkdir(dirname($target), 0755, true);
            copy($file->getPathname(), $target);
        }
        $copied++;
    }
    return ['copied' => $copied, 'kept' => $kept];
}

/**
 * 原站网址清单逐个检查：在登记表里（导入的条目或 301 跳转）就算覆盖。
 * @param array<string,mixed> $report
 * @return array{total:int,covered:int,missing:list<string>}
 */
function wpImportCoverage(string $file, WordPressImporter $importer, array $report): array
{
    if (!is_file($file)) throw new RuntimeException('网址清单不存在：' . $file);
    $domains = array_flip($report['language_domains']);
    $registered = array_flip(array_map(static fn (string $p): string => rtrim($p, '/'), $importer->registeredPaths()));
    $total = $covered = 0;
    $missing = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $url = trim($line);
        if ($url === '' || str_starts_with($url, '#')) continue;
        $total++;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        if (isset($domains[$host]) && !str_starts_with($path, '/wp-content/')) $path = '/' . $domains[$host] . $path;
        if ($path === '/' || isset($domains[$host]) && preg_match('#^/[a-z]{2}(?:-[A-Z]{2})?/?$#', $path)) { $covered++; continue; }   // 首页（含 ?p=123、?s= 等旧查询串，由 LegacyUrls 接住）
        try {
            $normalized = rtrim(ProductRouteModel::normalize($path), '/');
        } catch (InvalidArgumentException) {
            $missing[] = $url;
            continue;
        }
        if (isset($registered[$normalized]) || productRouteModel()->resolve($normalized) !== null) { $covered++; continue; }
        // 成批的旧地址：WordPress 规律地址（feed、作者、附件、日期归档）与跳转的前缀规则
        if (LegacyUrls::wordpressPathTarget($path) !== null || Redirects::matchPrefix($path) !== null) { $covered++; continue; }
        $missing[] = $url;
    }
    return ['total' => $total, 'covered' => $covered, 'missing' => $missing];
}
