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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error(__('admin_illegal_request'));
}

// 权限按上传类型分档，而不是一个「能不能上传」的总闸：
// $type 是客户端传的，传 files 就能上传 pdf/doc/xls/ppt/zip/rar/7z——
// 图片是排版需要（能编辑就该能传），文档压缩包不是。见 canUploadType()。
// 放在输入校验之前：无权者不该先收到「请选择文件」这种提示，
// 那既是信息泄露，也让权限测试难以断言。
$type = post('type', 'images');
if (!canUploadType($type)) {
    error($type === 'images' ? '没有上传图片的权限' : '没有上传文档/压缩包的权限', 403);
}

if (empty($_FILES['file'])) {
    error('请选择文件');
}
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
