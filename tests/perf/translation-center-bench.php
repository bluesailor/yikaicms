<?php
/**
 * 翻译工作中心在大站上的翻页开销（2.3.0 CORE-02 检查点）。一次性 SQLite 库（系统临时目录，跑完删除），不连任何现有站点。
 *
 * 用法：php tests/perf/translation-center-bench.php [文章数=3000] [--keep]
 * 造数：N 篇中文文章（正文约 2 KB），英文、日文译文各约 85%（其中约 1/5 是草稿），
 *       有来历记录的译文里约 1/10 原文在之后改过（过期），其余译文没有来历记录（未追踪）。
 * 量的是状态页的数据装配（admin/translation_status.php 用的同一个 TranslationCoverage::page()）：
 *       第 1 页、靠后的一页、带筛选的一页，各报耗时、查询次数、行数与计数，并与逐行全量扫描的结果核对。
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ROOT_PATH', dirname(__DIR__, 2));
$total = max(100, (int) ($argv[1] ?? 3000));
$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yk-tc-bench-' . bin2hex(random_bytes(4)) . '.sqlite';
// MySQL：只接受 run-translation-center-mysql.ps1 起的一次性实例（回环地址、测试端口、库名 yk_bench_ 开头、数据目录核对一致）
$mysql = (string) getenv('YK_BENCH_MYSQL_DATABASE');
if ($mysql !== '') {
    if (!preg_match('/^yk_bench_[a-z0-9]+$/', $mysql) || (int) getenv('YK_BENCH_MYSQL_PORT') < 33080) throw new RuntimeException('Disposable MySQL required');
    define('DB_DRIVER', 'mysql');
    define('DB_HOST', '127.0.0.1');
    define('DB_PORT', (string) getenv('YK_BENCH_MYSQL_PORT'));
    define('DB_NAME', $mysql);
    define('DB_USER', 'root');
    define('DB_PASS', '');
} else {
    define('DB_DRIVER', 'sqlite');
    define('DB_PATH', $file);
}
define('DB_PREFIX', 'yikai_');
define('DB_CHARSET', 'utf8mb4');
define('DEBUG', false);
define('STORAGE_PATH', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'yk-tc-bench-storage-' . bin2hex(random_bytes(4)));
require ROOT_PATH . '/config/database.php';
require ROOT_PATH . '/includes/models/autoload.php';
require ROOT_PATH . '/includes/TranslationCoverage.php';
function config(string $key, mixed $default = ''): mixed { return $key === 'site_lang' ? 'zh-CN' : $default; }

$pdo = db()->getPdo();
if ($mysql !== '') {
    $canonical = static fn (string $path): string => strtolower(rtrim(strtr($path, DIRECTORY_SEPARATOR, '/'), '/'));
    if ($canonical((string) db()->fetchColumn('SELECT @@datadir')) !== $canonical((string) getenv('YK_BENCH_MYSQL_DATADIR'))) throw new RuntimeException('Wrong MySQL datadir');
    if (db()->fetchAll('SHOW TABLES') !== []) throw new RuntimeException('An empty database is required');
}
$changed = $mysql !== '' ? "CONCAT(title, '（改）')" : "title || '（改）'";
$pdo->exec((string) file_get_contents(ROOT_PATH . '/install/sql/' . ($mysql !== '' ? 'mysql' : 'sqlite') . '.sql'));
foreach (['contents', 'channels', 'translation_links'] as $table) $pdo->exec('DELETE FROM yikai_' . $table);
$pdo->exec("INSERT INTO yikai_channels (id, name, slug, type, lang, status) VALUES (1, '新闻', 'news', 'list', 'zh-CN', 1), (2, 'News', 'news-en', 'list', 'en', 1), (3, 'ニュース', 'news-ja', 'list', 'ja', 1)");

mt_srand(20261120);
$body = static fn (string $seed): string => '<p>' . str_repeat($seed . ' 液压泵用于工业设备的动力传输，选型时需要核对流量、压力与接口尺寸。', 12) . '</p>';
$insert = $pdo->prepare('INSERT INTO yikai_contents (id, channel_id, type, title, slug, summary, content, lang, translation_group_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$link = $pdo->prepare('INSERT INTO yikai_translation_links (table_name, target_id, source_id, lang, source_fp, target_fp, state, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)');
$now = 1800000000;
$id = 0;
$pdo->beginTransaction();
$touch = [];
for ($i = 1; $i <= $total; $i++) {
    $sourceId = ++$id;
    $source = ['id' => $sourceId, 'title' => "文章 {$i}", 'summary' => "摘要 {$i}", 'content' => $body("第 {$i} 篇"), 'type' => 'article'];
    $insert->execute([$sourceId, 1, 'article', $source['title'], "a-{$i}", $source['summary'], $source['content'], 'zh-CN', $sourceId, mt_rand(1, 20) === 1 ? 0 : 1, $now - $i, $now - $i]);
    foreach (['en' => 2, 'ja' => 3] as $lang => $channel) {
        if (mt_rand(1, 100) > 85) continue;
        $targetId = ++$id;
        $target = ['id' => $targetId, 'title' => "Article {$i} {$lang}", 'summary' => "Summary {$i}", 'content' => $body("No. {$i} {$lang}"), 'type' => 'article'];
        $insert->execute([$targetId, $channel, 'article', $target['title'], "a-{$i}-{$lang}", $target['summary'], $target['content'], $lang, $sourceId, mt_rand(1, 5) === 1 ? 0 : 1, $now - $i, $now - $i]);
        if (mt_rand(1, 100) <= 80) {
            $link->execute(['contents', $targetId, $sourceId, $lang, TranslationState::rowFingerprint($source, 'title', 'summary'), TranslationState::rowFingerprint($target, 'title', 'summary'),
                mt_rand(1, 3) === 1 ? 'reviewed' : 'machine']);
            if (mt_rand(1, 10) === 1) $touch[$sourceId] = true;
        }
    }
}
foreach (array_keys($touch) as $sourceId) $pdo->exec("UPDATE yikai_contents SET title = {$changed} WHERE id = {$sourceId}");
$pdo->commit();
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]);   // config/database.php 里的计数语句类

$coverage = new TranslationCoverage(db());
$full = new TranslationCoverage(db(), false);   // 参照：整行扫描
$langs = ['en', 'ja'];
$measure = static function (string $label, Closure $run): array {
    CountingStatement::$count = 0;
    $start = microtime(true);
    $result = $run();
    return ['view' => $label, 'ms' => (int) round((microtime(true) - $start) * 1000), 'queries' => CountingStatement::$count] + $result;
};
/** 参照：逐行全量扫描（2.2.x 状态页的做法），用来核对计数与当前页的行 */
$reference = static function (string $filter, int $page, int $perPage = 50) use ($full, $langs): array {
    $totals = array_fill_keys(['all', ...TranslationCoverage::FLAGS], 0);
    $ids = [];
    $matched = 0;
    $full->scan('zh-CN', $langs, static function (array $source, array $cells, array $flags) use (&$totals, &$ids, &$matched, $filter, $page, $perPage): void {
        $flags['all'] = true;
        foreach (array_keys($totals) as $name) if ($flags[$name] ?? false) $totals[$name]++;
        if (!($flags[$filter] ?? false)) return;
        $matched++;
        if ($matched > ($page - 1) * $perPage && $matched <= $page * $perPage) $ids[] = (int) $source['id'];
    }, '', false, 'contents');
    return ['rows' => count($ids), 'matched' => $matched, 'totals' => $totals, 'ids' => $ids];
};
// 每次新建对象：和真实请求一样，指纹只能从缓存文件里拿，不靠进程内存
$viaPage = static function (string $filter, int $page) use ($langs): array {
    $r = (new TranslationCoverage(db()))->page('zh-CN', $langs, 'contents', $filter, '', $page, 50);
    return ['rows' => count($r['rows']), 'matched' => $r['matched'], 'totals' => $r['totals'], 'ids' => array_map(static fn (array $row): int => $row['id'], $r['rows'])];
};

$lastPage = (int) ceil($total / 50);
$views = [['all', 1, 'first page'], ['all', $lastPage - 1, 'later page (' . ($lastPage - 1) . ')'], ['outdated', 2, 'filtered: outdated, page 2'], ['draft', 1, 'filtered: draft, page 1']];
$report = ['articles' => $total, 'rows_in_contents' => (int) db()->fetchColumn('SELECT COUNT(*) FROM yikai_contents'), 'views' => [], 'reference' => [], 'consistent' => true];
foreach ($views as [$filter, $page, $label]) {
    $ref = $measure($label, static fn (): array => $reference($filter, $page));
    $new = $measure($label, static fn (): array => $viaPage($filter, $page));
    $again = $measure($label . ' (repeat)', static fn (): array => $viaPage($filter, $page));
    $same = $ref['ids'] === $new['ids'] && $ref['totals'] === $new['totals'] && $ref['matched'] === $new['matched'] && $new['ids'] === $again['ids'];
    $report['consistent'] = $report['consistent'] && $same;
    unset($ref['ids'], $new['ids'], $again['ids']);
    $report['reference'][] = $ref;
    $report['views'][] = $new + ['same_as_full_scan' => $same];
    $report['views'][] = $again;
}
// 改一篇原文后计数要跟着变（不能读到旧缓存）
$before = $viaPage('outdated', 1)['totals']['outdated'];
$fresh = (int) db()->fetchColumn("SELECT c.id FROM yikai_contents c WHERE c.lang = 'zh-CN' AND c.title NOT LIKE '%（改）' AND EXISTS (SELECT 1 FROM yikai_translation_links l WHERE l.source_id = c.id) ORDER BY c.id LIMIT 1");
db()->execute("UPDATE yikai_contents SET title = {$changed}, updated_at = ? WHERE id = ?", [$now + 10, $fresh]);
$after = $viaPage('outdated', 1)['totals']['outdated'];
$report['edit_is_seen'] = $after === $before + 1 && $reference('outdated', 1)['totals']['outdated'] === $after;
$report['consistent'] = $report['consistent'] && $report['edit_is_seen'];

$report['print_cache_bytes'] = array_sum(array_map('filesize', glob(STORAGE_PATH . '/cache/*') ?: []));
$report['driver'] = DB_DRIVER . ($mysql !== '' ? ' ' . db()->fetchColumn('SELECT VERSION()') : '');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
unset($coverage, $full, $insert, $link, $pdo);
$report_cache = glob(STORAGE_PATH . '/cache/*') ?: [];
if (!in_array('--keep', $argv, true)) register_shutdown_function(static function () use ($file, $report_cache): void {
    gc_collect_cycles();
    @unlink($file);
    foreach ($report_cache as $cached) @unlink($cached);
    @rmdir(STORAGE_PATH . '/cache');
    @rmdir(STORAGE_PATH);
});
exit($report['consistent'] ? 0 : 1);
