<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** 内容目录的文章卡片可拆成子元素模板：结构、字段绑定与循环模板策略。 */
final class ContentCatalogItemTemplateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testCatalogAcceptsTheSameLoopChildrenAsDynamicLists(): void
    {
        $catalog = new ContentCatalogElement();
        self::assertTrue($catalog->isContainer());
        self::assertTrue($catalog->rendersOwnChildren());
        self::assertSame(DynamicLoopTemplateRenderer::allowedTypes(), $catalog->allowedChildren());
        self::assertSame(DynamicLoopTemplateRenderer::allowedTypes(), BuilderRegistry::meta('content-list')['content-catalog']['allowedChildren']);
    }

    public function testSplitCardPartsBindToArticleFields(): void
    {
        self::assertArrayHasKey('date', DynamicListItemSchema::fieldOptions('summary', 'content'));

        $html = DynamicLoopTemplateRenderer::render([
            ['type' => 'heading', 'data' => ['text' => '', 'level' => 'h3', 'loop_field' => 'title', 'loop_url_field' => 'url', 'color' => '#123456']],
            ['type' => 'text', 'data' => ['html' => '', 'loop_field' => 'date']],
            ['type' => 'text', 'data' => ['html' => '', 'loop_field' => 'summary', 'loop_length' => 120]],
        ], ['query_source' => 'type:article']);

        self::assertStringContainsString('<div class="yk-query-item">', $html);
        self::assertStringContainsString('{yk:field name=title /}', $html);
        self::assertStringContainsString('href="{yk:field name=url /}"', $html);
        self::assertStringContainsString('{yk:field name=date len=', $html);
        self::assertStringContainsString('{yk:field name=summary len=120 /}', $html);
        // 每个部分是独立元素：标题自己的颜色只作用在标题上
        self::assertMatchesRegularExpression('/<h3[^>]*#123456/', $html);
    }

    public function testCatalogTemplatesFollowTheQueryLoopPolicy(): void
    {
        $withTemplate = [[
            'columns' => [['elements' => [[
                'type' => 'content-catalog',
                'data' => ['children' => [['type' => 'heading', 'data' => ['loop_field' => 'title']]]],
            ]]]],
        ]];
        $this->expectException(RuntimeException::class);
        BloxQueryLoopPolicy::assertSectionsAllowed($withTemplate, false);
    }

    public function testRuntimeViewAppliesTheTemplatePerArticle(): void
    {
        $view = (string) file_get_contents(ROOT_PATH . '/views/list/content-catalog.php');
        self::assertStringContainsString("(\$contentCatalogItemTemplate ?? '') !== ''", $view);
        self::assertStringContainsString('TagEngine::pushContext($item);', $view);
        self::assertStringContainsString('TagEngine::popContext();', $view);
    }
}
