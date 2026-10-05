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
