<?php
/**
 * 图片编辑：操作历史规范化（纯函数）与 GD 重放（像素级核对方向）。
 */
declare(strict_types=1);
namespace Yikai\Tests\Unit;

use ImageEditor;
use ImageEditPlan;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/media/ImageEditor.php';

final class ImageEditorTest extends TestCase
{
    public function testCropClampsToTheImageAndSetsTheSize(): void
    {
        $plan = ImageEditPlan::normalize(1000, 800, [['op' => 'crop', 'x' => 900, 'y' => -20, 'w' => 500, 'h' => 300]]);
        self::assertSame([['op' => 'crop', 'x' => 900, 'y' => 0, 'w' => 100, 'h' => 300]], $plan['ops']);
        self::assertSame([100, 300], [$plan['width'], $plan['height']]);
        self::assertSame([], ImageEditPlan::normalize(1000, 800, [['op' => 'crop', 'x' => 0, 'y' => 0, 'w' => 1000, 'h' => 800]])['ops'], '全图裁剪等于没裁');
    }

    public function testRotationsMergeAndSwapDimensions(): void
    {
        $plan = ImageEditPlan::normalize(1000, 800, [['op' => 'rotate', 'deg' => 90], ['op' => 'rotate', 'deg' => 90]]);
        self::assertSame([['op' => 'rotate', 'deg' => 180]], $plan['ops']);
        self::assertSame([1000, 800], [$plan['width'], $plan['height']]);
        $plan = ImageEditPlan::normalize(1000, 800, [['op' => 'rotate', 'deg' => -90]]);
        self::assertSame([['op' => 'rotate', 'deg' => 270]], $plan['ops']);
        self::assertSame([800, 1000], [$plan['width'], $plan['height']]);
        self::assertSame([], ImageEditPlan::normalize(10, 10, array_fill(0, 4, ['op' => 'rotate', 'deg' => 90]))['ops'], '转一整圈抵消');
    }

    public function testCropAfterRotationUsesTheRotatedImage(): void
    {
        // 1000×800 顺时针转 90° 后是 800×1000，裁剪坐标按转过的图
        $plan = ImageEditPlan::normalize(1000, 800, [['op' => 'rotate', 'deg' => 90], ['op' => 'crop', 'x' => 0, 'y' => 900, 'w' => 800, 'h' => 500]]);
        self::assertSame(['op' => 'crop', 'x' => 0, 'y' => 900, 'w' => 800, 'h' => 100], $plan['ops'][1]);
        self::assertSame([800, 100], [$plan['width'], $plan['height']]);
    }

    public function testSameAxisFlipsCancel(): void
    {
        self::assertSame([], ImageEditPlan::normalize(10, 10, [['op' => 'flip', 'axis' => 'h'], ['op' => 'flip', 'axis' => 'h']])['ops']);
        self::assertCount(2, ImageEditPlan::normalize(10, 10, [['op' => 'flip', 'axis' => 'h'], ['op' => 'flip', 'axis' => 'v']])['ops']);
    }

    #[DataProvider('invalid')]
    public function testInvalidHistoryIsRejected(array $ops): void
    {
        $this->expectException(InvalidArgumentException::class);
        ImageEditPlan::normalize(100, 100, $ops);
    }

    public static function invalid(): array
    {
        return [
            'unknown op' => [[['op' => 'blur']]],
            'odd angle' => [[['op' => 'rotate', 'deg' => 45]]],
            'bad axis' => [[['op' => 'flip', 'axis' => 'x']]],
            'crop without size' => [[['op' => 'crop', 'x' => 1, 'y' => 1]]],
            'crop with text' => [[['op' => 'crop', 'x' => 'a', 'y' => 1, 'w' => 1, 'h' => 1]]],
            'not an object' => [['crop']],
            'too many' => [array_fill(0, ImageEditPlan::MAX_OPS + 1, ['op' => 'flip', 'axis' => 'h'])],
        ];
    }

    public function testFitNeverUpscales(): void
    {
        self::assertSame([900, 450], ImageEditPlan::fit(2000, 1000, 900));
        self::assertSame([300, 200], ImageEditPlan::fit(300, 200, 900));
    }

    /** 4×2 的图：左上角红、其余白。转 / 翻 / 裁之后看红点落在哪。 */
    public function testGdReplayPutsPixelsWhereThePlanSays(): void
    {
        if (!function_exists('imagecreatetruecolor')) self::markTestSkipped('GD not available');
        $file = tempnam(sys_get_temp_dir(), 'yk-edit') . '.png';
        $img = imagecreatetruecolor(4, 2);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 255, 255, 255));
        imagesetpixel($img, 0, 0, (int) imagecolorallocate($img, 255, 0, 0));
        imagepng($img, $file);
        $red = static fn (\GdImage $g, int $x, int $y): bool => ((imagecolorat($g, $x, $y) >> 16) & 0xFF) === 255 && ((imagecolorat($g, $x, $y) >> 8) & 0xFF) === 0;
        try {
            $r = ImageEditor::render($file, 'png', ImageEditPlan::normalize(4, 2, [['op' => 'rotate', 'deg' => 90]])['ops']);
            self::assertSame([2, 4], [imagesx($r), imagesy($r)]);
            self::assertTrue($red($r, 1, 0), '顺时针 90°：左上角到右上角');

            $f = ImageEditor::render($file, 'png', [['op' => 'flip', 'axis' => 'h']]);
            self::assertTrue($red($f, 3, 0), '左右翻：到右上角');

            $c = ImageEditor::render($file, 'png', [['op' => 'crop', 'x' => 0, 'y' => 0, 'w' => 2, 'h' => 1]]);
            self::assertSame([2, 1], [imagesx($c), imagesy($c)]);
            self::assertTrue($red($c, 0, 0));

            $out = $file . '.out.png';
            self::assertTrue(ImageEditor::save($r, $out, 'png'));
            self::assertSame([2, 4], array_slice((array) getimagesize($out), 0, 2));
            @unlink($out);
        } finally {
            @unlink($file);
        }
    }
}
