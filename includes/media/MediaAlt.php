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
    static $connection = null;
    // 每个数据库连接查一次（同一请求只有一个连接；测试里每个用例换库，缓存跟着换）
    $current = spl_object_id(db());
    if ($map === null || $connection !== $current) {
        $connection = $current;
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

/**
 * 正文里没写替代文字的图片（<img> 没有 alt 或 alt 为空）用媒体库里的兜底（2.1.0；renderContent 调用）。
 * 写了替代文字的不动；媒体库里也没有的保持原样。
 */
function mediaAltFillHtml(string $html): string
{
    if (stripos($html, '<img') === false) {
        return $html;
    }
    return (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $m): string {
        $tag = $m[0];
        if (preg_match('/\salt\s*=\s*(["\'])\s*\S.*?\1/is', $tag) === 1) {
            return $tag;
        }
        if (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is', $tag, $src) !== 1) {
            return $tag;
        }
        $alt = mediaAltFor(html_entity_decode($src[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($alt === '') {
            return $tag;
        }
        $attr = ' alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        $tag = (string) preg_replace('/\salt\s*=\s*(["\'])\s*\1/i', '', $tag);
        return (string) preg_replace('/\s*\/?>$/', $attr . '$0', $tag, 1);
    }, $html);
}
