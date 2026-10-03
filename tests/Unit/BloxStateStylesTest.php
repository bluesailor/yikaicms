<?php
/**
 * 元素级交互状态颜色（2.0.4）：按钮与导航链接直接设置悬停 / 键盘聚焦 / 按下（导航另有当前页）的颜色。
 */
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAssetCollector;
use BloxStateStyles;
use BlockRenderer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxStateStylesTest extends TestCase
{
    protected function setUp(): void
    {
        BloxAssetCollector::resetForTests();
    }

    private function render(string $type, array $data): string
    {
        return BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1',
            'columns' => [['width' => 'w-full', 'elements' => [['type' => $type, 'id' => 'e1', 'data' => $data]]]],
        ]]));
    }

    public function testRulesFollowStateOrderAndOnlyUseValidColors(): void
    {
        $css = BloxStateStyles::rules([
            'state_active_bg_color' => '#111111',
            'state_hover_text_color' => '#c2410c',
            'state_hover_bg_color' => 'red;}body{display:none',
            'state_focus_border_color' => 'var(--yk-color-primary)',
            'state_current_text_color' => '#0b6e8a',
        ], '.x', ' a');
        self::assertSame(
            '.x a[aria-current]{color:#0b6e8a!important}'
            . '.x a:hover{color:#c2410c!important}'
            . '.x a:is(:focus-visible,:has(:focus-visible)){border-color:var(--yk-color-primary)!important}'
            . '.x a:active{background-color:#111111!important}',
            $css,
            '当前页 → 悬停 → 聚焦 → 按下；不合法的颜色丢弃'
        );
        self::assertSame('', BloxStateStyles::rules([], '.x', ' a'));
    }

    public function testButtonGetsAScopedClassAndCollectedRules(): void
    {
        $html = $this->render('button', ['text' => 'Go', 'url' => '/x', 'state_hover_bg_color' => '#0b6e8a', 'state_active_text_color' => '#ffffff']);
        self::assertMatchesRegularExpression('/<div class="[^"]*\byk-st-[0-9a-f]{10}\b/', $html, '作用域类挂在按钮外层');
        preg_match('/yk-st-[0-9a-f]{10}/', $html, $m);
        $styles = BloxAssetCollector::renderStyles();
        self::assertStringContainsString('.' . $m[0] . '.' . $m[0] . ' a:hover{background-color:#0b6e8a!important}', $styles);
        self::assertStringContainsString('.' . $m[0] . '.' . $m[0] . ' a:active{color:#ffffff!important}', $styles);
    }

    public function testElementsWithoutStateValuesAreUnchanged(): void
    {
        self::assertStringNotContainsString('yk-st-', $this->render('button', ['text' => 'Go', 'url' => '/x']));
        self::assertStringNotContainsString('yk-st-', $this->render('heading', ['text' => 'T', 'state_hover_text_color' => '#000000']), '不支持的元素忽略');
    }

    public function testControlsLandInTheStatesGroup(): void
    {
        $nav = array_column((new \NavElement())->controls(), null, 'key');
        self::assertSame('states', $nav['state_current_text_color']['group']);
        self::assertSame('color', $nav['state_hover_bg_color']['type']);
        self::assertArrayNotHasKey('state_hover_border_color', $nav, '导航只给文字与背景');
        $button = array_column((new \ButtonElement())->controls(), null, 'key');
        self::assertArrayHasKey('state_active_border_color', $button);
        self::assertArrayNotHasKey('state_current_text_color', $button, '按钮没有当前页');
        self::assertSame(' li:not([data-yk-nav-cta]) a', (new \NavElement())->stateStyleTarget(), '导航的行动按钮不受影响');
    }
}
