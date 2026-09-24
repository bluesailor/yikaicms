<?php

declare(strict_types=1);

/**
 * 构建器「选择链接」的候选清单：新手不知道「关于我们」的地址是什么，让他们点选而不是手写 URL。
 *
 * 在编辑器页面渲染时一次性生成、嵌进页面，由前端本地搜索——不另开接口。
 * 内容与产品只取最近的一批（LIMIT），更早的仍可手动粘贴地址。
 *
 * 地址一律存「漂亮地址」：动态 URL 模式下由 CompatibleLinks 在输出时改写，
 * 两种模式都能用，切换模式也不用改文档。
 *
 * @psalm-suppress UnusedClass 由 admin/blox_editor/partials/link-picker-methods.php 调用（模板层，Psalm 不扫）
 */
final class BloxLinkCatalog
{
    public const RECENT_LIMIT = 100;

    /**
     * @return list<array{group:string,title:string,url:string}>
     */
    public static function build(string $lang): array
    {
        $items = [['group' => 'page', 'title' => __('blox_link_home'), 'url' => langPrefix($lang) . '/']];

        $channels = db()->fetchAll(
            'SELECT * FROM ' . DB_PREFIX . 'channels WHERE lang = ? AND status = 1 AND is_home = 0'
            . ' ORDER BY parent_id = 0 DESC, sort_order ASC, id ASC',
            [$lang]
        );
        foreach (self::treeOrder($channels) as [$channel, $depth]) {
            $url = self::relocate(channelPrettyUrl($channel), $lang);
            if ($url === '') {
                continue;
            }
            $items[] = [
                'group' => ($channel['type'] ?? '') === 'page' ? 'page' : 'channel',
                'title' => str_repeat('— ', $depth) . (string) ($channel['name'] ?? ''),
                'url' => $url,
            ];
        }

        $contents = db()->fetchAll(
            'SELECT c.id, c.title, c.slug, c.type, ch.type AS channel_type, ch.slug AS channel_slug'
            . ' FROM ' . DB_PREFIX . 'contents c LEFT JOIN ' . DB_PREFIX . 'channels ch ON c.channel_id = ch.id'
            . " WHERE c.lang = ? AND c.status = 1 AND c.deleted_at IS NULL AND c.type <> 'page'"
            . ' ORDER BY c.id DESC LIMIT ' . self::RECENT_LIMIT,
            [$lang]
        );
        foreach ($contents as $content) {
            $items[] = [
                'group' => 'content',
                'title' => (string) ($content['title'] ?? ''),
                'url' => self::relocate(contentPrettyUrl($content), $lang),
            ];
        }

        $products = db()->fetchAll(
            'SELECT p.id, p.title, p.slug, pc.slug AS category_slug FROM ' . DB_PREFIX . 'products p'
            . ' LEFT JOIN ' . DB_PREFIX . 'product_categories pc ON p.category_id = pc.id'
            . ' WHERE p.lang = ? AND p.status = 1 AND p.deleted_at IS NULL'
            . ' ORDER BY p.id DESC LIMIT ' . self::RECENT_LIMIT,
            [$lang]
        );
        foreach ($products as $product) {
            $items[] = [
                'group' => 'product',
                'title' => (string) ($product['title'] ?? ''),
                'url' => self::relocate(productPrettyUrl($product), $lang),
            ];
        }

        return $items;
    }

    /** 地址生成函数按「当前请求语言」加前缀；编辑器要的是被编辑内容的语言。 */
    private static function relocate(string $url, string $lang): string
    {
        return self::swapLangPrefix($url, langPrefix(), langPrefix($lang));
    }

    /**
     * 把站内地址的语言前缀从 $from 换成 $to（'' 表示默认语言无前缀）。
     * 站外链接、协议相对地址（外链栏目）原样保留。
     */
    public static function swapLangPrefix(string $url, string $from, string $to): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || !str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $url;
        }
        if ($from !== '' && ($url === $from || str_starts_with($url, $from . '/'))) {
            $url = substr($url, strlen($from));
            $url = $url === '' ? '/' : $url;
        }
        return $to . $url;
    }

    /**
     * 父栏目后紧跟其子栏目，保持后台栏目树的阅读顺序。
     *
     * @param list<array<string,mixed>> $channels
     * @return list<array{0:array<string,mixed>,1:int}>
     */
    private static function treeOrder(array $channels): array
    {
        $byParent = [];
        $ids = [];
        foreach ($channels as $channel) {
            $ids[(int) $channel['id']] = true;
            $byParent[(int) ($channel['parent_id'] ?? 0)][] = $channel;
        }
        $out = [];
        $walk = static function (int $parentId, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parentId] ?? [] as $channel) {
                $out[] = [$channel, $depth];
                if ($depth < 3) {
                    $walk((int) $channel['id'], $depth + 1);
                }
            }
        };
        $walk(0, 0);
        // 父栏目已隐藏的子栏目：挂不到树上，也照样列出来
        foreach ($byParent as $parentId => $children) {
            if ($parentId !== 0 && !isset($ids[$parentId])) {
                foreach ($children as $channel) {
                    $out[] = [$channel, 0];
                }
            }
        }
        return $out;
    }
}
