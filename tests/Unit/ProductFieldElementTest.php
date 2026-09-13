<?php
/**
 * ProductFieldElement 派生业务字段 + ProductTemplateDocument::normalizeContext 契约测试。
 *
 * 覆盖 TASK-001 要求 4 的前半步：相册 / 参数 / 上下篇 / 相关，以及
 * 「控制器输出 → 渲染上下文」的归一（产品行本身没有相册与参数）。
 * 询价表单元素不在本批（需 AJAX 处理与浏览器验证）。
 */
declare(strict_types=1);

use Yikai\Tests\TestCase;

// 测试引导只加载最小集合：productUrl 由既有测试按同一风格提供，productPrettyUrl 尚缺。
// 产品按钮元素在生产走 functions.php 的 productPrettyUrl；这里给同形最小实现，
// 仅用于断言"按钮链到产品自身"，不复制 SEO 逻辑。
if (!function_exists('productPrettyUrl')) {
    function productPrettyUrl(array $product): string
    {
        return '/product/' . ($product['id'] ?? 0) . '.html';
    }
}

final class ProductFieldElementTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    /** ProductDetailController::prepare() 的返回形状。 */
    private static function controllerVars(): array
    {
        return [
            'product' => [
                'id' => 12, 'title' => '示例产品', 'subtitle' => '副标题', 'summary' => '摘要',
                'content' => '<p>正文</p>', 'cover' => '/uploads/cover.jpg', 'model' => 'M-1',
                'price' => '99', 'lang' => 'zh-CN', 'category_id' => 5, 'tags' => 'a,b',
            ],
            'productCategory' => ['id' => 5, 'name' => '分类'],
            'productImages' => ['/uploads/cover.jpg', '/uploads/2.jpg', ''],
            'specs' => [['name' => '材质', 'value' => '钢'], ['name' => '', 'value' => '']],
            'prevProduct' => ['id' => 11, 'title' => '上一个', 'slug' => 'prev'],
            'nextProduct' => ['id' => 13, 'title' => '下一个', 'slug' => 'next'],
            'relatedProducts' => [['id' => 20, 'title' => '相关一', 'slug' => 'r1'], ['id' => 21, 'title' => '']],
        ];
    }

    public function testContextNormalizationPullsDerivedDataFromControllerOutput(): void
    {
        $ctx = ProductTemplateDocument::normalizeContext(self::controllerVars());

        $this->assertSame('示例产品', $ctx['title']);
        $this->assertSame(['/uploads/cover.jpg', '/uploads/2.jpg'], $ctx['images'], '空字符串图片被剔除');
        $this->assertSame([['name' => '材质', 'value' => '钢']], $ctx['specs'], '空参数被剔除');
        $this->assertSame(11, $ctx['prev']['id']);
        $this->assertSame(13, $ctx['next']['id']);
        $this->assertCount(1, $ctx['related'], '无标题的相关项被剔除');
        $this->assertSame('分类', $ctx['category']['name']);
    }

    public function testContextNormalizationAcceptsABareProductRow(): void
    {
        // 旧调用方式（只传产品行）仍可用：派生字段为空，而不是报错
        $ctx = ProductTemplateDocument::normalizeContext(['id' => 7, 'title' => '裸行']);
        $this->assertSame(7, $ctx['id']);
        $this->assertSame('裸行', $ctx['title']);
        $this->assertSame([], $ctx['images']);
        $this->assertSame([], $ctx['specs']);
        $this->assertNull($ctx['prev']);
        $this->assertNull($ctx['next']);
        $this->assertSame([], $ctx['related']);
    }

    public function testGalleryRendersRealImagesAndHidesWhenEmpty(): void
    {
        $gallery = new ProductFieldElement('gallery');

        $full = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(self::controllerVars()),
            static fn(): string => $gallery->render([])
        );
        $this->assertStringContainsString('/uploads/cover.jpg', $full);
        $this->assertStringContainsString('/uploads/2.jpg', $full);
        $this->assertSame(2, substr_count($full, '<img '), '只渲染真实存在的图片');

        $empty = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(['id' => 1, 'title' => 'T']),
            static fn(): string => $gallery->render([])
        );
        $this->assertSame('', $empty, '无相册时整块隐藏，不显示假图');
    }

    /**
     * R04-1：相册必须接上站点既有 PhotoSwipe 灯箱，而不是只有 img 网格。
     *
     * 这里断言的是「可点击放大的连接结构 + 引擎资源到位」，放大/滑动/键盘本身由
     * PhotoSwipe 提供（浏览器实测另有机动）。纯 img 网格会在这里失败。
     */
    public function testGalleryWiresExistingPhotoSwipeInsteadOfPlainImageGrid(): void
    {
        $gallery = new ProductFieldElement('gallery');
        $html = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(self::controllerVars()),
            static fn(): string => $gallery->render([])
        );

        // 每张图一个可点击锚点，且 href 指原图（大图浏览），不是缩略图
        $this->assertSame(2, substr_count($html, 'data-yk-gallery-item'), '每张图都要有灯箱锚点');
        $this->assertStringContainsString('href="/uploads/cover.jpg"', $html);
        $this->assertStringContainsString('data-yk-gallery-full="/uploads/cover.jpg"', $html);
        $this->assertStringContainsString('data-yk-gallery>', $html, '容器需带 data-yk-gallery 供绑定');
        $this->assertStringContainsString('blox_product_gallery_zoom', $html, '放大入口需可访问名称（lang key）');

        // 灯箱引擎与绑定脚本：走资源收集器声明 → 页面按实际渲染节点输出去重后的本地资源
        BloxAssetCollector::reset();
        BloxAssetCollector::collectElement($gallery, []);
        $this->assertSame([
            '/assets/photoswipe/photoswipe.umd.min.js',
            '/assets/photoswipe/photoswipe-lightbox.umd.min.js',
            '/assets/js/blox-product-gallery.js',
        ], BloxAssetCollector::scripts(), '相册需声明 PhotoSwipe 引擎与绑定脚本');
        $this->assertSame(['/assets/photoswipe/photoswipe.css'], BloxAssetCollector::styles());

        // 其它派生的文本字段不得顺手带上相册资源
        BloxAssetCollector::reset();
        BloxAssetCollector::collectElement(new ProductFieldElement('specs'), []);
        $this->assertSame([], BloxAssetCollector::scripts());
        $this->assertSame([], BloxAssetCollector::styles());
        BloxAssetCollector::reset();

        $this->assertStringNotContainsString('cdn.', $html);
        $this->assertStringNotContainsString('http://', $html);
    }

    public function testGalleryEscapesImagePathAndSkipsLightboxWhenEmpty(): void
    {
        $gallery = new ProductFieldElement('gallery');
        $html = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext([
                'product' => ['id' => 1, 'title' => 'T'],
                'productImages' => ['/uploads/ok.jpg', '"><script>alert(1)</script>'],
            ]),
            static fn(): string => $gallery->render([])
        );
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&quot;&gt;&lt;script&gt;', $html);

        // 没有相册就不该为它加载灯箱资源
        $empty = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(['id' => 1, 'title' => 'T']),
            static fn(): string => $gallery->render([])
        );
        $this->assertSame('', $empty);
        $this->assertStringNotContainsString('photoswipe', $empty);
    }

    public function testSpecsRenderAndHideWhenEmpty(): void
    {
        $specs = new ProductFieldElement('specs');

        $full = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(self::controllerVars()),
            static fn(): string => $specs->render([])
        );
        $this->assertStringContainsString('材质', $full);
        $this->assertStringContainsString('钢', $full);

        $empty = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(['id' => 1, 'title' => 'T']),
            static fn(): string => $specs->render([])
        );
        $this->assertSame('', $empty, '无参数时不渲染空表');
    }

    public function testPrevNextRendersOnlyExistingSide(): void
    {
        $prevNext = new ProductFieldElement('prev-next');

        $both = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(self::controllerVars()),
            static fn(): string => $prevNext->render([])
        );
        $this->assertStringContainsString('上一个', $both);
        $this->assertStringContainsString('下一个', $both);

        $onlyNext = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext([
                'product' => ['id' => 1, 'title' => 'T'],
                'nextProduct' => ['id' => 2, 'title' => '只有下一个', 'slug' => 'n'],
            ]),
            static fn(): string => $prevNext->render([])
        );
        $this->assertStringContainsString('只有下一个', $onlyNext);
        $this->assertStringNotContainsString('上一个', $onlyNext);

        $none = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(['id' => 1, 'title' => 'T']),
            static fn(): string => $prevNext->render([])
        );
        $this->assertSame('', $none, '两端都没有时整块隐藏');
    }

    public function testRelatedRendersAndHidesWhenEmpty(): void
    {
        $related = new ProductFieldElement('related');

        $full = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(self::controllerVars()),
            static fn(): string => $related->render([])
        );
        $this->assertStringContainsString('相关一', $full);

        $empty = ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(['id' => 1, 'title' => 'T']),
            static fn(): string => $related->render([])
        );
        $this->assertSame('', $empty);
    }

    public function testCoreFieldsKeepWorkingAgainstTheNewContext(): void
    {
        $title = new ProductFieldElement('title');
        $image = new ProductFieldElement('image');
        $content = new ProductFieldElement('content');
        $button = new ProductFieldElement('button');

        ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(self::controllerVars()),
            function () use ($title, $image, $content, $button): string {
                $this->assertStringContainsString('示例产品', $title->render([]));

                $imageHtml = $image->render([]);
                $this->assertStringContainsString('/uploads/cover.jpg', $imageHtml);
                $this->assertStringContainsString('示例产品', $imageHtml, 'alt 用产品标题');

                $this->assertStringContainsString('<p>正文</p>', $content->render([]));

                $buttonHtml = $button->render([]);
                $this->assertStringContainsString('href="/', $buttonHtml, '按钮仍链到产品自身（不是询价入口）');
                return '';
            }
        );
    }

    public function testForgedBindingKeysAreIgnored(): void
    {
        $title = new ProductFieldElement('title');
        ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext(['id' => 1, 'title' => '真标题']),
            function () use ($title): string {
                $html = $title->render(['site_field' => 'site_name', 'site_name' => '站点名', 'loop_title' => 'X']);
                $this->assertStringContainsString('真标题', $html);
                $this->assertStringNotContainsString('站点名', $html);
                return '';
            }
        );
    }

    public function testNewFieldsAreRegisteredAndProductScoped(): void
    {
        foreach (['gallery', 'specs', 'prev-next', 'related'] as $field) {
            $element = new ProductFieldElement($field);
            $this->assertTrue($element->isDynamic());
            $this->assertTrue($element->paletteVisible('product-detail'));
            $this->assertFalse($element->paletteVisible('page'));
            $this->assertNotSame($field, $element->label(), '标签必须是三语 key 的翻译，而不是原始字段名');
        }
    }
}
