<?php
/**
 * YikaiCMS - 媒体库 JSON API
 *
 * 供媒体库选择弹窗调用，返回 JSON 数据
 * GET  ?action=list&type=image&keyword=xxx&page=1
 * POST ?action=upload  (file字段)
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

header('Content-Type: application/json; charset=utf-8');

/**
 * 统一的拒绝出口。
 * 不写 `: never`——那是 PHP 8.1 才有的类型，而本项目承诺支持 8.0
 * （8.0 会把它当成一个不存在的类名，Psalm 也会如实报 UndefinedClass）。
 */
function ma_deny(string $msg): void
{
    echo json_encode(['code' => 403, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = $_GET['action'] ?? 'list';

// 列表查询
if ($action === 'list') {
    // 选择器对内容编辑者开放（要插图就得能选图），但**不是媒体管理员的人只能看图片**：
    // 文档与压缩包是对外分发的资料，不该因为「能写文章」就能翻出来。
    if (!canUploadImage()) {
        ma_deny('没有媒体库权限');
    }
    $type = $_GET['type'] ?? 'image';
    if (!canManageMedia()) {
        $type = 'image';   // 忽略客户端传的 type，强制只列图片
    }
    $keyword = $_GET['keyword'] ?? '';
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 24;
    $offset  = ($page - 1) * $perPage;

    $filters = array_filter([
        'type'    => $type,
        'keyword' => $keyword,
    ]);

    $result = mediaModel()->getList($filters, $perPage, $offset);
    $total  = $result['total'];
    $pages  = (int)ceil($total / $perPage);

    echo json_encode([
        'code' => 0,
        'data' => [
            'items' => $result['items'],
            'total' => $total,
            'page'  => $page,
            'pages' => $pages,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 扫描入库：把 uploads/ 下未登记的文件补进 media 表。
// 场景：历史文件（演示图、手工 FTP 传的图、老编辑器上传）不在表里，媒体库/选图弹窗看不见。
// 跳过：已登记 url、缩略图副本（_thumb/_medium）、与原图同名的自动 webp 副本。
if ($action === 'scan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!canManageMedia()) {
        ma_deny('没有媒体管理权限');
    }
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
    $fileExts  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'rar', '7z'];

    $known = [];
    foreach (db()->fetchAll('SELECT url FROM ' . DB_PREFIX . 'media') as $r) {
        $known[(string) $r['url']] = true;
    }

    $rootNorm = str_replace('\\', '/', rtrim(ROOT_PATH, '/\\'));
    $added = 0;
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(UPLOADS_PATH, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iter as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $ext = strtolower($f->getExtension());
        $isImage = in_array($ext, $imageExts, true);
        if (!$isImage && !in_array($ext, $fileExts, true)) {
            continue;
        }
        $path = str_replace('\\', '/', $f->getPathname());
        if (preg_match('/_(thumb|medium)\.[a-z0-9]+$/i', $path)) {
            continue;
        }
        if ($ext === 'webp') {
            foreach (['jpg', 'jpeg', 'png'] as $sib) {
                if (is_file(preg_replace('/\.webp$/i', '.' . $sib, $path))) {
                    continue 2;
                }
            }
        }
        $url = substr($path, strlen($rootNorm));
        if ($url === '' || isset($known[$url])) {
            continue;
        }
        $w = 0;
        $h = 0;
        if ($isImage && $ext !== 'svg') {
            $info = @getimagesize($path);
            if ($info) {
                $w = (int) $info[0];
                $h = (int) $info[1];
            }
        }
        mediaModel()->create([
            'name'       => $f->getFilename(),
            'path'       => $path,
            'url'        => $url,
            'type'       => $isImage ? 'image' : 'file',
            'ext'        => $ext,
            'mime'       => function_exists('mime_content_type') ? (mime_content_type($path) ?: '') : '',
            'size'       => $f->getSize(),
            'width'      => $w,
            'height'     => $h,
            'md5'        => md5_file($path) ?: '',
            'admin_id'   => $_SESSION['admin_id'],
            'created_at' => $f->getMTime(),
        ]);
        $known[$url] = true;
        $added++;
    }
    adminLog('media', 'edit', '扫描入库：新增 ' . $added . ' 个文件');
    echo json_encode(['code' => 0, 'data' => ['added' => $added]], JSON_UNESCAPED_UNICODE);
    exit;
}

// 上传
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // 权限先于输入校验（同 upload.php）
    $type = post('type', 'images');
    if (!canUploadType($type)) {
        ma_deny($type === 'images' ? '没有上传图片的权限' : '没有上传文档/压缩包的权限');
    }

    if (empty($_FILES['file'])) {
        echo json_encode(['code' => 1, 'msg' => '请选择文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = uploadFile($_FILES['file'], $type);

    if (isset($result['error'])) {
        echo json_encode(['code' => 1, 'msg' => $result['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $mediaData = [
        'name'       => $result['name'],
        'path'       => $result['path'],
        'url'        => $result['url'],
        'type'       => in_array($result['ext'], ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']) ? 'image' : 'file',
        'ext'        => $result['ext'],
        'mime'       => mime_content_type($result['path']) ?: '',
        'size'       => $result['size'],
        'width'      => $result['width'] ?? 0,
        'height'     => $result['height'] ?? 0,
        'md5'        => $result['md5'] ?? '',
        'admin_id'   => $_SESSION['admin_id'],
        'created_at' => time(),
    ];

    $mediaId = mediaModel()->create($mediaData);

    echo json_encode([
        'code' => 0,
        'data' => [
            'id'   => $mediaId,
            'url'  => $result['url'],
            'name' => $result['name'],
            'size' => $result['size'],
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['code' => 1, 'msg' => '无效操作'], JSON_UNESCAPED_UNICODE);
