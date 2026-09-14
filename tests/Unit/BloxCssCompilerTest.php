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
        // 没有桌面基准值时不输出，避免缺失变量把主题默认字号变成 unset。
        self::assertSame('', BloxCssCompiler::compile($controls, ['size' => ['t' => 30, 'm' => 20]])['style']);

        $sheet = BloxCssCompiler::responsiveStylesheet();
        self::assertStringContainsString('.yk-r-font-size{font-size:var(--yk-r-font-size-m)}', $sheet);
        self::assertStringContainsString('@media (min-width:768px){', $sheet);
        self::assertStringContainsString('@media (min-width:1024px){', $sheet);
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
