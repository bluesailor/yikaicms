<?php
/**
 * 相册批量上传：两处入口（相册列表的快速上传、相册图片管理页）共用。
 *
 * 过去两处各自手写上传循环，只校验扩展名就 move_uploaded_file：改了扩展名的
 * 任意文件都能落进 uploads。现在每个文件都走 uploadFile()（大小、MIME 内容、
 * getimagesize、像素上限、超宽压缩），未通过的逐个报回，不再静默跳过。
 */

declare(strict_types=1);

/** 相册只收位图；UPLOAD_IMAGE_TYPES 里的 svg 等不适合放进相册灯箱。 */
const ALBUM_PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

/**
 * 把 $_FILES['files'] 这种「按字段分组」的多文件结构拆成逐个文件。
 *
 * @param array<string,mixed> $field
 * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
 */
function albumUploadedFiles(array $field): array
{
    $names = $field['name'] ?? [];
    if (!is_array($names)) {
        $names = [$names];
        $field = array_map(static fn($value): array => [$value], $field);
    }
    $files = [];
    foreach (array_keys($names) as $index) {
        $files[] = [
            'name' => (string) ($field['name'][$index] ?? ''),
            'type' => (string) ($field['type'][$index] ?? ''),
            'tmp_name' => (string) ($field['tmp_name'][$index] ?? ''),
            'error' => (int) ($field['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($field['size'][$index] ?? 0),
        ];
    }
    return $files;
}

/**
 * 校验并保存一批相册图片，逐张建记录。
 *
 * @param array<string,mixed> $field $_FILES['files']
 * @param callable(array, string):array $upload 默认 uploadFile；测试注入替身
 * @return array{uploaded:list<array{id:int|string,url:string,title:string}>,rejected:list<array{name:string,error:string}>}
 */
function albumStoreUploadedPhotos(int $albumId, array $field, ?callable $upload = null): array
{
    $upload ??= 'uploadFile';
    $uploaded = [];
    $rejected = [];
    foreach (albumUploadedFiles($field) as $file) {
        $name = $file['name'];
        if ($file['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ALBUM_PHOTO_EXTENSIONS, true)) {
            $rejected[] = ['name' => $name, 'error' => __('ap_type_not_allowed')];
            continue;
        }
        $result = $upload($file, 'albums');
        if (isset($result['error']) || empty($result['url'])) {
            $rejected[] = ['name' => $name, 'error' => (string) ($result['error'] ?? __('admin_upload_failed'))];
            continue;
        }
        $title = pathinfo($name, PATHINFO_FILENAME);
        $id = albumPhotoModel()->create([
            'album_id' => $albumId,
            'title' => $title,
            'image' => (string) $result['url'],
            'sort_order' => albumPhotoModel()->getMaxSort($albumId) + 1,
            'status' => 1,
            'created_at' => time(),
        ]);
        $uploaded[] = ['id' => $id, 'url' => (string) $result['url'], 'title' => $title];
    }
    return ['uploaded' => $uploaded, 'rejected' => $rejected];
}
