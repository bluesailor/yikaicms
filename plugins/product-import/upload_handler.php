<?php
/**
 * 产品导入 - 文件上传与解析
 * 由 admin.php 根据 ?handler=upload 引入（已通过 plugin_page.php 完成 auth）
 */

if (!defined('ROOT_PATH')) exit;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['code' => 1, 'msg' => '仅支持 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

verifyCsrf(); // 写端点必须 CSRF（plugin_page.php 只做登录与权限）

if (empty($_FILES['file'])) {
    echo json_encode(['code' => 1, 'msg' => '请选择文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => '文件超过服务器限制',
        UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
        UPLOAD_ERR_PARTIAL    => '文件上传不完整',
        UPLOAD_ERR_NO_FILE    => '未选择文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '服务器写入失败',
        UPLOAD_ERR_EXTENSION  => '上传被服务器扩展拦截',
    ];
    $errCode = (int) $file['error'];
    $msg = array_key_exists($errCode, $errors) ? $errors[$errCode] : '上传错误码: ' . $errCode;
    echo json_encode(['code' => 1, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'xlsx'], true)) {
    echo json_encode(['code' => 1, 'msg' => '仅支持 CSV / XLSX 格式'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['code' => 1, 'msg' => '文件不能超过 10MB'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 过期清理：上传后从未导入的临时目录会永久残留（导入完成才自清），
// 每次新上传顺手清掉 24 小时前的旧目录，防共享主机磁盘慢性泄漏。
foreach (glob(ROOT_PATH . '/storage/temp/import_*') ?: [] as $oldDir) {
    if (is_dir($oldDir) && filemtime($oldDir) < time() - 86400) {
        cleanupTempDir($oldDir);
    }
}

$sessionId = session_id();
$fileId = substr(md5($sessionId . time() . rand()), 0, 16);
$extDir = ROOT_PATH . '/storage/temp/import_' . $fileId;
if (!is_dir($extDir)) {
    mkdir($extDir, 0755, true);
}

// 落盘名固定为 data.<ext>：客户端可控的原始文件名绝不参与路径拼接（防 ../ 穿越）
$destPath = $extDir . '/data.' . $ext;
move_uploaded_file($file['tmp_name'], $destPath);

$rows = [];
$headers = [];
$parseErrors = [];

if ($ext === 'csv') {
    $fh = fopen($destPath, 'r');
    if (!$fh) {
        cleanupTempDir($extDir);
        echo json_encode(['code' => 1, 'msg' => '无法打开文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $line = 1;
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    while (($cols = fgetcsv($fh, null, ',', '"', '\\')) !== false) {
        if ($line === 1) {
            $headers = array_map(fn($h) => trim((string) ($h ?? '')), $cols);
        } else {
            if (count(array_filter($cols, fn($c) => trim((string) ($c ?? '')) !== '')) === 0) {
                continue;
            }
            $rows[] = $cols;
        }
        $line++;
    }
    fclose($fh);
} else {
    require_once __DIR__ . '/XlsxReader.php';
    $parsed = ProductImportXlsxReader::read($destPath);
    $headers = $parsed['headers'];
    $rows = $parsed['rows'];
    $parseErrors = $parsed['errors'];
}

$headers = array_filter($headers, fn($h) => $h !== '');
$headers = array_values($headers);
$totalRows = count($rows);

$previewRows = array_slice($rows, 0, 10);
$previewRows = array_map(function ($row) use ($headers) {
    $out = [];
    foreach (array_keys($headers) as $i) {
        $out[] = mb_substr((string) ($row[$i] ?? ''), 0, 200);
    }
    return $out;
}, $previewRows);

$result = [
    'file_id'      => $fileId,
    'owner_sid'    => session_id(),
    'filename'     => $file['name'],
    'file_size'    => $file['size'],
    'total_rows'   => $totalRows,
    'headers'      => $headers,
    'preview_rows' => $previewRows,
    'parse_errors' => $parseErrors,
];

file_put_contents($extDir . '/_meta.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode(['code' => 0, 'data' => $result], JSON_UNESCAPED_UNICODE);

// ── helpers ──

function cleanupTempDir(string $dir): void
{
    foreach (glob($dir . '/*') as $f) {
        unlink($f);
    }
    rmdir($dir);
}
