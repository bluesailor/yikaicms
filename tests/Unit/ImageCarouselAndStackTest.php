<?php
/** 图片轮播元素（缩略图 / 圆点 / 无导航、替代文字兜底、链接清洗）与容器「滚动时堆叠」（v2.1.0）。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAssetCollector;
use BuilderRegistry;
use ImageCarouselElement;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class ImageCarouselAndStackTest extends TestCase
{
    /** @return list<array<string,string>> */
    private static function items(): array
    {
        return [
            ['image' => '/uploads/a.jpg', 'alt' => '车间', 'caption' => '数控车间', 'link' => '/about.html'],
            ['image' => '/uploads/b.jpg', 'alt' => '', 'caption' => '', 'link' => 'javascript:alert(1)'],
            ['image' => '', 'caption' => '没有图片的条目丢掉'],
        ];
    }

    public function testRendersSlidesWithThumbnailsAndSafeLinks(): void
    {
        $html = (new ImageCarouselElement())->render(['items' => self::items(), 'nav' => 'thumbs', 'ratio' => 'square']);
        self::assertSame(2, substr_count($html, 'class="yk-carousel-slide"'), '没有图片的条目不出');
        self::assertStringContainsString('alt="车间"', $html);
        self::assertStringContainsString('<figcaption class="yk-image-carousel-caption">数控车间</figcaption>', $html);
        self::assertStringContainsString('href="/about.html"', $html);
        self::assertStringNotContainsString('javascript:', $html, '链接按站内规则清洗');
        self::assertSame(2, substr_count($html, 'data-yk-carousel-thumb="'), '每张图一个缩略图按钮');
        self::assertStringContainsString('--yk-image-carousel-ratio:1 / 1', $html);
        self::assertStringNotContainsString('data-yk-carousel-dots', $html, '用缩略图就不出圆点');
        self::assertStringContainsString('fetchpriority="high"', $html, '第一张优先加载');
    }

    public function testNavigationOptionsAndAutoplay(): void
    {
        $element = new ImageCarouselElement();
        $dots = $element->render(['items' => self::items()]);
        self::assertStringContainsString('data-yk-carousel-dots', $dots, '默认圆点');
        self::assertStringNotContainsString('data-yk-carousel-autoplay', $dots, '默认不自动播放');
        $none = $element->render(['items' => self::items(), 'nav' => 'none', 'show_arrows' => false, 'autoplay' => true, 'interval' => '3']);
        self::assertStringNotContainsString('data-yk-carousel-dots', $none);
        self::assertStringNotContainsString('data-yk-carousel-thumbs', $none);
        self::assertStringNotContainsString('data-yk-carousel-prev', $none);
        self::assertStringContainsString('data-yk-carousel-autoplay="3000"', $none);
        self::assertSame('', $element->render(['items' => []]));
        self::assertNotNull(BuilderRegistry::get('image-carousel'), '已登记');
    }

    public function testContainerStacksChildrenOnlyWhenAsked(): void
    {
        $container = BuilderRegistry::get('container');
        self::assertStringNotContainsString('yk-stack', $container->render([], '<div>a</div>'));
        BloxAssetCollector::resetForTests();
        $html = $container->render(['stack_scroll' => true], '<div>a</div><div>b</div>');
        self::assertStringContainsString('yk-stack', $html);
        self::assertStringContainsString('yikay-stack.css', BloxAssetCollector::renderStyles(), '只在用到时加载样式');
        self::assertStringNotContainsString('yk-stack', $container->render(['stack_scroll' => true, 'layout' => 'grid'], '<div>a</div>'), '网格布局不堆叠');
        $css = (string) file_get_contents(ROOT_PATH . '/assets/css/yikay-stack.css');
        self::assertStringContainsString('position: sticky', $css);
        self::assertStringContainsString('.yk-stack > :nth-child(12)', $css);
    }
}
