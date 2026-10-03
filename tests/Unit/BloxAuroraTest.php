<?php
/** 极光动态背景（2.0.4）：只在容器 / 布局块提供；变量只来自白名单；规则编进 app.css。 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxAuroraTest extends TestCase
{
    private function render(string $type, array $data): string
    {
        return BlockRenderer::render((string) json_encode([[
            'type' => 'custom', 'id' => 's1',
            'columns' => [['width' => 'w-full', 'elements' => [['type' => $type, 'id' => 'e1', 'data' => $data, 'children' => []]]]],
        ]]));
    }

    public function testOnlyContainersAndDivsOfferTheAuroraControls(): void
    {
        $keys = static fn (AbstractElement $element): array => array_column($element->controls(), 'key');
        self::assertContains('bg_aurora', $keys(new ContainerElement()));
        self::assertContains('bg_aurora_opacity', $keys(new DivElement()));
        self::assertNotContains('bg_aurora', $keys(new CardElement()));
        self::assertNotContains('bg_aurora', $keys(new HeadingElement()));
    }

    public function testDeclarationsUseDefaultsWhitelistsAndValidColors(): void
    {
        self::assertSame('', BloxAurora::declarations(['bg_aurora_color_1' => '#ff0000']), '未开启不输出');
        $vars = BloxAurora::declarations([
            'bg_aurora' => true, 'bg_aurora_color_1' => '#ff0000', 'bg_aurora_color_2' => 'red;}body{display:none',
            'bg_aurora_speed' => 'fast', 'bg_aurora_size' => 'huge', 'bg_aurora_blur' => 'lg', 'bg_aurora_opacity' => 500,
        ]);
        self::assertSame(
            '--yk-aurora-1:#ff0000;--yk-aurora-2:var(--yk-color-secondary,#1d4ed8);--yk-aurora-3:#22d3ee;--yk-aurora-4:#a78bfa;'
            . '--yk-aurora-speed:10s;--yk-aurora-size:50%;--yk-aurora-blur:80px;--yk-aurora-opacity:1;',
            $vars,
            '非法颜色回落站点色，非法档位回落默认，不透明度钳到 100%'
        );
    }

    public function testRenderedContainerGetsTheClassAndVariablesOnItsRoot(): void
    {
        $html = $this->render('container', ['bg_aurora' => '1', 'bg_color' => '#111827']);
        self::assertMatchesRegularExpression('/<div class="yk-container[^"]*\byk-aurora\b/', $html);
        self::assertStringContainsString('background-color:#111827;--yk-aurora-1:', $html, '变量接在元素自己的背景声明之后');
        self::assertStringNotContainsString('yk-aurora', $this->render('container', ['bg_color' => '#111827']), '默认输出不变');
    }

    public function testStylesheetIsCompiledIntoAppCssAndStopsForReducedMotion(): void
    {
        $sheet = BloxAurora::stylesheet();
        self::assertStringContainsString('animation:yk-aurora var(--yk-aurora-speed)', $sheet);
        self::assertStringContainsString('@media (prefers-reduced-motion:reduce){.yk-aurora::before{animation:none}}', $sheet);
        $app = (string) file_get_contents(ROOT_PATH . '/assets/css/src/app.css');
        self::assertStringContainsString($sheet, $app, 'app.css 里的规则必须与 BloxAurora::stylesheet() 逐字一致');
        $GLOBALS['_test_config']['motion_intensity'] = 'light';
        try {
            self::assertStringContainsString('.yk-aurora::before{animation:none!important;}', str_replace(['.yk-icon-motion,.yk-logo-track,'], '', BloxMotion::headHtml()));
        } finally {
            unset($GLOBALS['_test_config']['motion_intensity']);
        }
    }
}
