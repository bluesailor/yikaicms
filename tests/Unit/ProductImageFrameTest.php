<?php
/**
 * 产品图片比例与填充（2.3.1）：首页产品区块、产品目录卡片、详情主图与相册。
 *
 * 契约：
 * - 默认「跟随排版」时输出与升级前逐字节一致（旧文档、旧模板不变样）；
 * - 每个位置各存一份 image_ratio / image_fit，自定义比例只在服务端校验通过时生效；
 * - 显示框只改框，不改原图：相册锚点与灯箱仍指向原图。
 */
declare(strict_types=1);

use Yikai\Tests\TestCase;

if (!function_exists('productUrl')) {
    function productUrl(array $product): string
    {
        return '/product/' . ($product['id'] ?? 0) . '.html';
    }
}
if (!function_exists('productPrettyUrl')) {
    function productPrettyUrl(array $product): string
    {
        return '/product/' . ($product['id'] ?? 0) . '.html';
    }
}

final class ProductImageFrameTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testFollowLayoutReturnsTheLayoutClassesUnchanged(): void
    {
        $follow = ['frameClass' => 'aspect-[4/3]', 'frameStyle' => '', 'imgClass' => 'h-full object-cover', 'imgStyle' => '', 'custom' => false];
        foreach ([[], ['image_ratio' => 'default'], ['image_ratio' => 'default', 'image_fit' => ''], ['image_ratio' => 'bogus', 'image_fit' => 'stretch'],
            ['image_ratio' => 'custom'], ['image_ratio' => 'custom', 'image_ratio_w' => '0', 'image_ratio_h' => '4']] as $data) {
            self::assertSame($follow, BloxImageFraming::productFrame($data, 'aspect-[4/3]'), json_encode($data));
        }
    }

    public function testHomepageCardRadiusDistinguishesThemeDefaultFromSquareCorners(): void
    {
        self::assertSame('', HomeBloxBlockSchema::productCardRadiusStyle(null));
        self::assertSame('', HomeBloxBlockSchema::productCardRadiusStyle(''));
        self::assertSame('border-radius:0px;', HomeBloxBlockSchema::productCardRadiusStyle('0'));
        self::assertSame('border-radius:8px;', HomeBloxBlockSchema::productCardRadiusStyle(8));
        self::assertSame('', HomeBloxBlockSchema::productCardRadiusStyle('8; color:red'));
        self::assertSame('', HomeBloxBlockSchema::productCardRadiusStyle(['d' => 8]));
    }

    public function testRatiosFitsAndOriginal(): void
    {
        $f = BloxImageFraming::productFrame(['image_ratio' => 'square', 'image_fit' => 'contain'], 'aspect-[4/3]');
        self::assertSame('', $f['frameClass']);
        self::assertSame('aspect-ratio:1 / 1;', $f['frameStyle']);
        self::assertSame('h-full', $f['imgClass']);
        self::assertSame('object-fit:contain;', $f['imgStyle']);

        self::assertSame('aspect-ratio:16 / 9;', BloxImageFraming::productFrame(['image_ratio' => 'wide'], 'x')['frameStyle']);
        self::assertSame('aspect-ratio:3 / 4;', BloxImageFraming::productFrame(['image_ratio' => 'portrait'], 'x')['frameStyle']);
        // 只选填充方式：框跟随排版，图片换填充
        $fitOnly = BloxImageFraming::productFrame(['image_fit' => 'contain'], 'aspect-square', 'object-cover');
        self::assertSame(['aspect-square', '', 'h-full', 'object-fit:contain;'], [$fitOnly['frameClass'], $fitOnly['frameStyle'], $fitOnly['imgClass'], $fitOnly['imgStyle']]);
        // 只选比例：填充跟随排版
        $ratioOnly = BloxImageFraming::productFrame(['image_ratio' => 'landscape'], 'aspect-square', 'object-contain');
        self::assertSame(['', 'aspect-ratio:4 / 3;', 'h-full object-contain', ''], [$ratioOnly['frameClass'], $ratioOnly['frameStyle'], $ratioOnly['imgClass'], $ratioOnly['imgStyle']]);
        // 原图比例：没有框
        $original = BloxImageFraming::productFrame(['image_ratio' => 'auto', 'image_fit' => 'cover'], 'aspect-[4/3]');
        self::assertSame(['', '', 'h-auto', ''], [$original['frameClass'], $original['frameStyle'], $original['imgClass'], $original['imgStyle']]);
    }

    public function testCustomRatioAcceptsSensibleValuesOnly(): void
    {
        self::assertSame('5 / 4', BloxImageFraming::customRatioCss(['image_ratio_w' => '5', 'image_ratio_h' => 4]));
        self::assertSame('1.91 / 1', BloxImageFraming::customRatioCss(['image_ratio_w' => '1.91', 'image_ratio_h' => '1']));
        self::assertSame('aspect-ratio:5 / 4;', BloxImageFraming::productFrame(['image_ratio' => 'custom', 'image_ratio_w' => 5, 'image_ratio_h' => 4], 'x')['frameStyle']);
        $bad = [
            ['', '4'], ['5', ''], ['0', '4'], ['-5', '4'], ['1001', '4'], ['abc', '4'], ['1.234', '1'],
            ['11', '1'], ['1', '11'], [[5], '4'], [true, '4'], ['5;}', '4'], ['NAN', '1'],
        ];
        foreach ($bad as [$w, $h]) {
            self::assertNull(BloxImageFraming::customRatioCss(['image_ratio_w' => $w, 'image_ratio_h' => $h]), json_encode([$w, $h]));
        }
        self::assertSame('10 / 1', BloxImageFraming::customRatioCss(['image_ratio_w' => 10, 'image_ratio_h' => 1]));
    }

    private function document(string $type, array $data): string
    {
        return json_encode(['schema' => 1, 'settings' => [], 'sections' => [['id' => 's1', 'columns' => [['id' => 'c1', 'elements' => [
            ['id' => 'e1', 'type' => $type, 'data' => $data],
        ]]]]]], JSON_THROW_ON_ERROR);
    }

    public function testSavePipelineRejectsInvalidCustomRatios(): void
    {
        foreach ([['image_ratio_w' => '', 'image_ratio_h' => ''], ['image_ratio_w' => '5', 'image_ratio_h' => '0'], ['image_ratio_w' => '50', 'image_ratio_h' => '2']] as $sides) {
            try {
                BloxDocumentPipeline::process($this->document('product-gallery', ['image_ratio' => 'custom'] + $sides));
                self::fail('invalid custom ratio was saved: ' . json_encode($sides));
            } catch (RuntimeException $e) {
                self::assertStringContainsString('blox_pimg_custom_invalid', $e->getMessage() . ' ' . __('blox_pimg_custom_invalid'));
            }
        }
        $saved = BloxDocumentPipeline::process($this->document('product-gallery', ['image_ratio' => 'custom', 'image_ratio_w' => '5', 'image_ratio_h' => '4', 'image_fit' => 'contain']));
        $data = $saved['sections'][0]['columns'][0]['elements'][0]['data'];
        self::assertSame('custom', $data['image_ratio']);
        self::assertEquals(5, $data['image_ratio_w']);
        self::assertEquals(4, $data['image_ratio_h']);
        self::assertSame('contain', $data['image_fit']);
        // 非自定义时宽高不参与校验
        BloxDocumentPipeline::process($this->document('product-gallery', ['image_ratio' => 'square', 'image_ratio_w' => '', 'image_ratio_h' => '']));
    }

    public function testEachLocationOffersItsOwnControls(): void
    {
        $keys = static fn (array $controls): array => array_values(array_intersect(array_column($controls, 'key'), ['image_ratio', 'image_ratio_w', 'image_ratio_h', 'image_fit']));
        $expected = ['image_ratio', 'image_ratio_w', 'image_ratio_h', 'image_fit'];
        self::assertSame($expected, $keys((new ProductCatalogElement())->controls()));
        self::assertSame($expected, $keys((new ProductFieldElement('gallery'))->controls()));
        self::assertSame($expected, $keys((new ProductFieldElement('image'))->controls()));

        $ratio = array_values(array_filter((new ProductCatalogElement())->controls(), static fn (array $c): bool => $c['key'] === 'image_ratio'))[0];
        self::assertSame('default', $ratio['default']);
        self::assertSame(['default', 'square', 'landscape', 'portrait', 'wide', 'auto', 'custom'], array_keys($ratio['options']));
        self::assertSame('style', $ratio['tab']);
        // 普通下拉：带 option_preview 的控件会被面板集中排到最上面，自定义宽高就离比例很远（浏览器验收发现）
        self::assertArrayNotHasKey('option_preview', $ratio);
        $fit = array_values(array_filter((new ProductCatalogElement())->controls(), static fn (array $c): bool => $c['key'] === 'image_fit'))[0];
        self::assertArrayNotHasKey('option_preview', $fit);
        self::assertSame(['', 'contain', 'cover'], array_keys($fit['options']));
        $width = array_values(array_filter((new ProductCatalogElement())->controls(), static fn (array $c): bool => $c['key'] === 'image_ratio_w'))[0];
        self::assertSame([['image_ratio', '=', 'custom']], $width['visible_when']['terms']);

        // 列表区域选中时设置面板能看到新控件
        $list = array_values(array_filter((new ProductCatalogElement())->regions(), static fn (array $r): bool => $r['key'] === 'list'))[0];
        foreach ($expected as $key) {
            self::assertContains($key, $list['keys']);
        }
    }

    public function testCatalogKeepsLegacyPathAndClassesUntilARatioOrFitIsChosen(): void
    {
        self::assertNull(ProductCatalogLayout::resolveMode([]));
        self::assertNull(ProductCatalogLayout::resolveMode(['image_ratio' => 'default', 'image_fit' => '', 'image_ratio_w' => 0]));
        self::assertNotNull(ProductCatalogLayout::resolveMode(['image_fit' => 'contain']));
        self::assertNotNull(ProductCatalogLayout::resolveMode(['image_ratio' => 'landscape']), '升级前存的比例照旧走新路径');

        foreach (['square' => 'aspect-square', 'landscape' => 'aspect-[4/3]', 'portrait' => 'aspect-[3/4]', 'default' => 'aspect-[4/3]', '' => 'aspect-[4/3]'] as $ratio => $class) {
            $frame = ProductCatalogLayout::imageFrame(['image_ratio' => $ratio]);
            self::assertSame([$class, '', 'h-full object-cover', ''], [$frame['frameClass'], $frame['frameStyle'], $frame['imgClass'], $frame['imgStyle']], (string) $ratio);
        }
        $legacySquareContain = ProductCatalogLayout::imageFrame(['image_ratio' => 'square', 'image_fit' => 'contain']);
        self::assertSame(['aspect-square', 'object-fit:contain;'], [$legacySquareContain['frameClass'], $legacySquareContain['imgStyle']]);
    }

    private function renderGallery(array $data): string
    {
        $gallery = new ProductFieldElement('gallery');
        return ProductTemplateDocument::withProduct(
            ProductTemplateDocument::normalizeContext([
                'product' => ['id' => 12, 'title' => 'P', 'cover' => '/uploads/a.jpg', 'lang' => 'zh-CN'],
                'productImages' => ['/uploads/a.jpg', '/uploads/b.jpg'],
            ]),
            static fn (): string => $gallery->render($data)
        );
    }

    public function testGalleryFollowsLayoutByDefaultAndKeepsOriginalImagesForZoom(): void
    {
        $grid = $this->renderGallery([]);
        self::assertSame(2, substr_count($grid, 'class="block aspect-square overflow-hidden rounded-lg bg-gray-100">'));
        self::assertSame(2, substr_count($grid, 'class="w-full h-full object-cover">'));
        self::assertSame($grid, $this->renderGallery(['image_ratio' => 'default', 'image_fit' => '']));

        $thumbs = $this->renderGallery(['gallery_layout' => 'thumbs']);
        self::assertSame(2, substr_count($thumbs, 'class="w-full h-full object-contain">'));

        $framed = $this->renderGallery(['image_ratio' => 'custom', 'image_ratio_w' => 5, 'image_ratio_h' => 4, 'image_fit' => 'contain']);
        self::assertSame(2, substr_count($framed, 'style="aspect-ratio:5 / 4;"'));
        self::assertSame(2, substr_count($framed, 'style="object-fit:contain;"'));
        self::assertStringNotContainsString('aspect-square', $framed);
        self::assertStringContainsString('href="/uploads/a.jpg" data-yk-gallery-item data-yk-gallery-full="/uploads/a.jpg"', $framed);
    }

    public function testDetailImageKeepsNaturalSizeUntilARatioIsChosen(): void
    {
        $image = new ProductFieldElement('image');
        $render = static fn (array $data): string => ProductTemplateDocument::withProduct(
            ['id' => 12, 'title' => 'P', 'cover' => '/uploads/a.jpg'],
            static fn (): string => $image->render($data)
        );
        $plain = $render([]);
        self::assertStringNotContainsString('aspect-ratio', $plain);
        self::assertSame($plain, $render(['image_ratio' => 'default', 'image_fit' => '']));
        self::assertSame($plain, $render(['image_ratio' => 'auto']));
        $square = $render(['image_ratio' => 'square', 'image_fit' => 'contain']);
        self::assertStringContainsString('aspect-ratio:1 / 1;object-fit:contain;', $square);
        self::assertStringContainsString('aspect-ratio:5 / 4;', $render(['image_ratio' => 'custom', 'image_ratio_w' => '5', 'image_ratio_h' => '4']));
    }
}
