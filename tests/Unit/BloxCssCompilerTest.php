<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxCssCompiler.php';

/** E05：声明式 CSS 编译器的安全边界与确定性输出。 */
final class BloxCssCompilerTest extends TestCase
{
    private function control(string $key, string $property, array $extra = []): array
    {
        return ['key' => $key, 'type' => BloxCssCompiler::CONTROL_TYPE, 'css' => [['property' => $property] + $extra]];
    }

    public function testScalarValuesCompileToInlineRootDeclarationsInSchemaOrder(): void
    {
        $controls = [
            $this->control('size', 'font-size'),
            $this->control('weight', 'font-weight'),
            ['key' => 'text', 'type' => 'text'],
            $this->control('gap', 'gap'),
        ];
        $result = BloxCssCompiler::compile($controls, ['size' => 32, 'weight' => '700', 'gap' => '0', 'text' => 'ignored']);
        self::assertSame('font-size:32px;font-weight:700;gap:0px;', $result['style']);
        self::assertSame([], $result['classes']);
        // 同输入同输出。
        self::assertSame($result, BloxCssCompiler::compile($controls, ['size' => 32, 'weight' => '700', 'gap' => '0', 'text' => 'ignored']));
    }

    public function testUnsetClearAndZeroAreDistinct(): void
    {
        $controls = [$this->control('gap', 'gap')];
        self::assertSame('', BloxCssCompiler::compile($controls, [])['style']);
        self::assertSame('', BloxCssCompiler::compile($controls, ['gap' => ''])['style']);
        self::assertSame('', BloxCssCompiler::compile($controls, ['gap' => null])['style']);
        self::assertSame('gap:0px;', BloxCssCompiler::compile($controls, ['gap' => 0])['style']);
        self::assertSame('gap:0px;', BloxCssCompiler::compile($controls, ['gap' => ['d' => '0']])['style']);
    }

    public function testResponsiveValuesInheritFromLargerScreensAndUseFixedVariableClasses(): void
    {
        $controls = [$this->control('size', 'font-size')];
        $result = BloxCssCompiler::compile($controls, ['size' => ['d' => 40, 't' => '', 'm' => 24]]);
        self::assertSame('--yk-r-font-size-m:24px;--yk-r-font-size-t:40px;--yk-r-font-size-d:40px;', $result['style']);
        self::assertSame(['yk-r-font-size'], $result['classes']);
        // 全断点一致时退化为单条内联声明。
        self::assertSame('font-size:40px;', BloxCssCompiler::compile($controls, ['size' => ['d' => 40, 't' => 40, 'm' => '']])['style']);

        $sheet = BloxCssCompiler::responsiveStylesheet();
        self::assertStringContainsString('.yk-r-font-size{font-size:var(--yk-r-font-size-m)}', $sheet);
        self::assertStringContainsString('@media (min-width:768px){', $sheet);
        self::assertStringContainsString('@media (min-width:1024px){', $sheet);
    }

    public function testPartialOverridesKeepWiderScreensUntouchedAndPreserveZero(): void
    {
        $controls = [$this->control('gap', 'gap')];
        self::assertSame([
            'style' => '--yk-r-gap-t:30px;--yk-r-gap-m:0px;',
            'classes' => ['yk-r-gap-t-only', 'yk-r-gap-m-only'],
        ], BloxCssCompiler::compile($controls, ['gap' => ['d' => '', 't' => 30, 'm' => 0]]));
        self::assertSame([
            'style' => '--yk-r-gap-m:0px;', 'classes' => ['yk-r-gap-m-only'],
        ], BloxCssCompiler::compile($controls, ['gap' => ['m' => 0]]));
        self::assertSame('--yk-r-gap-t:30px;--yk-r-gap-m:30px;',
            BloxCssCompiler::compile($controls, ['gap' => ['t' => 30, 'm' => '']])['style']);
        self::assertSame(['style' => '', 'classes' => []],
            BloxCssCompiler::compile($controls, ['gap' => ['d' => '', 't' => '', 'm' => '']]));
        $css = BloxCssCompiler::responsiveStylesheet();
        self::assertStringContainsString('@media not all and (min-width:1024px){', $css);
        self::assertStringContainsString('@media not all and (min-width:768px){', $css);
        self::assertStringContainsString('.yk-r-gap-m-only{gap:var(--yk-r-gap-m)}', $css);
    }

    public function testMaliciousOrOutOfRangeValuesAreDropped(): void
    {
        $controls = [$this->control('size', 'font-size'), $this->control('weight', 'font-weight'), $this->control('radius', 'border-radius')];
        foreach (['</style><script>', '12px;color:red', '12;background:url(x)', 'var(--x)', '1e3', '-4', '161', 'NaN', ['d' => '9999']] as $bad) {
            self::assertSame('', BloxCssCompiler::compile($controls, ['size' => $bad])['style'], 'accepted ' . json_encode($bad));
        }
        self::assertSame('', BloxCssCompiler::compile($controls, ['weight' => 'bold; color:red'])['style']);
        self::assertSame('border-radius:999px;', BloxCssCompiler::compile($controls, ['radius' => 999])['style']);
        self::assertSame('', BloxCssCompiler::compile($controls, ['radius' => INF])['style']);
    }

    public function testUntrustedSchemaShapesAreRejected(): void
    {
        foreach ([
            ['key' => 'x', 'css' => [['property' => 'background-image']]],
            ['key' => 'x', 'css' => [['property' => 'color']]],
            ['key' => 'x', 'css' => [['property' => 'font-size', 'selector' => ' ~ p']]],
            ['key' => 'x', 'css' => [['property' => 'font-size', 'selector' => 'body']]],
            ['key' => 'x', 'css' => ['font-size']],
        ] as $control) {
            try {
                BloxCssCompiler::compile([$control], ['x' => 12]);
                self::fail('Accepted schema: ' . json_encode($control));
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('control x', $error->getMessage());
            }
        }
    }

    public function testLaterDeclarationOfSamePropertyOverridesWithoutReordering(): void
    {
        $controls = [$this->control('a', 'font-size'), $this->control('b', 'gap'), $this->control('c', 'font-size')];
        self::assertSame('font-size:20px;gap:8px;', BloxCssCompiler::compile($controls, ['a' => 12, 'b' => 8, 'c' => 20])['style']);
        self::assertSame('font-size:12px;gap:8px;', BloxCssCompiler::compile($controls, ['a' => 12, 'b' => 8, 'c' => ''])['style']);
    }

    public function testFlexPropertiesCompileWithTypedValueRules(): void
    {
        // 0a 布局引擎：长度带 px、number 保留一位小数、int 拒绝小数与越界。
        $controls = [
            $this->control('rg', 'row-gap'),
            $this->control('cg', 'column-gap'),
            $this->control('basis', 'flex-basis'),
            $this->control('grow', 'flex-grow'),
            $this->control('shrink', 'flex-shrink'),
            $this->control('order', 'order'),
        ];
        $result = BloxCssCompiler::compile($controls, [
            'rg' => 24, 'cg' => 0, 'basis' => 320, 'grow' => '1.5', 'shrink' => 0, 'order' => '-2',
        ]);
        self::assertSame('row-gap:24px;column-gap:0px;flex-basis:320px;flex-grow:1.5;flex-shrink:0;order:-2;', $result['style']);
        self::assertSame([], $result['classes']);

        foreach (['1.5', 11, -11] as $bad) {
            self::assertSame('', BloxCssCompiler::compile([$this->control('order', 'order')], ['order' => $bad])['style'], 'order accepted ' . json_encode($bad));
        }
        self::assertSame('', BloxCssCompiler::compile([$this->control('basis', 'flex-basis')], ['basis' => 1201])['style']);

        // 响应式路径与既有属性同构：变量 + 标记类，宽屏仅在不同于桌面时输出。
        $responsive = BloxCssCompiler::compile([$this->control('basis', 'flex-basis')], ['basis' => ['d' => 320, 'm' => 160, 'w' => 400]]);
        self::assertSame('--yk-r-flex-basis-m:160px;--yk-r-flex-basis-t:320px;--yk-r-flex-basis-d:320px;--yk-r-flex-basis-w:400px;', $responsive['style']);
        self::assertSame(['yk-r-flex-basis'], $responsive['classes']);
        $sheet = BloxCssCompiler::responsiveStylesheet();
        foreach (['row-gap', 'column-gap', 'flex-basis', 'flex-grow', 'flex-shrink', 'order'] as $property) {
            self::assertStringContainsString('.yk-r-' . $property . '{' . $property . ':var(--yk-r-' . $property . '-m)}', $sheet);
        }
    }

    public function testStaticStylesheetMatchesCompilerRules(): void
    {
        // 固定响应式规则随 app.css 编译进静态样式表；两处必须逐字一致，避免新增属性后前台缺规则。
        self::assertStringContainsString(
            BloxCssCompiler::responsiveStylesheet(),
            (string) file_get_contents(ROOT_PATH . '/assets/css/src/app.css')
        );
    }

    public function testSaveSanitizerKeepsUnsetAndZeroWithoutNumericDefaults(): void
    {
        $control = $this->control('size', 'font-size');
        self::assertSame('', BloxCssCompiler::sanitizeValue($control, ''));
        self::assertSame('', BloxCssCompiler::sanitizeValue($control, 'abc'));
        self::assertSame(0, BloxCssCompiler::sanitizeValue($this->control('gap', 'gap'), '0'));
        self::assertSame(['d' => 40, 't' => '', 'm' => 24], BloxCssCompiler::sanitizeValue($control, ['d' => '40', 't' => '', 'm' => 24, 'x' => 1]));
        self::assertSame('', BloxCssCompiler::sanitizeValue($control, ['d' => '', 't' => 'bad']));
    }
}
