<?php
/**
 * 产品导入后台执行的 MySQL 验收夹具（2.3.0）。只在一次性站点（目录名 yikai-e2e-*）+ 一次性 MySQL 实例（库名 s103_pimport_*、
 * 回环地址、测试端口、数据目录与环境变量一致）上运行；由 tests/e2e/run-product-import-mysql.js 调用，每个动作打印一行 JSON。
 *
 *   provision                 空库导入 install/sql/mysql.sql，建超级管理员（密码取 YK_PIMP_ADMIN_PASSWORD，不进命令行）
 *   package <前缀> <款数> [zip] [bad]  incoming/<前缀> 下生成导入包（每款一张不同的图）；bad = 有一款引用不存在的图；zip = 另打 zip
 *   rename <前缀> <新标题后缀>  把包里各款标题改一下（重导时走「更新」）
 *   queue <前缀> [break]      预览 + 建任务 + 排队（与页面 job_create 相同）；break = 建好后挪走一张图，执行时那一行失败
 *   restore <前缀>            把挪走的图放回去
 *   worker <任务> <每写一款停多少毫秒> [写到第几款后退出] [drain]  一个独立的执行者进程
 *   undo / retry / resume-legacy <任务>
 *   legacy-done <任务>        把已完成的任务改写成升级前的格式（没有 v，原值在主文件）
 *   legacy-partial <前缀> <存盘的款数> <又写了没存的款数>  造一个升级前没跑完的任务（最后一批写进库后没存）
 *   role <角色 id>            改管理员的角色（1 超管、3 内容编辑）
 *   inspect <任务>            任务文件 + 库里的实际情况（按标记、按导入键）
 *   titles <前缀>             这个包各款产品在库里的标题
 *   newest                    最新的任务号
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(1);
define('ROOT_PATH', dirname(__DIR__, 2));
if (!str_starts_with(basename(ROOT_PATH), 'yikai-e2e-')) throw new RuntimeException('Disposable site required');
define('IK_CLI', true);
require ROOT_PATH . '/config/config.php';
if (DB_DRIVER !== 'mysql' || !str_starts_with(DB_NAME, 's103_pimport_') || DB_HOST !== '127.0.0.1' || (int) DB_PORT < 33080) {
    throw new RuntimeException('Disposable connection required');
}
$action = $argv[1] ?? '';
if ($action === 'provision') {
    require_once ROOT_PATH . '/config/database.php';
} else {
    require_once ROOT_PATH . '/includes/init.php';
    require_once ROOT_PATH . '/includes/product-import/ProductImportPlanner.php';
    require_once ROOT_PATH . '/includes/product-import/ProductImportSiteDb.php';
    require_once ROOT_PATH . '/includes/product-import/ProductImportWorker.php';
}
$expected = (string) getenv('YK_PIMP_MYSQL_DATADIR');
$canonical = static fn (string $path): string => strtolower(rtrim(strtr($path, '\\', '/'), '/'));
if ($expected === '' || $canonical((string) db()->fetchColumn('SELECT @@datadir')) !== $canonical($expected)) throw new RuntimeException('Wrong MySQL datadir');

$out = static function (array $data): never {
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit(0);
};
$p = 'yikai_';
$incoming = ROOT_PATH . '/storage/product-import/incoming';
require_once ROOT_PATH . '/includes/product-import/ProductImportJobs.php';
$jobs = new ProductImportJobs(ROOT_PATH . '/storage/product-import/jobs');
$prefixOk = static fn (string $prefix): string => preg_match('/^[a-z0-9-]{2,40}$/D', $prefix) ? $prefix : throw new InvalidArgumentException('bad prefix');

if ($action === 'provision') {
    if (db()->fetchAll('SHOW TABLES') !== []) throw new RuntimeException('An empty database is required');
    db()->getPdo()->exec((string) file_get_contents(ROOT_PATH . '/install/sql/mysql.sql'));
    $password = (string) getenv('YK_PIMP_ADMIN_PASSWORD');
    if (strlen($password) < 12) throw new RuntimeException('Admin password required');
    db()->execute("DELETE FROM {$p}users");
    db()->execute("INSERT INTO {$p}users (username, password, nickname, email, role_id, status, created_at, updated_at) VALUES ('admin', ?, 'Admin', 'admin@example.test', 1, 1, ?, ?)",
        [password_hash($password, PASSWORD_BCRYPT), time(), time()]);
    db()->execute("UPDATE {$p}settings SET value = 'en' WHERE `key` IN ('site_lang', 'admin_lang')");
    db()->execute("UPDATE {$p}settings SET value = ? WHERE `key` = 'enabled_languages'", [json_encode(['en'])]);
    $out(['ok' => true, 'server' => db()->fetchColumn('SELECT VERSION()'), 'isolation' => db()->fetchColumn('SELECT @@transaction_isolation')]);
}

if ($action === 'package') {
    $prefix = $prefixOk((string) ($argv[2] ?? ''));
    $count = max(1, min(200, (int) ($argv[3] ?? 10)));
    $dir = "{$incoming}/{$prefix}";
    if (!is_dir($dir . '/images')) mkdir($dir . '/images', 0755, true);
    $products = [];
    for ($i = 1; $i <= $count; $i++) {
        $img = imagecreatetruecolor(320, 320);
        imagefill($img, 0, 0, imagecolorallocate($img, ($i * 37) % 256, ($i * 91) % 256, (crc32($prefix) + $i * 53) % 256));
        imagestring($img, 5, 20, 20, $prefix . '-' . $i, imagecolorallocate($img, 255, 255, 255));
        imagepng($img, "{$dir}/images/p{$i}.png");
        $products[] = ['key' => "{$prefix}-{$i}", 'title' => "PIMP {$prefix} product {$i}", 'category' => "{$prefix}-sub", 'cover' => "images/p{$i}.png",
            'model' => strtoupper($prefix) . "-{$i}", 'summary' => "Summary {$i}"];
    }
    if (in_array('bad', $argv, true)) {
        $products[] = ['key' => "{$prefix}-bad", 'title' => "PIMP {$prefix} product with a missing image", 'category' => "{$prefix}-sub", 'cover' => 'images/missing.png'];
    }
    $img = imagecreatetruecolor(200, 200);
    imagefill($img, 0, 0, imagecolorallocate($img, 10, 10, 10));
    imagepng($img, "{$dir}/images/category.png");
    file_put_contents("{$dir}/products.json", json_encode([
        'format' => 'yikai-product-package', 'version' => 1, 'lang' => 'en',
        'categories' => [
            ['key' => "{$prefix}-sub", 'name' => "PIMP {$prefix} sub", 'parent' => "{$prefix}-top"],
            ['key' => "{$prefix}-top", 'name' => "PIMP {$prefix} top", 'image' => 'images/category.png'],
        ],
        'products' => $products,
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $zipPath = '';
    if (in_array('zip', $argv, true)) {
        $zipPath = ROOT_PATH . "/storage/{$prefix}.zip";
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            $zip->addFile($file->getPathname(), $prefix . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1)));
        }
        $zip->close();
    }
    $out(['dir' => $dir, 'zip' => $zipPath, 'count' => count($products)]);
}

if ($action === 'rename') {
    $prefix = $prefixOk((string) ($argv[2] ?? ''));
    $file = "{$incoming}/{$prefix}/products.json";
    $manifest = json_decode((string) file_get_contents($file), true);
    foreach ($manifest['products'] as $i => $product) $manifest['products'][$i]['title'] = $product['title'] . ' ' . (string) ($argv[3] ?? 'v2');
    file_put_contents($file, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    $out(['ok' => true]);
}

if ($action === 'queue') {
    $prefix = $prefixOk((string) ($argv[2] ?? ''));
    $dir = "{$incoming}/{$prefix}";
    $package = ProductImportPackage::fromDirectory($dir, ROOT_PATH);
    $plan = (new ProductImportPlanner($package, new ProductImportSiteDb('en', ROOT_PATH)))->plan();
    $job = ProductImportRunner::newJob($plan, [], $dir, 'en', 1, $prefix);
    $jobs->save($job);
    $jobs->markActive((string) $job['id']);
    if (in_array('break', $argv, true)) rename("{$dir}/images/p2.png", "{$dir}/p2.png.moved");
    $out(['job' => $job['id'], 'summary' => $plan['summary']]);
}

if ($action === 'restore') {
    $dir = "{$incoming}/" . $prefixOk((string) ($argv[2] ?? ''));
    rename("{$dir}/p2.png.moved", "{$dir}/images/p2.png");
    $out(['ok' => true]);
}

if ($action === 'worker') {
    $id = (string) ($argv[2] ?? '');
    $delay = max(0, (int) ($argv[3] ?? 0));
    $crashAfter = (int) ($argv[4] ?? 0);
    $worker = new ProductImportWorker($jobs, ROOT_PATH);
    $products = 0;
    $worker->onWrite(static function (string $kind) use ($delay, $crashAfter, &$products): void {
        if ($delay > 0) usleep($delay * 1000);
        if ($kind === 'product' && ++$products === $crashAfter) {
            // 写进库之后、存进度之前进程没了（不走 finally，执行权由操作系统释放）
            fwrite(STDOUT, json_encode(['crashed_after' => $products]) . "\n");
            exit(3);
        }
    });
    $started = microtime(true);
    $result = in_array('drain', $argv, true) ? $worker->drain(120, 'cli') + ['result' => 'drain'] : $worker->runJob($id, 120, 'cli');
    $out(['result' => $result['result'], 'pid' => getmypid(), 'status' => $result['job']['status'] ?? null, 'seconds' => round(microtime(true) - $started, 2),
        'progress' => $result['progress'] ?? null]);
}

if ($action === 'undo' || $action === 'retry' || $action === 'resume-legacy') {
    $worker = new ProductImportWorker($jobs, ROOT_PATH);
    $id = (string) ($argv[2] ?? '');
    $result = match ($action) { 'undo' => $worker->undo($id), 'retry' => $worker->retry($id), default => $worker->resumeLegacy($id) };
    $out(['result' => $result['result'], 'status' => $result['job']['status'] ?? null, 'undo_requested' => $jobs->undoRequested($id)]);
}

if ($action === 'legacy-done') {
    $id = (string) ($argv[2] ?? '');
    $job = $jobs->load($id) ?? throw new RuntimeException('no job');
    unset($job['v'], $job['categories_done'], $job['updated_split'], $job['updated_ids'], $job['needs']);
    file_put_contents(ROOT_PATH . '/storage/product-import/jobs' . "/{$id}.json", json_encode($job, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $jobs->clearActive($id);
    $out(['ok' => true, 'status' => $job['status']]);
}

if ($action === 'legacy-partial') {
    // 照 2.2.x 插件的做法：页面每批跑完才存盘；最后一批写进库之后页面关了，没存
    $prefix = $prefixOk((string) ($argv[2] ?? ''));
    $saved = (int) ($argv[3] ?? 5);
    $lost = (int) ($argv[4] ?? 3);
    $dir = "{$incoming}/{$prefix}";
    $package = ProductImportPackage::fromDirectory($dir, ROOT_PATH);
    $plan = (new ProductImportPlanner($package, new ProductImportSiteDb('en', ROOT_PATH)))->plan();
    $job = ProductImportRunner::newJob($plan, [], $dir, 'en', 1, $prefix);
    unset($job['v'], $job['categories_done']);
    $job['status'] = 'ready';
    $runner = new ProductImportRunner($package, ROOT_PATH);
    $runner->run($job, $saved);
    $legacy = $job;
    unset($legacy['categories_done']);
    file_put_contents(ROOT_PATH . '/storage/product-import/jobs' . "/{$job['id']}.json", json_encode($legacy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $runner->run($job, $lost);   // 写进库，没存
    $out(['job' => $job['id'], 'products_in_db' => (int) db()->fetchColumn("SELECT COUNT(*) FROM {$p}products WHERE title LIKE ?", ["PIMP {$prefix} %"])]);
}

if ($action === 'role') {
    db()->execute("UPDATE {$p}users SET role_id = ? WHERE username = 'admin'", [(int) ($argv[2] ?? 1)]);
    $out(['ok' => true]);
}

if ($action === 'inspect') {
    $id = (string) ($argv[2] ?? '');
    $job = $jobs->load($id) ?? throw new RuntimeException('no job');
    $tagged = static fn (string $type, string $like): array => array_map('intval', array_column(db()->fetchAll(
        "SELECT owner_id FROM {$p}metas WHERE owner_type = ? AND meta_key = 'import_job' AND meta_value LIKE ? ORDER BY owner_id", [$type, $like]), 'owner_id'));
    $exists = static fn (string $table, array $ids): array => $ids === [] ? [] : array_map('intval', array_column(db()->fetchAll(
        "SELECT id FROM {$p}{$table} WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id', $ids), 'id'));
    $products = $exists('products', $tagged('product', $id . '#%'));
    $dupTags = (int) db()->fetchColumn("SELECT COUNT(*) FROM (SELECT meta_value FROM {$p}metas WHERE owner_type = 'product' AND meta_key = 'import_job' AND meta_value LIKE ? GROUP BY meta_value HAVING COUNT(*) > 1) d", [$id . '#%']);
    $prefix = (string) preg_replace('/-[^-]+$/', '', (string) ($job['items'][0]['key'] ?? $job['name']));   // 包里的导入键是 <前缀>-<序号>
    $dupKeys = (int) db()->fetchColumn("SELECT COUNT(*) FROM (SELECT m.meta_value FROM {$p}metas m JOIN {$p}products p ON p.id = m.owner_id AND p.deleted_at IS NULL
        WHERE m.owner_type = 'product' AND m.meta_key = 'import_key' AND m.meta_value LIKE ? GROUP BY m.meta_value HAVING COUNT(*) > 1) d", [$prefix . '-%']);
    $byKey = (int) db()->fetchColumn("SELECT COUNT(*) FROM {$p}metas m JOIN {$p}products p ON p.id = m.owner_id AND p.deleted_at IS NULL
        WHERE m.owner_type = 'product' AND m.meta_key = 'import_key' AND m.meta_value LIKE ?", [$prefix . '-%']);
    $sorted = static function (array $ids): array { $ids = array_map('intval', $ids); sort($ids); return $ids; };
    $out([
        'status' => $job['status'], 'v' => $job['v'] ?? 1, 'counts' => $job['counts'], 'busy' => $jobs->busy($id), 'active' => in_array($id, $jobs->active(), true),
        'undo_requested' => $jobs->undoRequested($id), 'owner' => $job['owner'] ?? null, 'blocked_reason' => $job['blocked_reason'] ?? null,
        'statuses' => array_count_values(array_column($job['items'], 'status')),
        'db_products_tagged' => count($products), 'db_products_by_import_key' => $byKey, 'duplicate_tags' => $dupTags, 'duplicate_import_keys' => $dupKeys,
        'job_created_products' => count($job['created']['products']), 'created_matches_tagged' => $sorted($job['created']['products']) === $products,
        'db_categories_tagged' => count($exists('product_categories', $tagged('product_category', $id . '#cat:%'))), 'job_created_categories' => count($job['created']['categories']),
        'db_media_tagged' => count($exists('media', $tagged('media', $id))), 'job_created_media' => count($job['created']['media']),
        'undo_conflicts' => $job['undo_conflicts'] ?? null, 'verify' => isset($job['verify']) ? ['checked' => $job['verify']['checked'], 'problems' => count($job['verify']['problems'])] : null,
        'updated_files' => count(glob(ROOT_PATH . '/storage/product-import/jobs' . "/{$id}.updated/*.json") ?: []),
    ]);
}

if ($action === 'titles') {
    $prefix = $prefixOk((string) ($argv[2] ?? ''));
    $out(['titles' => array_column(db()->fetchAll("SELECT p.title FROM {$p}products p JOIN {$p}metas m ON m.owner_type = 'product' AND m.owner_id = p.id AND m.meta_key = 'import_key'
        WHERE m.meta_value LIKE ? AND p.deleted_at IS NULL ORDER BY m.meta_value", [$prefix . '-%']), 'title')]);
}

if ($action === 'newest') {
    $out(['job' => $jobs->recent(1)[0]['id'] ?? null]);
}

throw new InvalidArgumentException('unknown action ' . $action);
