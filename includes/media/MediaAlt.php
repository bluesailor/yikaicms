<?php
declare(strict_types=1);

/**
 * 媒体库里给图片填的替代文字（图片编辑弹窗里改，存在 metas：owner_type=media、meta_key=alt）。
 * 页面上用到这张图、又没单独写替代文字时，用它兜底——在媒体库填一次，各处都有。
 * 每个请求只查一次，按网址（去掉查询串）对应。
 */
function mediaAltFor(string $url): string
{
    static $map = null;
    if ($map === null) {
        $map = [];
        try {
            if (db()->tableExists('metas') && db()->tableExists('media')) {
                foreach (db()->fetchAll('SELECT m.url, x.meta_value FROM ' . DB_PREFIX . 'media m JOIN ' . DB_PREFIX
                    . "metas x ON x.owner_type = 'media' AND x.owner_id = m.id AND x.meta_key = 'alt' WHERE x.meta_value <> ''") as $row) {
                    $map[(string) $row['url']] = (string) $row['meta_value'];
                }
            }
        } catch (Throwable) {
            $map = [];
        }
    }
    if ($map === []) return '';
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    return $map[$path] ?? $map[$url] ?? '';
}
