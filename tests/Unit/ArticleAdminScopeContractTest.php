<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ArticleAdminScopeContractTest extends TestCase
{
    public function testDefaultArticleListIncludesTheNewsRootAndItsChildren(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/admin/article.php');

        self::assertIsString($source);
        self::assertStringContainsString(
            'array_unique(array_merge([$newsChannelId], $newsChildIds))',
            $source
        );
        self::assertStringContainsString('array_merge($params, $newsScopeIds)', $source);
    }

    /** 2026-10-05 烤肉培训站：文章分类由插件接管，核心升级不再覆盖定制。 */
    public function testPluginsCanTakeOverArticleCategories(): void
    {
        global $ik_filters;
        require_once dirname(__DIR__, 2) . '/includes/hooks.php';
        require_once dirname(__DIR__, 2) . '/includes/admin_article_categories.php';
        $saved = $ik_filters['admin_article_categories'] ?? null;
        unset($ik_filters['admin_article_categories']);
        try {
            self::assertNull(adminArticleCategories('zh-CN'), '没有插件接管时走默认 news 子栏目');
            add_filter('admin_article_categories', static fn($value, string $lang): array => $lang === 'zh-CN'
                ? [['id' => 7, 'name' => '烤肉培训'], ['id' => 0, 'name' => '无效'], 'bad']
                : []);
            self::assertSame([['id' => 7, 'name' => '烤肉培训']], adminArticleCategories('zh-CN'), '只保留有 id 的栏目行');
            self::assertSame([], adminArticleCategories('en'), '返回空数组表示接管但该语言没有分类');
        } finally {
            if ($saved === null) unset($ik_filters['admin_article_categories']); else $ik_filters['admin_article_categories'] = $saved;
        }

        $list = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/article.php');
        $edit = (string) file_get_contents(dirname(__DIR__, 2) . '/admin/article_edit.php');
        self::assertStringContainsString('adminArticleCategories((string) $_viewLang)', $list);
        self::assertStringContainsString('array_merge($params, $scopeIds)', $list, '列表范围跟随插件给的栏目');
        self::assertStringContainsString('adminArticleCategories((string) $_editLang) ?? $categories', $edit);
        self::assertStringContainsString("error('请选择所属分类')", $edit, '接管时只接受插件给出的栏目');
    }

    /** 插件接管时，分类管理页按插件给的栏目排父子树；父级不在集合里的当顶层，新分类默认建在顶层。 */
    public function testCategoryTreeFollowsPluginChannels(): void
    {
        global $ik_filters;
        require_once dirname(__DIR__, 2) . '/includes/hooks.php';
        require_once dirname(__DIR__, 2) . '/includes/admin_article_categories.php';
        $saved = $ik_filters['admin_article_categories'] ?? null;
        unset($ik_filters['admin_article_categories']);
        try {
            add_filter('admin_article_categories', static fn(): array => [
                ['id' => 12, 'parent_id' => 10, 'name' => '技术文章'],
                ['id' => 10, 'parent_id' => 3, 'name' => '烤肉资讯'],      // 父级 3（课程）不在集合里 → 顶层
                ['id' => 15, 'parent_id' => 0, 'name' => '学员故事'],
                ['id' => 13, 'parent_id' => 12, 'name' => '设备保养'],
            ]);
            $tree = adminArticleCategoryTree('zh-CN');
            self::assertTrue($tree['plugin']);
            self::assertSame(0, $tree['root'], '插件接管时新分类建在顶层');
            self::assertSame([10, 12, 13, 15], array_map(static fn(array $r): int => (int) $r['id'], $tree['rows']));
            self::assertSame([0, 1, 2, 0], array_map(static fn(array $r): int => $r['_depth'], $tree['rows']));
            self::assertSame('', $tree['rows'][0]['_prefix']);
            self::assertSame('　— 　— ', $tree['rows'][2]['_prefix']);
        } finally {
            if ($saved === null) unset($ik_filters['admin_article_categories']); else $ik_filters['admin_article_categories'] = $saved;
        }
    }

    /** 文章分类管理页：从文章列表的标签进入；改、删、开关只接受分类树里的栏目。 */
    public function testCategoryPageIsReachableAndScoped(): void
    {
        $root = dirname(__DIR__, 2);
        $nav = (string) file_get_contents($root . '/admin/includes/workflow_nav.php');
        $list = (string) file_get_contents($root . '/admin/article.php');
        $page = (string) file_get_contents($root . '/admin/article_category.php');

        self::assertStringContainsString("'article', 'article_category' => ['admin_article'", $nav, '文章列表与文章分类共用标签');
        self::assertStringContainsString("require ROOT_PATH . '/admin/includes/workflow_nav.php'", $list);
        self::assertStringContainsString('adminModuleEnd()', $list);
        self::assertStringContainsString("requirePermission('edit_article')", $page);
        self::assertStringContainsString('adminArticleCategoryTree($_viewLang)', $page);
        self::assertStringContainsString("if (\$id > 0 && !isset(\$byId[\$id])) error(__('ccat_invalid'));", $page, '保存只改分类树里的栏目');
        self::assertSame(2, substr_count($page, "if (!isset(\$byId[\$id])) error(__('ccat_invalid'));"), '删除与开关只动分类树里的栏目');
        self::assertStringContainsString("if (\$childCount(\$id) > 0) error(__('pcat_has_children'));", $page, '有下级的分类不能删');
        self::assertStringContainsString("if (\$articleCount(\$id) > 0) error(__('acat_has_articles'));", $page, '有文章的分类不能删');
        self::assertStringContainsString("'/admin/article_category.php'", (string) file_get_contents($root . '/includes/admin_pages_catalog.php'));
    }
}
