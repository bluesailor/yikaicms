<?php
/**
 * 连续焦点（E05 切片 A）的判定契约。
 *
 * 三条容易做错的地方：
 *   1. "未设置"与"设为 0"必须分开——0% 是合法焦点（贴左/贴顶），不能被当成空；
 *   2. 旧九宫格数据必须原样生效，老页面的外观不能因为新增控件而改变；
 *   3. 手机档留空即继承桌面档，且两轴各自继承。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxImageFraming;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxImageFraming.php';

final class BloxImageFocusTest extends TestCase
{
    public function testZeroIsARealFocusNotAnEmptyValue(): void
    {
        // 0% 表示贴左/贴顶，是人像照片常用的构图
        self::assertSame([0.0, 0.0], BloxImageFraming::focusFor(['focus_x' => 0, 'focus_y' => 0], false));
        self::assertSame([0.0, 0.0], BloxImageFraming::focusFor(['focus_x' => '0', 'focus_y' => '0'], false));
        self::assertStringContainsString('object-position:0% 0%',
            BloxImageFraming::objectStyle(['focus_x' => 0, 'focus_y' => 0], false));

        // 空串／null 才是"未设置"
        self::assertSame([null, null], BloxImageFraming::focusFor(['focus_x' => '', 'focus_y' => null], false));
    }

    public function testOutOfRangeValuesFallBackInsteadOfBeingClamped(): void
    {
        // 夹取会把明显的输入错误伪装成"生效了"，所以越界按未设置处理
        self::assertSame([null, null], BloxImageFraming::focusFor(['focus_x' => 120, 'focus_y' => -5], false));
        self::assertSame([null, null], BloxImageFraming::focusFor(['focus_x' => 'abc', 'focus_y' => []], false));
    }

    /** 没有焦点设置时，输出必须与加这个功能之前完全一致。 */
    public function testLegacyGridPositionsRenderUnchanged(): void
    {
        foreach ([
            'top-left' => 'left top', 'top' => 'center top', 'top-right' => 'right top',
            'left' => 'left center', 'center' => 'center center', 'right' => 'right center',
            'bottom-left' => 'left bottom', 'bottom' => 'center bottom', 'bottom-right' => 'right bottom',
        ] as $grid => $expected) {
            self::assertStringContainsString('object-position:' . $expected,
                BloxImageFraming::objectStyle(['image_position' => $grid], false), $grid);
        }
        // 完全没设置过的元素
        self::assertStringContainsString('object-position:center center', BloxImageFraming::objectStyle([], false));
    }

    /** 只设一个轴时，另一个轴沿用九宫格的对应分量，而不是粗暴归中。 */
    public function testASingleAxisKeepsTheOtherAxisFromTheGrid(): void
    {
        $style = BloxImageFraming::objectStyle(['image_position' => 'top', 'focus_y' => 30], false);
        // 九宫格 top ＝ 横向 50%，纵向被焦点覆盖为 30%
        self::assertStringContainsString('object-position:50% 30%', $style);

        $style = BloxImageFraming::objectStyle(['image_position' => 'left', 'focus_x' => 20], false);
        self::assertStringContainsString('object-position:20% 50%', $style);
    }

    public function testMobileFocusInheritsPerAxis(): void
    {
        $data = ['focus_x' => 40, 'focus_y' => 60, 'focus_y_m' => 20];
        self::assertSame([40.0, 60.0], BloxImageFraming::focusFor($data, false), '桌面档不受手机值影响');
        // 只覆盖了 y：x 仍继承桌面的 40
        self::assertSame([40.0, 20.0], BloxImageFraming::focusFor($data, true));

        $both = ['focus_x' => 40, 'focus_y' => 60, 'focus_x_m' => 10, 'focus_y_m' => 90];
        self::assertSame([10.0, 90.0], BloxImageFraming::focusFor($both, true));
    }

    /** 小数焦点的输出必须稳定，否则同一设置会在不同请求产出不同 HTML（影响缓存与比对）。 */
    public function testPercentFormattingIsStable(): void
    {
        $style = BloxImageFraming::objectStyle(['focus_x' => 33.5, 'focus_y' => 66.25], false);
        self::assertStringContainsString('object-position:33.5% 66.25%', $style);
        $again = BloxImageFraming::objectStyle(['focus_x' => 33.5, 'focus_y' => 66.25], false);
        self::assertSame($style, $again);
        // 整数不该带小数点
        self::assertStringContainsString('object-position:50% 50%',
            BloxImageFraming::objectStyle(['focus_x' => 50.0, 'focus_y' => 50], false));
    }

    /** 焦点只改变展示构图，绝不触碰 object-fit 的既有语义。 */
    public function testFitIsUnaffectedByFocus(): void
    {
        self::assertStringContainsString('object-fit:contain',
            BloxImageFraming::objectStyle(['image_fit' => 'contain', 'focus_x' => 10], false));
        self::assertStringContainsString('object-fit:cover',
            BloxImageFraming::objectStyle(['focus_x' => 10], false));
    }
}
