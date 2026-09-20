<?php
/** v1.26 Site Builder：search 模板类型与 search-results 元素的注册形态。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxSearchTemplateTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testSearchIsAFreeConditionalTemplateTypeOnTheGenericPipeline(): void
    {
        self::assertTrue(BloxTemplateModel::validType('search'));
        self::assertTrue(BloxTemplateModel::conditionalType('search'));
        self::assertFalse(BloxAreaDocument::isArea('search'));
        // 免费档：结果面走原生同源元素，不依赖查询循环
        self::assertTrue(BloxTemplateEditPolicy::allows('search', false));
    }

    public function testSearchResultsElementIsRegisteredAndFailsClosedWithoutContext(): void
    {
        BuilderRegistry::boot();
        $element = BuilderRegistry::get('search-results');
        self::assertNotNull($element);
        self::assertSame('dynamic', $element->category());
        // 无上下文（非搜索页误放/模板预渲染）：前台空输出——模板空输出会整体回落原生页
        SearchResultsElement::setRuntimeContext(null);
        self::assertSame('', $element->render([]));
    }
}
