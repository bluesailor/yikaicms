<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 首页性能与输出（2026-10-10 首页源码审查的 P0 + P1）。
 */
final class HomePagePerformanceTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testLateElementStylesLandAtTheEndOfHead(): void
    {
        require_once ROOT_PATH . '/includes/builder/BloxAssetCollector.php';
        $_SERVER['REQUEST_URI'] = '/';
        $level = ob_get_level();

        ob_start();
        echo '<head><link href="/early.css">';
        BloxAssetCollector::openHeadBuffer();
        echo '</head><body><main>正文</main>';
        BloxAssetCollector::addStyle('/assets/css/yikay-carousel.css');   // 渲染正文时才知道要的样式
        BloxAssetCollector::addScript('/assets/js/yikay-carousel.js');
        BloxAssetCollector::renderFooterAssets();
        echo '</body>';
        $html = (string) ob_get_clean();

        self::assertSame($level, ob_get_level(), '缓冲全部收回');
        $style = strpos($html, 'yikay-carousel.css');
        self::assertGreaterThan(strpos($html, 'early.css'), $style);
        self::assertLessThan(strpos($html, '</head>'), $style, '样式落在 </head> 之前');
        self::assertGreaterThan(strpos($html, '</main>'), strpos($html, 'yikay-carousel.js'), '脚本仍在页尾');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAdminAndCanvasRequestsKeepStylesInTheFooter(): void
    {
        require_once ROOT_PATH . '/includes/builder/BloxAssetCollector.php';
        $_SERVER['REQUEST_URI'] = '/admin/blox_preview.php';
        ob_start();
        echo '<head>';
        BloxAssetCollector::openHeadBuffer();
        echo '</head><body>';
        BloxAssetCollector::addStyle('/assets/css/yikay-carousel.css');
        BloxAssetCollector::renderFooterAssets();
        $html = (string) ob_get_clean();
        self::assertGreaterThan(strpos($html, '</head>'), strpos($html, 'yikay-carousel.css'), '画布预览自己分段捕获，不开缓冲');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testUnclosedHeadBufferIsHandedBackBeforeThePageCacheReadsIt(): void
    {
        require_once ROOT_PATH . '/includes/builder/BloxAssetCollector.php';
        $_SERVER['REQUEST_URI'] = '/';
        ob_start();                       // 相当于 HtmlCache::start() 的缓冲
        $cacheLevel = ob_get_level();
        echo '<head>';
        BloxAssetCollector::openHeadBuffer();
        echo '</head><body>没走到页脚就结束';
        BloxAssetCollector::closeHeadBuffer();   // HtmlCache::end() 在 ob_get_clean() 之前调用
        self::assertSame($cacheLevel, ob_get_level());
        self::assertSame('<head></head><body>没走到页脚就结束', ob_get_clean(), '缓存拿到的是整页，不是半截');

        $cache = (string) file_get_contents(ROOT_PATH . '/includes/HtmlCache.php');
        $end = substr($cache, (int) strpos($cache, 'public static function end()'));
        self::assertLessThan(strpos($end, '$html = ob_get_clean();'), strpos($end, 'BloxAssetCollector::closeHeadBuffer()'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFirstBannerImageIsPrioritisedAndLaterSlidesAreLazy(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
        $slides = [];
        foreach (['/uploads/a.jpg', '/uploads/b.jpg', '/uploads/c.jpg'] as $image) {
            $slides[] = HomeBannerItemElement::responsiveMediaHtml(['title' => 'S', 'image' => $image]);
        }
        self::assertStringContainsString('fetchpriority="high"', $slides[0]);
        self::assertStringNotContainsString('loading="lazy"', $slides[0], '首屏图不能延迟加载');
        foreach ([1, 2] as $i) {
            self::assertStringContainsString('loading="lazy"', $slides[$i]);
            self::assertStringNotContainsString('fetchpriority', $slides[$i]);
        }
    }

    public function testBundledDemoProductImagesShipAMediumVariant(): void
    {
        $originals = glob(ROOT_PATH . '/assets/images/demo/product-*-v2.webp') ?: [];
        self::assertNotEmpty($originals);
        foreach ($originals as $file) {
            $medium = substr($file, 0, -5) . '_medium.webp';
            self::assertFileExists($medium, basename($file) . ' 缺 800px 版本：首页产品卡会下载 1200px 原图');
            [$width] = getimagesize($medium) ?: [0];
            self::assertSame(800, $width, basename($medium));
        }
    }

    public function testInstallDataDoesNotPaintTheCtaBackgroundTwice(): void
    {
        foreach (['sqlite.sql', 'mysql.sql'] as $file) {
            $sql = (string) file_get_contents(ROOT_PATH . '/install/sql/' . $file);
            self::assertDoesNotMatchRegularExpression('/container_bg_image\\\\?":\\\\?"\/themes\/default\/assets\/images\/cta\//', $sql, $file);
            self::assertStringContainsString('cta-smart-manufacturing.webp', $sql, $file . '：CTA 自己的背景要在');
        }
    }

    public function testHomeBannerAssetsAndLegacyScriptsStayLean(): void
    {
        $index = (string) file_get_contents(ROOT_PATH . '/index.php');
        self::assertStringNotContainsString('.banner-swiper { height', $index, '高度只由 yikay-banner.css 的属性规则决定');
        self::assertStringContainsString("assetVer('/assets/swiper/swiper-bundle.min.js')", $index);
        self::assertStringContainsString("assetVer('/assets/swiper/swiper-bundle.min.css')", $index);

        $footer = (string) file_get_contents(ROOT_PATH . '/themes/default/layouts/footer.php');
        $header = (string) file_get_contents(ROOT_PATH . '/themes/default/layouts/header.php');
        self::assertStringContainsString("\$GLOBALS['ykBloxHeaderActive'] = \$ykBloxHeader !== '';", $header);
        self::assertLessThan(strpos($footer, "getElementById('mobileMenuBtn')"), strpos($footer, "empty(\$GLOBALS['ykBloxHeaderActive'])"), 'Blox 头部时不输出旧移动菜单脚本');
    }

    public function testCommonEditorIconsAreInTheSiteSubset(): void
    {
        $audit = json_decode((string) file_get_contents(ROOT_PATH . '/assets/icons/site-icon-audit.json'), true);
        foreach (['arrow-narrow-right', 'arrow-right', 'chevron-right', 'external-link', 'download', 'phone', 'mail', 'map-pin', 'brand-wechat', 'brand-linkedin'] as $icon) {
            self::assertContains($icon, $audit['icons']['tabler'], "{$icon} 不在子集里会让整页加载 462 KB 的完整图标字体");
        }
    }
}
