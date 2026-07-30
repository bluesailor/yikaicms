<?php
/**
 * YikaiCMS - 文件上传
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();

// 上传是独立能力，不随内容编辑权附带（见 canUploadMedia()）。
// 此前这里只判登录，「投稿者」角色写着不能上传媒体却能直接 POST 本接口。
if (!canUploadMedia()) {
    error('没有上传权限', 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error('非法请求');
}

if (empty($_FILES['file'])) {
    error('请选择文件');
}

$type = post('type', 'images');
$result = uploadFile($_FILES['file'], $type);

if (isset($result['error'])) {
    error($result['error']);
}

// 保存到媒体库
$mediaData = [
    'name' => $result['name'],
    'path' => $result['path'],
    'url' => $result['url'],
    'type' => in_array($result['ext'], ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg']) ? 'image' : 'file',
    'ext' => $result['ext'],
    'mime' => mime_content_type($result['path']) ?: '',
    'size' => $result['size'],
    'width' => $result['width'] ?? 0,
    'height' => $result['height'] ?? 0,
    'md5' => $result['md5'] ?? '',
    'admin_id' => $_SESSION['admin_id'],
    'created_at' => time(),
];

$mediaId = mediaModel()->create($mediaData);

success([
    'id' => $mediaId,
    'url' => $result['url'],
    'name' => $result['name'],
    'size' => $result['size'],
]);
