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

    /**
     * R04-2 前半：脚本没跑起来时，字段不能落进 URL。
     *
     * 浏览器按表单自身的 method/action 提交；缺 method 时默认 GET，会把姓名/电话
     * 拼进当前页查询串。这里锁死「显式 POST + 受控地址」，并确认提交目标只有一处。
     */
    public function testPublishedFormSubmitsByPostToControlledEndpoint(): void
    {
        $html = self::renderWith(['id' => 12, 'title' => '示例产品']);

        $this->assertStringContainsString('method="post"', $html);
        $this->assertStringContainsString('action="/form_submit.php?_lang=', $html);
        $this->assertStringNotContainsString('data-yk-inquiry-endpoint', $html, '提交目标只保留 action 一处');
        $this->assertStringContainsString('form.getAttribute("action")', $html, '脚本从 action 取地址');
        $this->assertStringContainsString('if(preview||endpoint==="")', $html, '无地址/预览态一律不发请求');
    }

    /**
     * R04-2 后半：预览态不得产生真实询价。
     *
     * 用与原生「主题默认预览」同款做法（product.php 的 fieldset disabled）：
     * 字段禁用 + 按钮禁用 + 不输出提交目标，脚本再拦一层。
     */
    public function testPreviewModeIsStructurallyUnsubmittable(): void
    {
        $element = new ProductInquiryElement();
        ProductTemplateDocument::markPreview();
        try {
            $html = self::renderWith(['id' => 12, 'title' => '示例产品']);
        } finally {
            ProductTemplateDocument::markPreview(false);   // 静态标记不能漏给后续用例
        }

        $this->assertStringContainsString('data-yk-preview="1"', $html);
        $this->assertStringContainsString('<fieldset disabled', $html);
        $this->assertStringContainsString(' disabled class=', $html, '提交按钮也要禁用');
        $this->assertStringNotContainsString('action="', $html, '预览态不得带真实提交地址');
        $this->assertStringNotContainsString('method="post"', $html);
        $this->assertStringContainsString('blox_product_inquiry_preview', $html, '需明确告知不会提交');
        // 令牌与蜜罐字段照常输出：作者看到的布局就是发布后的布局
        $this->assertStringContainsString('name="form_sig"', $html);
        $this->assertStringContainsString('name="hp_url"', $html);
    }

    /** 发布态不得被预览标记污染（前台真实提交必须仍然可用）。 */
    public function testPreviewFlagDoesNotLeakIntoPublishedRender(): void
    {
        ProductTemplateDocument::markPreview(false);
        $html = self::renderWith(['id' => 5, 'title' => 'T']);

        $this->assertStringNotContainsString('data-yk-preview="1"', $html);
        $this->assertStringContainsString('method="post"', $html);
    }
}
