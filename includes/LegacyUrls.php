<?php
declare(strict_types=1);

/**
 * 旧站地址兜底（2.0.4，WordPress 迁移）：原站成批的地址没法一条条登记，在这里按规律接住。
 *
 * - 首页带查询串：?s=关键词 → 本站搜索（任何站点都生效）；
 *   ?p=123 / ?page_id=123 / ?cat=5 / ?product_cat=5 等按导入时记下的 WordPress ID 找到新内容（只在导入过的站点生效）。
 * - 即将 404 时（render_404 钩子）：先查跳转里的前缀规则（「/旧目录/*」）；
 *   导入过的站点再接住 WordPress 特有的地址：…/feed/ → 去掉 feed 的上一级，/author/… → 首页，
 *   …/attachment/名称/ → 所属页面，/2024/05/ 这类日期归档 → 首页，?attachment_id= → 首页。
 * WordPress 部分由导入工具写入设置 wp_legacy_urls=1 打开，后台不暴露开关；删掉设置即关闭。
 */
final class LegacyUrls
{
    public const SETTING = 'wp_legacy_urls';
    /** 导入时 metas(owner_type=wp_import) 里的种类 → 网址登记表的实体类型 */
    public const KIND_ROUTE = [
        'post' => 'content', 'page' => 'channel', 'product' => 'product',
        'category' => 'channel', 'post_tag' => 'content_tag', 'product_cat' => 'category', 'product_tag' => 'product_tag',
    ];
    /** 查询参数 → 依次尝试的导入种类 */
    private const QUERY_KINDS = [
        'p' => ['post', 'product', 'page'], 'page_id' => ['page'], 'cat' => ['category'],
        'tag_id' => ['post_tag'], 'product_cat' => ['product_cat'], 'product_tag' => ['product_tag'],
    ];

    public static function wordpressEnabled(): bool
    {
        return function_exists('config') && (string) config(self::SETTING, '0') === '1';
    }

    /**
     * 首页请求（含语言首页 /ja/）。命中就跳转并结束请求；不命中直接返回，照常渲染首页。
     * @param array<string,mixed> $query
     */
    public static function onHome(array $query): void
    {
        $target = self::homeTarget($query, self::wordpressEnabled());
        if ($target !== null) {
            self::redirect($target);
        }
    }

    /**
     * 首页查询串对应的跳转目标（纯函数，便于测试）；不跳返回 null。
     * @param array<string,mixed> $query
     */
    public static function homeTarget(array $query, bool $wordpress): ?string
    {
        $keyword = $query['s'] ?? null;
        if (is_string($keyword) && trim($keyword) !== '') {
            return searchUrl(mb_substr(trim($keyword), 0, 100));
        }
        if (!$wordpress) {
            return null;
        }
        foreach (self::QUERY_KINDS as $param => $kinds) {
            $raw = $query[$param] ?? null;
            if (!is_string($raw) || preg_match('/^\d{1,10}$/', $raw) !== 1) {
                continue;
            }
            foreach ($kinds as $kind) {
                $path = self::pathForWordPressId($kind, (int) $raw);
                if ($path !== '') {
                    return $path;
                }
            }
        }
        if (isset($query['attachment_id']) || isset($query['feed']) || isset($query['author'])) {
            return langPrefix() . '/';
        }
        return null;
    }

    /** render_404 钩子：前缀规则 → WordPress 规律地址。命中就跳转。 */
    public static function onNotFound(string $path): void
    {
        if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            return;
        }
        $prefix = Redirects::matchPrefix($path);
        if ($prefix !== null) {
            Redirects::send($prefix);
        }
        $target = self::wordpressEnabled() ? self::wordpressPathTarget($path) : null;
        if ($target !== null) {
            self::redirect($target);
        }
    }

    /** WordPress 规律地址的去处（纯函数）；不认识返回 null。 */
    public static function wordpressPathTarget(string $path): ?string
    {
        $path = '/' . trim(rawurldecode($path), '/');
        $lang = '';
        if (preg_match('~^/([a-z]{2}(?:-[a-z]{2,4})?)(/.*)?$~i', $path, $m) === 1 && class_exists('LanguageRegistry')
            && LanguageRegistry::has(strtolower($m[1]))) {
            $lang = '/' . strtolower($m[1]);
            $path = $m[2] ?? '/';
        }
        $home = $lang . '/';
        // …/feed/、…/feed/rss2/、/comments/feed/：回到去掉 feed 的上一级
        if (preg_match('~^(.*?)/(?:comments/)?feed(?:/(?:rss|rss2|atom|rdf))?$~', $path, $m) === 1) {
            return $m[1] === '' || $m[1] === '/comments' ? $home : $lang . $m[1] . '/';
        }
        if (preg_match('~^/author(?:/|$)~', $path) === 1) {
            return $home;
        }
        // 附件页：…/attachment/名称/ → 所属页面
        if (preg_match('~^(.*?)/attachment/[^/]+$~', $path, $m) === 1) {
            return $m[1] === '' ? $home : $lang . $m[1] . '/';
        }
        // 日期归档 /2024/、/2024/05/、/2024/05/12/（以及其分页）
        if (preg_match('~^/\d{4}(?:/\d{2}){0,2}(?:/page/\d+)?$~', $path) === 1) {
            return $home;
        }
        // 首页分页 /page/2/
        if (preg_match('~^/page/\d+$~', $path) === 1) {
            return $home;
        }
        return null;
    }

    /** 导入时记下的 WordPress ID 对应的新地址；没导入或已删除返回 ''。 */
    public static function pathForWordPressId(string $kind, int $wpId): string
    {
        $route = self::KIND_ROUTE[$kind] ?? null;
        if ($route === null || $wpId < 1 || !function_exists('getMeta')) {
            return '';
        }
        $id = (int) (getMeta('wp_import', $wpId, $kind) ?? 0);
        return $id > 0 ? productRouteModel()->pathFor($route, $id) : '';
    }

    /** @return never */
    private static function redirect(string $target): void
    {
        header('Cache-Control: no-store');
        header('Location: ' . $target, true, 301);
        exit;
    }
}
