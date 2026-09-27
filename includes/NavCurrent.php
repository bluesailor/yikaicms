<?php
/**
 * 导航「当前位置」判定 —— 主题页头、Blox 导航元素（nav / nav-mega / nav-drawer）
 * 与 {yk:nav}/{yk:subnav} 共用的唯一来源。
 *
 * 状态值直接就是 aria-current 的取值：
 *   'page' —— 该链接就是当前页（到达栏目页、单页、自定义链接落点）
 *   'true' —— 当前页位于该项之下（祖先栏目、详情页所属栏目、子项命中的父项）
 *   ''     —— 无关
 *
 * 上下文来源（优先级从高到低）：
 *   1. 页头 layout 调 capture()，传入页面入口设置的 $currentChannelId / $currentSlug /
 *      $isHomePage —— Dispatcher 在方法作用域里 require 入口文件，这些变量到不了 $GLOBALS；
 *   2. $GLOBALS 同名变量（直接访问 list.php 等入口、编辑画布预览）；
 *   3. 与链接无关的 URL 比对始终生效（菜单组自定义链接、产品分类节点）。
 * 详情页入口调 markDetail()：所属栏目只算 'true'，不冒充栏目页本身。
 * 旧约定 $currentSlug（'product' / 'news'）只表示「位于该栏目下」，同样只给 'true'。
 */

declare(strict_types=1);

final class NavCurrent
{
    public const PAGE = 'page';
    public const SECTION = 'true';

    /** @var array{channel_id:int,slug:string,home:bool}|null */
    private static ?array $context = null;
    private static bool $detail = false;
    /** @var array<int, array<int, true>> */
    private static array $ancestorCache = [];

    public static function capture(int $channelId, string $slug = '', bool $isHome = false): void
    {
        self::$context = ['channel_id' => max(0, $channelId), 'slug' => trim($slug), 'home' => $isHome];
    }

    public static function markDetail(bool $detail = true): void
    {
        self::$detail = $detail;
    }

    /** @psalm-suppress PossiblyUnusedMethod （单测隔离用；同一请求内上下文只捕获一次） */
    public static function reset(): void
    {
        self::$context = null;
        self::$detail = false;
        self::$ancestorCache = [];
    }

    /**
     * @param array<string,mixed> $node 栏目行 / 菜单组节点 / 产品分类节点 / 首页虚拟项
     * @return ''|'page'|'true'
     */
    public static function state(array $node, ?string $requestUri = null): string
    {
        $ctx = self::context();
        if (!empty($node['_is_home'])) {
            return $ctx['home'] ? self::PAGE : '';
        }
        $requestUri ??= (string) ($_SERVER['REQUEST_URI'] ?? '');

        // 子项先判：子项命中时本项只是所在区域。父链接的查询串常是子链接的子集
        // （?yk_route=product_list ⊂ ?yk_route=product_list&cat=gift），先比父 URL 会让两级都成 'page'
        foreach (is_array($node['children'] ?? null) ? $node['children'] : [] as $child) {
            if (is_array($child) && self::state($child, $requestUri) !== '') {
                return self::SECTION;
            }
        }
        if (self::urlMatches(self::href($node), $requestUri, $ctx['home'])) {
            return self::PAGE;
        }

        $id = (int) ($node['id'] ?? 0);
        if ($id > 0 && $id === $ctx['channel_id']) {
            // 详情页里所属栏目只是所在区域，不冒充栏目页
            return self::$detail ? self::SECTION : self::PAGE;
        }
        if ($id > 0 && $ctx['slug'] !== '' && (string) ($node['slug'] ?? '') === $ctx['slug']) {
            return self::SECTION;
        }
        if ($id > 0 && isset(self::ancestors($ctx['channel_id'])[$id])) {
            return self::SECTION;
        }
        return '';
    }

    /** 状态 → 链接上的 aria-current 属性片段（含前导空格；无状态为空串） */
    public static function attr(string $state): string
    {
        return $state === self::PAGE || $state === self::SECTION ? ' aria-current="' . $state . '"' : '';
    }

    /** @return array{channel_id:int,slug:string,home:bool} */
    private static function context(): array
    {
        if (self::$context !== null) {
            return self::$context;
        }
        return [
            'channel_id' => max(0, (int) ($GLOBALS['currentChannelId'] ?? 0)),
            'slug' => trim((string) ($GLOBALS['currentSlug'] ?? '')),
            'home' => !empty($GLOBALS['isHomePage']),
        ];
    }

    /** 与 NavMegaElement::nodeHref / 主题 getChannelUrl 同一 URL 语义 */
    private static function href(array $node): string
    {
        if (!empty($node['_url'])) {
            return (string) $node['_url'];
        }
        if ((int) ($node['id'] ?? 0) > 0 && function_exists('channelUrl')) {
            return channelUrl($node);
        }
        return (string) ($node['url'] ?? '');
    }

    private static function urlMatches(string $href, string $requestUri, bool $isHome): bool
    {
        // channelUrl() 对外链栏目返回已转义的 link_url（&amp;），比对前还原
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($href === '' || $href[0] === '#' || $requestUri === '') {
            return false;
        }
        $link = parse_url($href);
        $request = parse_url($requestUri);
        if ($link === false || $request === false) {
            return false;
        }
        if (isset($link['host'])) {
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $linkHost = strtolower($link['host'] . (isset($link['port']) ? ':' . $link['port'] : ''));
            if ($host === '' || $linkHost !== $host) {
                return false;
            }
        } elseif (isset($link['scheme'])) {
            return false; // mailto: / tel: 等
        }

        $rawRequestPath = (string) ($request['path'] ?? '/');
        $requestPath = self::normalizePath($rawRequestPath);
        $linkPath = (string) ($link['path'] ?? '');
        if ($linkPath !== '' && $linkPath[0] !== '/') {
            $linkPath = substr($rawRequestPath, 0, (int) strrpos('/' . $rawRequestPath, '/')) . '/' . $linkPath;
        }
        if (self::normalizePath($linkPath === '' ? '/' : $linkPath) !== $requestPath) {
            return false;
        }

        parse_str((string) ($link['query'] ?? ''), $linkQuery);
        parse_str((string) ($request['query'] ?? ''), $requestQuery);
        if ($linkQuery === []) {
            // 动态 URL 模式下所有页面都是 /index.php?yk_route=…，只比路径会让首页链接处处命中
            return !isset($requestQuery['yk_route']) || $isHome;
        }
        foreach ($linkQuery as $key => $value) {
            if (!array_key_exists($key, $requestQuery) || $requestQuery[$key] != $value) {
                return false;
            }
        }
        return true;
    }

    private static function normalizePath(string $path): string
    {
        $path = (string) preg_replace('~/{2,}~', '/', rawurldecode($path));
        if (str_ends_with($path, '/index.php')) {
            $path = substr($path, 0, -strlen('index.php'));
        }
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    /** @return array<int, true> 当前栏目的全部祖先 id（不含自身） */
    private static function ancestors(int $channelId): array
    {
        if ($channelId <= 0 || !function_exists('getChannel')) {
            return [];
        }
        if (isset(self::$ancestorCache[$channelId])) {
            return self::$ancestorCache[$channelId];
        }
        $ids = [];
        $channel = getChannel($channelId);
        for ($depth = 0; $depth < 16 && is_array($channel); $depth++) {
            $parentId = (int) ($channel['parent_id'] ?? 0);
            if ($parentId <= 0 || isset($ids[$parentId]) || $parentId === $channelId) {
                break;
            }
            $ids[$parentId] = true;
            $channel = getChannel($parentId);
        }
        return self::$ancestorCache[$channelId] = $ids;
    }
}
