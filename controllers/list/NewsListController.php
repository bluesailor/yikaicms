<?php
/**
 * Yikai CMS — news.php 的数据装配控制器（新闻文章列表）。
 *
 * 解析 news 顶级栏目、子栏目（分类导航）、当前分类、分页与关键词搜索，
 * 返回视图变量。news.php 只负责渲染新闻专用模板。
 *
 * 与 detail.php / article.php / job_detail.php 同款 controller 模式，
 * 由 NewsListControllerTest 守护数据逻辑。
 */

declare(strict_types=1);

final class NewsListController
{
    /**
     * @param array{cat?:string,cat_id?:int,page?:int,keyword?:string} $req
     * @return array<string,mixed>
     */
    public function prepare(array $req): array
    {
        $categorySlug = trim((string)($req['cat'] ?? ''));
        $categoryId   = (int)($req['cat_id'] ?? 0);
        $keyword      = trim((string)($req['keyword'] ?? ''));
        $page         = max(1, (int)($req['page'] ?? 1));

        // news 顶级栏目（lang-aware）
        $newsChannel   = getChannelBySlug('news', true);
        $newsChannelId = $newsChannel ? (int)$newsChannel['id'] : 0;
        $perPage = catalogPageSize('article', 10, $newsChannelId);

        // 当前分类：优先 slug，其次 id
        $category = null;
        if ($categorySlug !== '') {
            $category = channelModel()->findWhere(['slug' => $categorySlug, 'status' => 1]);
            if ($category) {
                $categoryId = (int)$category['id'];
            }
        } elseif ($categoryId > 0) {
            $category = getChannel($categoryId);
        }

        // 子栏目（分类导航）
        $categories = [];
        if ($newsChannelId > 0) {
            $categories = channelModel()->where(['parent_id' => $newsChannelId, 'status' => 1]);
        }

        // 查询目标栏目：指定分类则用分类，否则用 news 顶级栏目
        $queryChannelId = $categoryId > 0 ? $categoryId : $newsChannelId;
        $filters = ['include_children' => true];
        if ($keyword !== '') {
            $filters['keyword'] = $keyword;
        }

        $offset   = ($page - 1) * $perPage;
        $total    = contentModel()->getCount($queryChannelId, $filters);
        $articles = contentModel()->getList($queryChannelId, $perPage, $offset, $filters);

        return [
            'category'      => $category,
            'newsChannel'   => $newsChannel,
            'newsChannelId' => $newsChannelId,
            'categories'    => $categories,
            'keyword'       => $keyword,
            'page'          => $page,
            'perPage'       => $perPage,
            'total'         => $total,
            'articles'      => $articles,
        ];
    }
}
