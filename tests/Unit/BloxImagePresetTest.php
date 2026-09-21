<?php
/**
 * 构图预设（E05 切片 B）的判定契约。
 *
 * 最要紧的一条是"预设不覆盖作者已选的值"——套预设不该把调好的比例冲掉。
 * 其次是旧数据：没选预设的图片，输出必须与加这个功能之前一字不差。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxImageFraming;
use BuilderRegistry;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxImagePresetTest extends TestCase
{
    private function render(array $data): string
    {
        $element = BuilderRegistry::get('image');
        self::assertNotNull($element);
        return $element->render($data + ['src' => '/uploads/photo.jpg']);
    }

    public function testPresetSuppliesABaselineWhenNothingWasChosen(): void
    {
        self::assertSame('square', BloxImageFraming::ratio(['image_preset' => 'avatar']));
        self::assertSame('wide', BloxImageFraming::ratio(['image_preset' => 'case']));
        self::assertSame('portrait', BloxImageFraming::ratio(['image_preset' => 'portrait']));
        // 白底产品用 contain：整件装得下比填满重要
        self::assertStringContainsString('object-fit:contain',
            BloxImageFraming::objectStyle(['image_preset' => 'product'], false));
    }

    /** 作者已经选过的值优先——套预设不能把调好的构图冲掉。 */
    public function testAuthorChoicesWinOverThePreset(): void
    {
        self::assertSame('wide', BloxImageFraming::ratio(['image_preset' => 'avatar', 'image_ratio' => 'wide']),
            '显式比例必须压过预设');
        self::assertStringContainsString('object-fit:cover',
            BloxImageFraming::objectStyle(['image_preset' => 'product', 'image_fit' => 'cover'], false),
            '显式填充方式必须压过预设');
        // 焦点（切片 A）与预设并存
        self::assertStringContainsString('object-position:30% 20%',
            BloxImageFraming::objectStyle(['image_preset' => 'avatar', 'focus_x' => 30, 'focus_y' => 20], false));
    }

    /** 没选预设的图片，输出与以前完全一致。 */
    public function testImagesWithoutAPresetAreUnchanged(): void
    {
        self::assertSame('auto', BloxImageFraming::ratio([]));
        self::assertSame('', BloxImageFraming::standaloneStyle([]));
        $html = $this->render([]);
        self::assertStringNotContainsString('border-radius:9999px', $html);
        self::assertStringNotContainsString('<div class="relative">', $html);
    }

    /** 圆形与白底不是比例能表达的，要出现在样式里。 */
    public function testPresetAppearanceReachesTheMarkup(): void
    {
        self::assertStringContainsString('border-radius:9999px', $this->render(['image_preset' => 'avatar']));
        self::assertStringContainsString('background-color:#fff', $this->render(['image_preset' => 'product']));
    }

    /** 图上标题：三段都空就不渲染遮罩——空遮罩只会白白压暗图片。 */
    public function testOverlayIsOmittedWhenEmpty(): void
    {
        $html = $this->render(['image_preset' => 'overlay']);
        self::assertStringNotContainsString('linear-gradient', $html);
        self::assertStringNotContainsString('<div class="relative">', $html);
    }

    public function testOverlayRendersAndEscapesItsText(): void
    {
        $html = $this->render([
            'image_preset' => 'overlay',
            'overlay_title' => '春季 <b>新品</b>',
            'overlay_text' => '限时 & 优惠',
            'overlay_button_label' => '查看',
        ]);
        self::assertStringContainsString('linear-gradient', $html);
        self::assertStringContainsString('&lt;b&gt;', $html, '标题里的标签必须转义');
        self::assertStringContainsString('&amp;', $html);
        self::assertStringContainsString('查看', $html);
        // 遮罩不吃点击：图片本身的链接/灯箱仍然可用
        self::assertStringContainsString('pointer-events-none', $html);
    }

    /** 遮罩与灯箱并存时，链接仍在外层，遮罩不夺走点击。 */
    public function testOverlayCoexistsWithLightbox(): void
    {
        $html = $this->render([
            'image_preset' => 'overlay',
            'overlay_title' => '标题',
            'click_action' => 'lightbox',
        ]);
        self::assertStringContainsString('data-lightbox', $html);
        self::assertStringContainsString('linear-gradient', $html);
        self::assertStringContainsString('pointer-events-none', $html);
    }
}
