<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/SiteTemplateData.php';

final class BloxMotionTest extends TestCase
{
    protected function tearDown(): void
    {
        $GLOBALS['_test_config'] = [];
        BloxAssetCollector::reset();
    }

    public function testSitePolicyIsWhitelistOnlyAndCannotTravelWithATemplate(): void
    {
        foreach (BloxMotion::LEVELS as $level) {
            $GLOBALS['_test_config']['motion_intensity'] = $level;
            self::assertSame($level, BloxMotion::level());
            self::assertStringContainsString('content="' . $level . '"', BloxMotion::headHtml());
        }
        $GLOBALS['_test_config']['motion_intensity'] = '</style><script>alert(1)</script>';
        self::assertSame('standard', BloxMotion::level());
        self::assertStringNotContainsString('<script>', BloxMotion::headHtml());
        self::assertFalse(SiteTemplateData::settingAllowed('motion_intensity'));
    }

    public function testEffectsCollectPolicyOnceBeforeTheirRuntime(): void
    {
        BloxAssetCollector::reset();
        BloxAssetCollector::addScript('/assets/js/yikay-carousel.js');
        BloxAssetCollector::addScript('/assets/js/yikay-interactions.js');
        self::assertSame(['/assets/js/scroll-anim.js', '/assets/js/yikay-carousel.js', '/assets/js/yikay-interactions.js'], BloxAssetCollector::scripts());
        BloxAssetCollector::reset();
        BuilderRegistry::get('heading')->render(['text' => 'No effect']);
        self::assertSame([], BloxAssetCollector::scripts());
        $html = BuilderRegistry::get('heading')->render(['text' => 'Motion', 'animation' => 'fade', 'animation_device' => 'mobile']);
        self::assertStringContainsString('data-animate-device="mobile"', $html);
        self::assertSame(['/assets/js/scroll-anim.js'], BloxAssetCollector::scripts());
    }

    public function testLegacyBloxAssetPathsFromOldThemesMapToRenamedFiles(): void
    {
        BloxAssetCollector::reset();
        BloxAssetCollector::addScript('/assets/js/blox-counter.js');
        BloxAssetCollector::addStyle('/assets/css/blox-banner.css');
        BloxAssetCollector::addScript('/assets/js/blox-not-a-real-file.js');
        self::assertSame(['/assets/js/scroll-anim.js', '/assets/js/yikay-counter.js'], BloxAssetCollector::scripts());
        self::assertSame(['/assets/css/yikay-banner.css'], BloxAssetCollector::styles());
    }

    public function testGroupingIsOptInAndSurvivesDocumentNormalization(): void
    {
        $element = BuilderRegistry::get('container');
        self::assertStringNotContainsString('data-stagger', $element->render([], '<span>A</span>'));
        self::assertStringContainsString('data-stagger', $element->render(['animation_stagger' => true], '<span>A</span><span>B</span>'));
        $document = BloxDocumentPipeline::process(json_encode(['sections' => [['columns' => [['elements' => [['type' => 'container', 'data' => ['animation_stagger' => true, 'children' => []]]]]]]]], JSON_THROW_ON_ERROR));
        self::assertSame('1', $document['sections'][0]['columns'][0]['elements'][0]['data']['animation_stagger']);
    }

    public function testBothLayoutContainersExposeSafeGroupOptions(): void
    {
        foreach (['container', 'div'] as $type) {
            $element = BuilderRegistry::get($type);
            self::assertContains('animation_stagger', array_column($element->controls(), 'key'));
            self::assertStringNotContainsString('data-stagger', $element->render([]));
            $html = $element->render(['animation_stagger' => '1', 'animation_speed' => 'fast', 'animation_device' => 'desktop']);
            self::assertStringContainsString('data-stagger data-animate-speed="fast" data-animate-device="desktop"', $html);
            $invalid = $element->render(['animation_stagger' => true, 'animation_speed' => '"bad', 'animation_device' => '<script>']);
            self::assertStringNotContainsString('data-animate-speed', $invalid);
            self::assertStringNotContainsString('data-animate-device', $invalid);
            self::assertStringNotContainsString('data-stagger', $element->render(['animation_stagger' => 'false']));
        }
    }
    /** 2.0.4 页面切换动画：白名单、随动效强度降级或关闭、尊重减少动态、不随模板迁移。 */
    public function testPageTransitionFollowsTheMotionLevel(): void
    {
        self::assertSame('', BloxMotion::transitionHtml(), '默认关闭');
        $GLOBALS['_test_config']['page_transition'] = 'slide';
        $html = BloxMotion::transitionHtml();
        self::assertStringContainsString('<style data-yk-page-transition="slide">@view-transition{navigation:auto}', $html);
        self::assertStringContainsString('320ms', $html);
        self::assertStringContainsString('html[dir="rtl"]::view-transition-old(root)', $html, '从右到左反向滑动');
        self::assertStringContainsString('@media (prefers-reduced-motion:reduce)', $html);

        $GLOBALS['_test_config']['motion_intensity'] = 'light';
        $light = BloxMotion::transitionHtml();
        self::assertStringContainsString('data-yk-page-transition="fade"', $light, '轻动效只淡入淡出');
        self::assertStringContainsString('200ms', $light);

        $GLOBALS['_test_config']['motion_intensity'] = 'none';
        self::assertSame('', BloxMotion::transitionHtml(), '无动效不播放');

        $GLOBALS['_test_config'] = ['page_transition' => '</style><script>'];
        self::assertSame('none', BloxMotion::transition());
        self::assertSame('', BloxMotion::transitionHtml());
        self::assertFalse(SiteTemplateData::settingAllowed('page_transition'), '站点偏好不随整站模板迁移');
    }

    public function testSinglePagesOverrideTheSiteTransition(): void
    {
        // 2.0.5：Blox 单页的文档设置 page_transition 决定进入该页时的效果；不设 = 跟随全站
        $GLOBALS['_test_config'] = ['page_transition' => 'fade'];
        try {
            BloxMotion::setPageOverride('zoom');
            self::assertStringContainsString('data-yk-page-transition="zoom"', BloxMotion::transitionHtml());
            BloxMotion::setPageOverride('none');
            self::assertSame('', BloxMotion::transitionHtml(), '本页选「无」：不声明 @view-transition，进入本页不播');
            BloxMotion::setPageOverride('</style>');
            self::assertStringContainsString('data-yk-page-transition="fade"', BloxMotion::transitionHtml(), '非法值当作跟随全站');
            BloxMotion::setPageOverride(null);
            self::assertSame('fade', BloxMotion::transition());
            $GLOBALS['_test_config']['page_transition'] = 'none';
            BloxMotion::setPageOverride('slide');
            self::assertStringContainsString('data-yk-page-transition="slide"', BloxMotion::transitionHtml(), '全站关闭时单页仍可单独开启');
            $GLOBALS['_test_config']['motion_intensity'] = 'none';
            self::assertSame('', BloxMotion::transitionHtml(), '无动效的站点单页也不播');
        } finally {
            BloxMotion::setPageOverride(null);
        }
        $doc = \BloxDocumentPipeline::normalizeDocSettings(['page_transition' => 'slide']);
        self::assertSame('slide', $doc['page_transition']);
        self::assertArrayNotHasKey('page_transition', \BloxDocumentPipeline::normalizeDocSettings(['page_transition' => 'spin']));
        self::assertArrayNotHasKey('page_transition', \BloxDocumentPipeline::normalizeDocSettings(['page_transition' => '']), '空 = 跟随全站，不存');
    }
}
