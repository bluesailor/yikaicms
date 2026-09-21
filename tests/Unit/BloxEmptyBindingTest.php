<?php
/**
 * 动态绑定为空时的处置（E10）。
 *
 * 三条边界是这个功能最容易做错的地方：
 *   1. 只有**动态绑定**为空才触发——作者手写的空文本是他自己要的空元素；
 *   2. 编辑器里始终保留占位，否则作者会以为元素丢了；
 *   3. 没设过这个规则的旧文档，渲染结果一字不变。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxEmptyBinding;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxEmptyBindingTest extends TestCase
{
    /** 未登记规则的元素一律 keep：旧文档行为不变。 */
    public function testDocumentsWithoutTheRuleAreUnaffected(): void
    {
        self::assertSame(BloxEmptyBinding::KEEP, BloxEmptyBinding::decide('heading', ['text' => '{{loop.title}}']));
        self::assertSame(BloxEmptyBinding::KEEP, BloxEmptyBinding::decide('heading', []));
    }

    /** 绑定解析为空 → 按规则处理。 */
    public function testEmptyBindingTriggersTheChosenRule(): void
    {
        // loop.* 在没有循环上下文时解析为空
        $data = ['text' => '{{loop.subtitle}}', '_empty_binding' => BloxEmptyBinding::HIDE];
        self::assertTrue(BloxEmptyBinding::isEmpty('heading', $data));
        self::assertSame(BloxEmptyBinding::HIDE, BloxEmptyBinding::decide('heading', $data));

        $row = ['text' => '{{loop.subtitle}}', '_empty_binding' => BloxEmptyBinding::ROW];
        self::assertSame(BloxEmptyBinding::ROW, BloxEmptyBinding::decide('heading', $row));
    }

    /** 作者手写的静态内容不是"绑定为空"——哪怕它是空字符串。 */
    public function testStaticContentIsNeverTreatedAsAnEmptyBinding(): void
    {
        self::assertFalse(BloxEmptyBinding::isEmpty('heading', ['text' => '固定标题']),
            '有静态文字就不算空绑定');
        self::assertFalse(BloxEmptyBinding::isEmpty('heading', ['text' => '']),
            '手写的空文本是作者自己要的，不该被当成绑定为空');
        self::assertSame(BloxEmptyBinding::KEEP,
            BloxEmptyBinding::decide('heading', ['text' => '固定标题', '_empty_binding' => BloxEmptyBinding::HIDE]));
    }

    /** fallback 管道给了替代值就不算空——两种规则不该互相打架。 */
    public function testFallbackValueCountsAsContent(): void
    {
        $data = ['text' => '{{loop.subtitle | 暂无副标题}}', '_empty_binding' => BloxEmptyBinding::HIDE];
        self::assertFalse(BloxEmptyBinding::isEmpty('heading', $data), 'fallback 命中即有内容');
        self::assertSame(BloxEmptyBinding::KEEP, BloxEmptyBinding::decide('heading', $data));
    }

    /** 未登记内容字段的元素类型不参与，保持旧行为。 */
    public function testUnknownTypesStayOut(): void
    {
        self::assertFalse(BloxEmptyBinding::isEmpty('divider', ['_empty_binding' => BloxEmptyBinding::HIDE]));
        self::assertSame(BloxEmptyBinding::KEEP,
            BloxEmptyBinding::decide('divider', ['_empty_binding' => BloxEmptyBinding::HIDE]));
    }

    /** 非法规则值按 keep 处理，不能因为脏数据就把内容藏了。 */
    public function testUnknownRuleValuesFallBackToKeep(): void
    {
        foreach (['', 'delete', '1', 'row ', null] as $bad) {
            self::assertSame(BloxEmptyBinding::KEEP,
                BloxEmptyBinding::decide('heading', ['text' => '{{loop.subtitle}}', '_empty_binding' => $bad]),
                var_export($bad, true));
        }
    }

    /** 渲染层：前台隐藏，编辑器保留。 */
    public function testRenderHidesOnFrontEndButKeepsPlaceholderWhileEditing(): void
    {
        $node = ['id' => 'h1', 'type' => 'heading',
            'data' => ['text' => '{{loop.subtitle}}', 'level' => 'h3', '_empty_binding' => BloxEmptyBinding::HIDE]];

        self::assertSame('', \BlockRenderer::renderElementNode($node, 0, false, [0, 0, 0]),
            '前台：绑定为空即隐藏');
        self::assertNotSame('', \BlockRenderer::renderElementNode($node, 0, true, [0, 0, 0]),
            '编辑器：必须保留占位，否则作者以为元素丢了');
    }
}
