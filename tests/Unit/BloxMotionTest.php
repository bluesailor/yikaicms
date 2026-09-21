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
        BloxAssetCollector::addScript('/assets/js/blox-carousel.js');
        BloxAssetCollector::addScript('/assets/js/blox-interactions.js');
        self::assertSame(['/assets/js/scroll-anim.js', '/assets/js/blox-carousel.js', '/assets/js/blox-interactions.js'], BloxAssetCollector::scripts());
        BloxAssetCollector::reset();
        BuilderRegistry::get('heading')->render(['text' => 'No effect']);
        self::assertSame([], BloxAssetCollector::scripts());
        $html = BuilderRegistry::get('heading')->render(['text' => 'Motion', 'animation' => 'fade', 'animation_device' => 'mobile']);
        self::assertStringContainsString('data-animate-device="mobile"', $html);
        self::assertSame(['/assets/js/scroll-anim.js'], BloxAssetCollector::scripts());
    }

    public function testGroupingIsOptInAndSurvivesDocumentNormalization(): void
    {
        $element = BuilderRegistry::get('container');
        self::assertStringNotContainsString('data-stagger', $element->render([], '<span>A</span>'));
        self::assertStringContainsString('data-stagger', $element->render(['animation_stagger' => true], '<span>A</span><span>B</span>'));
        $document = BloxDocumentPipeline::process(json_encode(['sections' => [['columns' => [['elements' => [['type' => 'container', 'data' => ['animation_stagger' => true, 'children' => []]]]]]]]], JSON_THROW_ON_ERROR));
        self::assertSame('1', $document['sections'][0]['columns'][0]['elements'][0]['data']['animation_stagger']);
    }
}
