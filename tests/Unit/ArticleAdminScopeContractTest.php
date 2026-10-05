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
}
