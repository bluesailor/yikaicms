<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/logo-maker/lib.php';

final class LogoMakerTest extends TestCase
{
    public function testLocalOptionsAreNormalizedWithoutBundledFonts(): void
    {
        $options = logoMakerNormalizeLocalOptions([
            'mark' => str_repeat('A', 80),
            'tagline' => str_repeat('B', 80),
            'layout' => 'unsupported',
            'symbol' => 'unsupported',
            'primary' => 'red',
            'secondary' => '#123456',
            'background' => 'url(javascript:bad)',
        ]);

        self::assertSame('horizontal', $options['layout']);
        self::assertSame('circle', $options['symbol']);
        self::assertSame('#2563EB', $options['primary']);
        self::assertSame('#123456', $options['secondary']);
        self::assertSame('transparent', $options['background']);
        self::assertSame(24, mb_strlen($options['mark']));
        self::assertSame(48, mb_strlen($options['tagline']));
    }

    public function testLocalSvgUsesSystemFontFallbacksAndPassesSafetyCheck(): void
    {
        $svg = logoMakerLocalSvg([
            'mark' => 'YikaiCMS',
            'tagline' => 'Build faster',
            'layout' => 'stacked',
            'symbol' => 'diamond',
            'primary' => '#2563EB',
            'secondary' => '#0F172A',
            'background' => '#FFFFFF',
        ]);

        self::assertStringContainsString('YikaiCMS', $svg);
        self::assertStringContainsString('Build faster', $svg);
        self::assertStringContainsString('Microsoft YaHei', $svg);
        self::assertStringNotContainsString('@font-face', $svg);
        self::assertTrue(logoMakerIsSafeSvg($svg));
    }

    public function testUnsafeSvgIsRejected(): void
    {
        self::assertFalse(logoMakerIsSafeSvg('<svg><script>alert(1)</script></svg>'));
        self::assertFalse(logoMakerIsSafeSvg('<svg onload="alert(1)"></svg>'));
    }

    public function testIcoEncoderProducesThreeSizeIco(): void
    {
        $png = imagecreatetruecolor(32, 32);
        self::assertInstanceOf(GdImage::class, $png);
        imagealphablending($png, false);
        imagesavealpha($png, true);
        imagefill($png, 0, 0, imagecolorallocatealpha($png, 37, 99, 235, 0));
        ob_start();
        imagepng($png);
        $pngData = (string) ob_get_clean();
        unset($png);

        $ico = logoMakerBuildIco('data:image/png;base64,' . base64_encode($pngData));
        self::assertSame("\x00\x00\x01\x00", substr($ico, 0, 4));
        self::assertSame(3, unpack('v', substr($ico, 4, 2))[1]);
    }
}
