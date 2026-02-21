<?php
/**
 * Yikai CMS - 媒体库 JSON API
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

$action = $_GET['action'] ?? 'list';

// 列表查询
if ($action === 'list') {
    $type    = $_GET['type'] ?? 'image';
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

// 上传
if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['file'])) {
        echo json_encode(['code' => 1, 'msg' => '请选择文件'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $type   = post('type', 'images');
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
