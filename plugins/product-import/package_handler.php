<?php
/**
 * 产品导入 2.0 接口（导入包向导）。由 admin.php 按 ?handler=pkg_* / job_* 引入，plugin_page.php 已做登录与权限。
 *
 *   pkg_upload        POST  上传 zip（或单张 products.csv/xlsx/json）→ 包
 *   pkg_incoming      GET   incoming/ 里用 FTP 传上来的文件夹与 zip
 *   pkg_use_incoming  POST  选一个 incoming 项 → 包
 *   pkg_plan          POST  包 + 语言 → 导入计划（预览）
 *   pkg_image         GET   预览缩略图（包里或本站的图）
 *   job_create        POST  包 + 语言 + 每行选择 → 任务
 *   job_run           POST  跑下一批
 *   job_retry / job_undo  POST
 *   job_get / jobs    GET
 *   job_errors        GET   出错行 CSV
 */

if (!defined('ROOT_PATH')) exit;

require_once __DIR__ . '/lib/ProductImportPackage.php';
require_once __DIR__ . '/lib/ProductImportPlanner.php';
require_once __DIR__ . '/lib/ProductImportSiteDb.php';
require_once __DIR__ . '/lib/ProductImportJobs.php';
require_once __DIR__ . '/lib/ProductImportRunner.php';
require_once __DIR__ . '/lib/ProductImportPackages.php';
require_once __DIR__ . '/lib/ProductImportExporter.php';

loadPluginLang('product-import');

$pimpPackages = new ProductImportPackages(ROOT_PATH . '/storage/product-import');
$pimpJobs = new ProductImportJobs($pimpPackages->jobsDir());

/** @return never */
$pimpReply = static function (array $data = [], string $error = ''): void {
    header('Content-Type: application/json; charset=utf-8');
    $message = $error === '' ? '' : (__('pimp_err_' . $error) !== 'pimp_err_' . $error ? __('pimp_err_' . $error) : $error);
    echo json_encode($error === '' ? ['code' => 0, 'data' => $data] : ['code' => 1, 'msg' => $message, 'error' => $error],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
};

$pimpInput = static function (): array {
    $input = json_decode((string) file_get_contents('php://input'), true);
    return is_array($input) ? $input : $_POST;
};

$pimpLang = static function (array $input, ProductImportPackage $package): string {
    $lang = (string) ($input['lang'] ?? '');
    $lang = $lang !== '' ? $lang : ($package->lang !== '' ? $package->lang : (string) config('site_lang', 'zh-CN'));
    return preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/D', $lang) ? $lang : (string) config('site_lang', 'zh-CN');
};

/** 计划里去掉服务器路径，只留引用（预览图走 pkg_image） */
$pimpPublicPlan = static function (array $plan): array {
    foreach ($plan['products'] as $i => $row) {
        foreach ($row['images'] as $role => $list) {
            $plan['products'][$i]['images'][$role] = array_map(
                static fn (array $image): array => ['ref' => $image['ref'], 'kind' => $image['kind']],
                $list
            );
        }
    }
    return $plan;
};

$pimpJobView = static function (array $job): array {
    unset($job['package'], $job['images'], $job['updated'], $job['category_ids']);
    $job['created'] = ['categories' => count($job['created']['categories']), 'products' => count($job['created']['products']),
        'media' => count($job['created']['media'])];
    return $job;
};

$handler = (string) ($_GET['handler'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) {
    verifyCsrf();
} elseif (!in_array($handler, ['pkg_incoming', 'pkg_image', 'pkg_categories', 'pkg_export', 'job_get', 'jobs', 'job_errors'], true)) {
    $pimpReply([], 'post_required');
}

try {
    switch ($handler) {
        case 'pkg_upload':
            $file = $_FILES['file'] ?? null;
            if (!is_array($file) || (int) $file['error'] !== UPLOAD_ERR_OK) {
                $pimpReply([], in_array((int) ($file['error'] ?? 0), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'upload_too_large' : 'upload_failed');
            }
            $name = basename(str_replace('\\', '/', (string) $file['name']));
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $pimpPackages->cleanup();
            $package = $ext === 'zip'
                ? $pimpPackages->extractZip((string) $file['tmp_name'], $name)
                : $pimpPackages->fromTable((string) $file['tmp_name'], $ext, $name);
            $pimpReply(['package' => ['id' => $package['id'], 'name' => $package['name']]]);

        case 'pkg_incoming':
            $pimpReply(['items' => $pimpPackages->incoming(), 'dir' => 'storage/product-import/incoming/']);

        case 'pkg_use_incoming':
            $package = $pimpPackages->useIncoming((string) ($pimpInput()['name'] ?? ''));
            $pimpReply(['package' => ['id' => $package['id'], 'name' => $package['name']]]);

        case 'pkg_plan':
            $input = $pimpInput();
            $record = $pimpPackages->get((string) ($input['package'] ?? '')) ?? $pimpReply([], 'package_gone');
            $package = ProductImportPackage::fromDirectory($record['dir'], ROOT_PATH);
            $lang = $pimpLang($input, $package);
            $plan = (new ProductImportPlanner($package, new ProductImportSiteDb($lang, ROOT_PATH)))->plan();
            $pimpReply(['plan' => $pimpPublicPlan($plan), 'lang' => $lang, 'package' => ['id' => $record['id'], 'name' => $record['name']]]);

        case 'pkg_image':
            $record = $pimpPackages->get((string) ($_GET['package'] ?? ''));
            $resolved = $record === null ? null : ProductImportPackage::fromDirectory($record['dir'], ROOT_PATH)->resolveImage((string) ($_GET['ref'] ?? ''));
            $info = $resolved !== null && in_array($resolved['kind'], ['package', 'site'], true) ? @getimagesize($resolved['path']) : false;
            if (!is_array($info) || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                http_response_code(404);
                exit;
            }
            header('Content-Type: ' . $info['mime']);
            header('Cache-Control: private, max-age=600');
            header('X-Content-Type-Options: nosniff');
            readfile($resolved['path']);
            exit;

        case 'pkg_categories':
            $lang = (string) ($_GET['lang'] ?? '');
            $lang = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/D', $lang) ? $lang : (string) config('site_lang', 'zh-CN');
            $pimpReply(['categories' => ProductImportExporter::categories($lang), 'lang' => $lang,
                'uncategorized' => (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . "products WHERE lang = ? AND category_id = 0 AND deleted_at IS NULL", [$lang])]);

        case 'pkg_export':
            // 只读导出，GET 下载；导出文件放 storage 临时目录，发完即删
            $lang = (string) ($_GET['lang'] ?? '');
            $lang = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/D', $lang) ? $lang : (string) config('site_lang', 'zh-CN');
            $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($_GET['categories'] ?? ''))), static fn (int $id): bool => $id > 0));
            @set_time_limit(0);
            if (!is_dir(ROOT_PATH . '/storage/temp')) {
                mkdir(ROOT_PATH . '/storage/temp', 0755, true);
            }
            $zipPath = ROOT_PATH . '/storage/temp/product-export-' . bin2hex(random_bytes(6)) . '.zip';
            try {
                $result = (new ProductImportExporter(ROOT_PATH))->export($ids, $lang, $zipPath);
                adminLog('product', 'export', "导出产品导入包：{$result['products']} 款，{$result['images']} 张图");
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="products-' . $lang . '-' . date('Ymd-His') . '.zip"');
                header('Content-Length: ' . filesize($zipPath));
                header('X-Product-Export: ' . $result['products'] . ' products; ' . count($result['missing']) . ' missing images');
                readfile($zipPath);
            } finally {
                if (is_file($zipPath)) {
                    unlink($zipPath);
                }
            }
            exit;

        case 'job_create':
            $input = $pimpInput();
            $record = $pimpPackages->get((string) ($input['package'] ?? '')) ?? $pimpReply([], 'package_gone');
            $package = ProductImportPackage::fromDirectory($record['dir'], ROOT_PATH);
            if ($package->errors !== []) {
                $pimpReply([], (string) $package->errors[0]['code']);
            }
            $lang = $pimpLang($input, $package);
            $plan = (new ProductImportPlanner($package, new ProductImportSiteDb($lang, ROOT_PATH)))->plan();
            $decisions = [];
            foreach ((array) ($input['decisions'] ?? []) as $index => $choice) {
                if (in_array($choice, ['create', 'update', 'skip'], true)) {
                    $decisions[(int) $index] = (string) $choice;
                }
            }
            $job = ProductImportRunner::newJob($plan, $decisions, $record['dir'], $lang, (int) ($_SESSION['admin_id'] ?? 0), (string) $record['name']);
            $pimpJobs->save($job);
            $pimpReply(['job' => $pimpJobView($job)]);

        case 'job_run':
        case 'job_retry':
        case 'job_undo':
            $id = (string) ($pimpInput()['job'] ?? '');
            if (!ProductImportJobs::validId($id)) {
                $pimpReply([], 'job_gone');
            }
            // 同一任务同时只跑一个请求（两个标签页同时点会重复建产品）
            $lock = fopen($pimpPackages->jobsDir() . '/' . $id . '.lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                $pimpReply([], 'job_busy');
            }
            $job = $pimpJobs->load($id) ?? $pimpReply([], 'job_gone');
            if ($handler === 'job_undo') {
                if (!in_array($job['status'], ['done', 'running'], true)) {
                    $pimpReply([], 'job_not_undoable');
                }
                $runner = new ProductImportRunner(ProductImportPackage::fromDirectory(is_dir($job['package']) ? $job['package'] : sys_get_temp_dir(), ROOT_PATH), ROOT_PATH);
                $conflicts = $runner->undo($job);
                $pimpJobs->save($job);
                adminLog('product', 'import', '撤销产品导入任务 ' . $id . '，未恢复 ' . count($conflicts) . ' 款');
                $pimpReply(['job' => $pimpJobView($job)]);
            }
            if (!is_dir((string) $job['package'])) {
                $pimpReply([], 'package_gone');
            }
            if ($handler === 'job_retry') {
                // 没有失败的行：原样返回，不再空跑一批
                if (ProductImportRunner::retryFailed($job) === 0) {
                    $pimpReply(['job' => $pimpJobView($job)]);
                }
            }
            $runner = new ProductImportRunner(ProductImportPackage::fromDirectory($job['package'], ROOT_PATH), ROOT_PATH);
            $wasDone = $job['status'] === 'done';
            $runner->run($job);
            if ($job['status'] === 'done' && (!$wasDone || $handler === 'job_retry')) {
                $job['verify'] = $runner->verify($job);
            }
            $pimpJobs->save($job);
            if (!$wasDone && $job['status'] === 'done') {
                $c = $job['counts'];
                adminLog('product', 'import', "产品导入任务 {$id}：新增 {$c['created']}，更新 {$c['updated']}，跳过 {$c['skipped']}，失败 {$c['failed']}，有错 {$c['error']}");
            }
            $pimpReply(['job' => $pimpJobView($job)]);

        case 'job_get':
            $job = $pimpJobs->load((string) ($_GET['job'] ?? '')) ?? $pimpReply([], 'job_gone');
            $pimpReply(['job' => $pimpJobView($job)]);

        case 'jobs':
            $pimpReply(['jobs' => $pimpJobs->recent()]);

        case 'job_errors':
            $job = $pimpJobs->load((string) ($_GET['job'] ?? '')) ?? $pimpReply([], 'job_gone');
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="product-import-' . $job['id'] . '-errors.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [__('pimp_col_row'), __('pimp_col_title'), __('pimp_col_key'), __('pimp_col_status'), __('pimp_col_message')], ',', '"', '\\');
            foreach ($job['items'] as $item) {
                if (in_array($item['status'], ['error', 'failed'], true)) {
                    // 单元格以 = + - @ 开头会被表格软件当公式
                    $cells = array_map(static fn ($v): string => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : (string) $v,
                        [$item['index'] + 1, $item['title'], $item['key'], $item['status'], $item['message']]);
                    fputcsv($out, $cells, ',', '"', '\\');
                }
            }
            fclose($out);
            exit;
    }
} catch (RuntimeException $e) {
    $pimpReply([], $e->getMessage());
}
$pimpReply([], 'unknown_handler');
