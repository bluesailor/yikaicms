<?php
/**
 * R7A 单页圆点导航：归一化、条目提取、渲染与旧文档不变性。
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxDotNavTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    /** @param list<array<string,mixed>> $sections */
    private static function document(array $sections, array $docSettings = []): string
    {
        return json_encode([
            'schema' => 1,
            'settings' => $docSettings,
            'sections' => $sections,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function section(string $id, array $settings = [], ?string $name = null): array
    {
        $section = ['id' => $id, 'settings' => $settings, 'columns' => [['id' => $id . '_c', 'elements' => []]]];
        if ($name !== null) {
            $section['name'] = $name;
        }
        return $section;
    }

    public function testSettingsNormalizationClampsPositionAndBooleans(): void
    {
        self::assertSame(
            ['enabled' => false, 'position' => 'right', 'mobile' => false],
            BloxDotNav::normalizeSettings(null)
        );
        self::assertSame(
            ['enabled' => true, 'position' => 'left', 'mobile' => true],
            BloxDotNav::normalizeSettings(['enabled' => '1', 'position' => 'left', 'mobile' => 1])
        );
        self::assertSame('right', BloxDotNav::normalizeSettings(['position' => 'center'])['position']);
    }

    public function testPipelineDerivesStableUniqueAnchorsForParticipants(): void
    {
        $json = self::document([
            self::section('s_alpha', ['dot_nav_on' => true]),
            self::section('s_beta', ['dot_nav_on' => '1', 'anchor_id' => 'features']),
            self::section('s_gamma', []),
        ], ['dot_nav' => ['enabled' => 1, 'position' => 'left', 'mobile' => '1']]);

        $first = BloxDocumentPipeline::process($json, 'page', trustedJson: $json);
        $document = json_decode($first['json'], true);

        self::assertSame(['enabled' => true, 'position' => 'left', 'mobile' => true], $document['settings']['dot_nav']);
        // 自动锚点从稳定节点 ID 派生，且重复归一化不再改变（改名不换锚点）
        self::assertSame('sec-s_alpha', $document['sections'][0]['settings']['anchor_id']);
        self::assertSame('features', $document['sections'][1]['settings']['anchor_id']);
        self::assertArrayNotHasKey('anchor_id', $document['sections'][2]['settings']);

        $second = BloxDocumentPipeline::process($first['json'], 'page', trustedJson: $first['json']);
        self::assertSame(
            'sec-s_alpha',
            json_decode($second['json'], true)['sections'][0]['settings']['anchor_id'],
            '再次归一化必须得到同一锚点'
        );
    }

    public function testItemsOnlyIncludeExplicitVisibleSectionsWithUniqueAnchors(): void
    {
        $document = [
            'settings' => [],
            'sections' => [
                self::section('s1', ['dot_nav_on' => true, 'anchor_id' => 'intro', 'dot_nav_title' => '开场'], '第一屏'),
                self::section('s2', ['dot_nav_on' => true, 'anchor_id' => 'story'], '品牌故事'),
                self::section('s3', ['dot_nav_on' => true, 'anchor_id' => 'noname']),
                self::section('s4', ['dot_nav_on' => true, 'anchor_id' => 'hiddenone', 'hidden' => true], '隐藏屏'),
                self::section('s5', ['anchor_id' => 'not-in-nav'], '未勾选'),
                self::section('s6', ['dot_nav_on' => true, 'anchor_id' => 'Intro'], '重复锚点'),
                self::section('s7', ['dot_nav_on' => true]), // 未归一化：无锚点 → 跳过而不是坏链接
            ],
        ];
        $items = BloxDotNav::items($document);

        self::assertSame(['intro', 'story', 'noname'], array_column($items, 'anchor'));
        self::assertSame('开场', $items[0]['title'], 'dot_nav_title 优先');
        self::assertSame('品牌故事', $items[1]['title'], '缺标题时回落区块名称');
        self::assertSame(str_replace(':n', '3', __('blox_dotnav_section_n')), $items[2]['title'], '最后回落“第 N 屏”');
    }

    public function testRenderIsOffByDefaultRequiresTwoItemsAndEmitsRealAnchors(): void
    {
        $sections = [
            self::section('s1', ['dot_nav_on' => true, 'anchor_id' => 'one', 'dot_nav_title' => 'A<b>'], '甲'),
            self::section('s2', ['dot_nav_on' => true, 'anchor_id' => 'two'], '乙'),
        ];
        self::assertSame('', BloxDotNav::render(['settings' => [], 'sections' => $sections]), '默认关闭');
        self::assertSame('', BloxDotNav::render([
            'settings' => ['dot_nav' => ['enabled' => true]],
            'sections' => [$sections[0]],
        ]), '少于 2 项不渲染');

        $html = BloxDotNav::render([
            'settings' => ['dot_nav' => ['enabled' => true, 'position' => 'left', 'mobile' => true]],
            'sections' => $sections,
        ]);
        self::assertStringContainsString('data-yk-dotnav', $html);
        self::assertStringContainsString('yk-dotnav--left', $html);
        self::assertStringContainsString('yk-dotnav--mobile', $html);
        self::assertStringContainsString('href="#one"', $html, '真实锚点链接，JS 不可用仍可跳转');
        self::assertStringContainsString('href="#two"', $html);
        self::assertStringContainsString('aria-label="A&lt;b&gt;"', $html, '标题必须转义');
        self::assertStringNotContainsString('<b>', $html);
        self::assertSame(2, substr_count($html, 'yk-dotnav__dot'));
    }

    public function testEditorAndFrontWiringContract(): void
    {
        $workspace = (string) file_get_contents(ROOT_PATH . "/admin/blox_editor/partials/workspace.php");
        self::assertStringContainsString("blox-section-dotnav-on", $workspace);
        self::assertStringContainsString("blox-section-dotnav-title", $workspace);
        $overlays = (string) file_get_contents(ROOT_PATH . "/admin/blox_editor/partials/overlays.php");
        foreach (["blox-page-frame-dotnav", "blox-dotnav-enabled", "blox-dotnav-left", "blox-dotnav-right", "blox-dotnav-mobile"] as $testid) {
            self::assertStringContainsString($testid, $overlays);
        }
        $settingsJs = (string) file_get_contents(ROOT_PATH . "/assets/js/blox-page-settings.js");
        self::assertStringContainsString("dot_nav: {", $settingsJs, "网页设置草稿必须带 dot_nav");
        $pagePhp = (string) file_get_contents(ROOT_PATH . "/page.php");
        self::assertStringContainsString('BloxDotNav::render($pageDocument)', $pagePhp);
        $assets = json_decode((string) file_get_contents(ROOT_PATH . "/config/blox-assets.json"), true);
        $flat = json_encode($assets, JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString("assets/css/blox-dot-nav.css", $flat);
        self::assertStringContainsString("assets/js/blox-dot-nav.js", $flat);
        self::assertStringContainsString("assets/js/blox-style-clipboard.js", $flat);
        // 前台 JS 不劫持导航：不 preventDefault、不改 history
        $frontJs = (string) file_get_contents(ROOT_PATH . "/assets/js/blox-dot-nav.js");
        self::assertStringNotContainsString(".preventDefault(", $frontJs);
        self::assertStringNotContainsString(".pushState(", $frontJs);
        self::assertStringContainsString("prefers-reduced-motion", $frontJs);
    }
    public function testLegacyDocumentsStayUntouched(): void
    {
        $json = self::document([self::section('s_old', ['padding' => 'md'])]);
        $processed = json_decode(BloxDocumentPipeline::process($json, 'page', trustedJson: $json)['json'], true);
        self::assertArrayNotHasKey('dot_nav', $processed['settings'], '未使用的功能不写入旧文档');
        self::assertArrayNotHasKey('dot_nav_on', $processed['sections'][0]['settings']);
        self::assertArrayNotHasKey('anchor_id', $processed['sections'][0]['settings']);
        // 渲染器输出不含导航（导航只由 page.php 前台注入）
        self::assertStringNotContainsString('yk-dotnav', BlockRenderer::render($json));
    }
}
