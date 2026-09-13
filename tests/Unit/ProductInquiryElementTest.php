<?php
/**
 * ProductInquiryElement 契约测试（TASK-001 要求 4 后半步）。
 *
 * 要点：询价必须是**真实业务动作**——复用 form_submit 提交链路与签名令牌，
 * 而不是链回产品自身的假按钮；无产品上下文时不渲染；标题转义防 XSS。
 */
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ProductInquiryElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    private static function renderWith(array $product): string
    {
        $element = new ProductInquiryElement();
        return ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext($product),
            static fn(): string => $element->render([])
        );
    }

    public function testRendersRealInquiryFlowNotAFakeButton(): void
    {
        $html = self::renderWith(['id' => 12, 'title' => '示例产品']);

        // 真实提交链路：endpoint + slug + 令牌字段 + 产品关联 + 蜜罐
        $this->assertStringContainsString('/form_submit.php', $html);
        $this->assertStringContainsString('name="form_slug" value="product-inquiry"', $html);
        $this->assertStringContainsString('name="form_ts"', $html);
        $this->assertStringContainsString('name="form_sig"', $html);
        $this->assertStringContainsString('name="product_id" value="12"', $html);
        $this->assertStringContainsString('name="product_title" value="示例产品"', $html);
        $this->assertStringContainsString('name="hp_url"', $html, '必须有蜜罐字段');

        // 关键反例：不得只是"链回产品自身"的假询价
        $this->assertStringNotContainsString('<a ', $html, '询价元素不应输出链接，而应是可提交的表单');
        $this->assertStringNotContainsString('href=', $html);
    }

    public function testHidesWhenNoProductContext(): void
    {
        $element = new ProductInquiryElement();
        $this->assertSame('', $element->render([]), '渲染上下文外不输出');
        $this->assertSame('', self::renderWith(['id' => 0, 'title' => 'X']), '无产品 id 不输出');
    }

    public function testEscapesProductTitleAndKeepsValuesIntactInForms(): void
    {
        $html = self::renderWith(['id' => 3, 'title' => '"><script>alert(1)</script>']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html, '产品标题必须转义后落进隐藏字段');
        $this->assertStringContainsString('name="product_id" value="3"', $html);
    }

    public function testEachInstanceGetsItsOwnFormIdAndBindsOnce(): void
    {
        $element = new ProductInquiryElement();
        $first = '';
        $second = '';
        ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(['id' => 9, 'title' => 'T']),
            static function () use ($element, &$first, &$second): string {
                $first = $element->render([]);
                $second = $element->render([]);
                return '';
            }
        );

        preg_match('/id="(blox-inquiry-\d+-form)"/', $first, $a);
        preg_match('/id="(blox-inquiry-\d+-form)"/', $second, $b);
        $this->assertNotSame($a[1] ?? '', $b[1] ?? '', '同一页面多个实例不得共用表单 id');
        $this->assertStringContainsString('form.dataset.ykBound==="1"', $first, '脚本必须防重复绑定');
    }

    public function testIsProductDetailScoped(): void
    {
        $element = new ProductInquiryElement();
        $this->assertSame('product-inquiry', $element->type());
        $this->assertTrue($element->isDynamic());
        $this->assertTrue($element->paletteVisible('product-detail'));
        $this->assertFalse($element->paletteVisible('page'));
        // 测试引导的 __() 直接返回 key；这里断言"用的是 lang key 而非字段名"，
        // 三语是否齐备由 check_lang_keys 与契约测试覆盖。
        $this->assertSame('blox_product_inquiry', $element->label());
        $this->assertNotSame('product-inquiry', $element->label());
    }
}
