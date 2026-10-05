<?php

declare(strict_types=1);

/**
 * 后台「文章管理」的分类：默认是 news 栏目下的子栏目（返回 null 表示走默认）。
 * 插件可用过滤器 admin_article_categories 接管，返回某语言下的栏目行数组（每行至少有 id、name），
 * 文章列表范围与编辑页的分类下拉都改用这些栏目。老站的定制放进插件里，升级不会被覆盖。
 *
 * @return list<array<string,mixed>>|null
 */
function adminArticleCategories(string $lang): ?array
{
    $categories = function_exists('apply_filters') ? apply_filters('admin_article_categories', null, $lang) : null;
    if (!is_array($categories)) return null;
    return array_values(array_filter($categories, static fn($c): bool => is_array($c) && (int) ($c['id'] ?? 0) > 0));
}

/**
 * 某语言下的 news 栏目 id（0 = 没有）。翻译出来的 news 栏目 slug 带语言后缀，
 * 所以先取默认语言的源行，再按翻译组找姊妹栏目。
 */
function adminArticleNewsChannelId(string $lang): int
{
    $src = getChannelBySlug('news');
    if (!$src) return 0;
    if ((string) $src['lang'] === $lang) return (int) $src['id'];
    return findTranslatedChannelId((int) $src['id'], $lang);
}

/**
 * 文章分类管理页用的分类树：按父子关系排好、带缩进前缀（_depth / _prefix）。
 * 插件接管时只用插件给出的栏目，新分类默认建在顶层（root = 0）；
 * 否则是 news 栏目下的全部子栏目，新分类默认建在 news 下。
 *
 * @return array{rows: list<array<string,mixed>>, root: int, plugin: bool}
 */
function adminArticleCategoryTree(string $lang): array
{
    $plugin = adminArticleCategories($lang);
    if ($plugin === null) {
        $root = adminArticleNewsChannelId($lang);
        $rows = $root > 0 ? channelModel()->getFlatList($root, 0, $lang) : [];
        foreach ($rows as &$row) {
            $row['_depth'] = (int) ($row['_level'] ?? 0);
            $row['_prefix'] = $row['_depth'] > 0 ? str_repeat('　— ', $row['_depth']) : '';
        }
        unset($row);
        return ['rows' => array_values($rows), 'root' => $root, 'plugin' => false];
    }

    // 插件给的栏目可能只是一部分：父级不在集合里的当作顶层，再按集合内的父子关系排序
    $ids = [];
    foreach ($plugin as $row) $ids[(int) $row['id']] = true;
    $byParent = [];
    foreach ($plugin as $row) {
        $parent = (int) ($row['parent_id'] ?? 0);
        $byParent[isset($ids[$parent]) ? $parent : 0][] = $row;
    }
    $rows = [];
    $seen = [];
    $walk = static function (int $parent, int $depth) use (&$walk, &$rows, &$seen, $byParent): void {
        foreach ($byParent[$parent] ?? [] as $row) {
            $id = (int) $row['id'];
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $row['_depth'] = $depth;
            $row['_prefix'] = $depth > 0 ? str_repeat('　— ', $depth) : '';
            $rows[] = $row;
            $walk($id, $depth + 1);
        }
    };
    $walk(0, 0);
    return ['rows' => $rows, 'root' => 0, 'plugin' => true];
}
