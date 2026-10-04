<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxAssetCollector;
use BloxOverlap;
use BlockRenderer;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

/**
 * 受控错位与叠放（V2.0.1 C）。
 *
 * 任务书的约束：只用已有负 margin，不引入 transform / 绝对定位；手机端默认取消
 * 大幅负边距；装饰层不可获得焦点；层级只能取白名单档位。
 */
final class BloxOverlapTest extends TestCase
{
    protected function tearDown(): void
    {
        BloxAssetCollector::reset();
        parent::tearDown();
    }

    /** @param array<string,mixed> $data */
    private function renderHeading(array $data): string
    {
        return BlockRenderer::renderElementNode(['type' => 'heading', 'data' => ['text' => 'Hi'] + $data]);
    }

    private function css(): string
    {
        return (string) file_get_contents(ROOT_PATH . '/assets/css/yikay-overlap.css');
    }

    // ── 1. 缺省不变 ────────────────────────────────────────────

    public function testElementWithoutOverlapIsUntouched(): void
    {
        $html = $this->renderHeading([]);

        self::assertStringNotContainsString('yk-overlap', $html);
        self::assertStringNotContainsString('yk-stack', $html);
        self::assertStringNotContainsString('inert', $html);
    }

    public function testUnknownPresetAndLayerAreRejected(): void
    {
        $normalized = BloxOverlap::normalizeData(['overlap_preset' => 'float-anywhere', 'stack_layer' => '9999']);
        self::assertSame('', $normalized['overlap_preset']);
        self::assertSame('', $normalized['stack_layer']);

        self::assertStringNotContainsString('yk-overlap', $this->renderHeading(['overlap_preset' => 'float-anywhere']));
    }

    // ── 2. 预设 ────────────────────────────────────────────────

    public function testPresetAddsTwoClassNames(): void
    {
        $html = $this->renderHeading(['overlap_preset' => 'title_over']);

        // 两个类名的选择器压过全局类的单类选择器 → 元素本地值胜出，与 2.0.0 优先级一致
        self::assertStringContainsString('yk-overlap', $html);
        self::assertStringContainsString('yk-overlap--title-over', $html);
    }

    public function testEveryPresetHasMatchingCss(): void
    {
        foreach (BloxOverlap::PRESETS as $preset) {
            $selector = '.yk-overlap.yk-overlap--' . str_replace('_', '-', $preset);
            self::assertStringContainsString($selector, $this->css(), $preset);
        }
    }

    public function testPresetsCarryTheirDefaultLayer(): void
    {
        // 上浮卡片不浮到前景就看不出"浮"
        self::assertSame('front', BloxOverlap::layer(['overlap_preset' => 'lift']));
        self::assertSame('front', BloxOverlap::layer(['overlap_preset' => 'title_over']));
        self::assertSame('', BloxOverlap::layer(['overlap_preset' => 'bleed']));

        // 作者显式选的层级优先于预设自带的
        self::assertSame('back', BloxOverlap::layer(['overlap_preset' => 'lift', 'stack_layer' => 'back']));
    }

    // ── 3. 手机端归零（任务书硬要求）─────────────────────────

    public function testEveryPresetResetsOnPhones(): void
    {
        $css = $this->css();
        $phoneBlock = substr($css, (int) strpos($css, '@media (max-width: 767px)'));

        // 桌面值带 !important，手机端归零也必须带，否则压不过去——负边距会留在手机上
        self::assertStringContainsString('margin-top: 0 !important', $phoneBlock);
        self::assertStringContainsString('margin-inline-start: 0 !important', $phoneBlock);
        foreach (BloxOverlap::PRESETS as $preset) {
            self::assertStringContainsString(str_replace('_', '-', $preset), $phoneBlock, $preset);
        }
    }

    /** 每条带 !important 的位移，都必须有一条同样带 !important 的手机端归零 */
    public function testEveryImportantOffsetHasAnImportantPhoneReset(): void
    {
        $css = $this->css();
        $phoneStart = strpos($css, '@media (max-width: 767px)');
        self::assertIsInt($phoneStart);
        $desktop = substr($css, 0, $phoneStart);
        $phone = substr($css, $phoneStart);

        self::assertGreaterThan(0, substr_count($desktop, '!important'));

        // 手机块里每一条声明都得带 !important（多个预设可以合并在一条规则里，
        // 所以按声明数比、不按预设数比）
        preg_match_all('/margin-(?:top|left)\s*:[^;]+;/', $phone, $m);
        self::assertNotEmpty($m[0]);
        foreach ($m[0] as $declaration) {
            self::assertStringContainsString('!important', $declaration, $declaration);
        }

        // 每个预设都要在手机块里被归零
        foreach (BloxOverlap::PRESETS as $preset) {
            self::assertStringContainsString(str_replace('_', '-', $preset), $phone, $preset);
        }
    }

    public function testNoTransformOrAbsolutePositioningIsUsed(): void
    {
        // 任务书：宁可只用负 margin，也不要半成品 transform
        // 只看声明，不看注释——注释里本来就写着"不用 transform"
        $declarations = (string) preg_replace('#/\*.*?\*/#s', '', $this->css());
        self::assertStringNotContainsString('transform:', $declarations);
        self::assertStringNotContainsString('position: absolute', $declarations);
        self::assertStringNotContainsString('position: fixed', $declarations);
    }

    // ── 4. 层级与装饰层 ────────────────────────────────────────

    public function testFrontLayerRendersClassOnly(): void
    {
        $html = $this->renderHeading(['stack_layer' => 'front']);

        self::assertStringContainsString('yk-stack--front', $html);
        self::assertStringNotContainsString('inert', $html);
    }

    public function testDecorativeBackLayerIsInertSoKeyboardCannotReachIt(): void
    {
        $html = $this->renderHeading(['stack_layer' => 'back']);

        self::assertStringContainsString('yk-stack--back', $html);
        // pointer-events:none 只挡鼠标；键盘要靠 inert
        self::assertStringContainsString('inert', $html);
        self::assertStringContainsString('pointer-events: none', $this->css());
    }

    public function testLayerZIndexComesFromAWhitelistNotAuthorInput(): void
    {
        $css = $this->css();
        self::assertStringContainsString('z-index: 20', $css);
        self::assertStringContainsString('z-index: -1', $css);

        // 作者塞任意数值无效
        self::assertSame('', BloxOverlap::normalizeData(['stack_layer' => '999'])['stack_layer']);
    }

    public function testStylesheetLoadsOnlyWhenUsed(): void
    {
        $this->renderHeading([]);
        self::assertStringNotContainsString(BloxOverlap::STYLESHEET, BloxAssetCollector::renderStyles());

        BloxAssetCollector::reset();
        $this->renderHeading(['overlap_preset' => 'lift']);
        self::assertStringContainsString(BloxOverlap::STYLESHEET, BloxAssetCollector::renderStyles());
    }

    // ── 5. 契约表与 CSS 对齐 ───────────────────────────────────

    public function testContractMatchesTheStylesheetValues(): void
    {
        $contract = [];
        foreach (BloxOverlap::propertyContract() as $field) {
            $contract[$field['key']] = $field;
        }
        self::assertSame(BloxOverlap::dataKeys(), array_keys($contract));

        $css = $this->css();
        foreach ($contract['overlap_preset']['css'] as $preset => $breakpoints) {
            foreach ($breakpoints as $value) {
                // 契约写 'margin-top:-64px'，CSS 里是 'margin-top: -64px'
                [$prop, $val] = explode(':', $value, 2);
                self::assertStringContainsString($prop . ': ' . $val, $css, $preset . ' ' . $value);
            }
        }
    }
}
