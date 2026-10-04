<?php
/** ExtFields 单测用的 functions.php 替身（独立进程里加载，读写走真实的 MetaModel / 内存 SQLite）。 */
declare(strict_types=1);

if (!function_exists('getMeta')) {
    function getMeta(string $ownerType, int $ownerId, string $key, mixed $default = null): mixed
    {
        return metaModel()->get($ownerType, $ownerId, $key, $default);
    }
}
if (!function_exists('setMeta')) {
    function setMeta(string $ownerType, int $ownerId, string $key, mixed $value): bool
    {
        return metaModel()->set($ownerType, $ownerId, $key, $value);
    }
}
if (!function_exists('delMeta')) {
    function delMeta(string $ownerType, int $ownerId, string $key = ''): int
    {
        return metaModel()->del($ownerType, $ownerId, $key);
    }
}
if (!function_exists('getAllMeta')) {
    function getAllMeta(string $ownerType, int $ownerId): array
    {
        return metaModel()->getAllByOwner($ownerType, $ownerId);
    }
}
if (!function_exists('extFieldOwnerTypes')) {
    function extFieldOwnerTypes(): array
    {
        return ['content', 'product', 'team'];
    }
}
if (!function_exists('resolveExtFieldOwner')) {
    function resolveExtFieldOwner(string $type): string
    {
        return $type === 'product' ? 'product' : ($type === 'team' ? 'team' : 'content');
    }
}
if (!function_exists('sanitizeHtml')) {
    require_once ROOT_PATH . '/includes/security.php';   // 真实的富文本净化
}
