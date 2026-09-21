<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use MediaOptimization;
use MediaReplacement;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MediaReplacement.php';

final class MediaReplacementTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD support is not available');
        }
        $this->directory = ROOT_PATH . '/uploads/images/media-replace-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($this->directory, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    private function writePng(string $path, int $red, int $width = 64, int $height = 48): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, $red, 64, 96));
        imagepng($image, $path);
        imagedestroy($image);
    }

    private function media(string $path, int $id = 7): array
    {
        return ['id' => $id, 'path' => $path, 'url' => substr($path, strlen(ROOT_PATH)), 'type' => 'image'];
    }

    public function testReplaceKeepsUrlAndRebuildsDerivatives(): void
    {
        $path = $this->directory . '/hero.png';
        $this->writePng($path, 40, 1600, 900);
        $before = md5_file($path);
        // 预生成旧衍生，替换后必须重建而不是沿用
        MediaOptimization::repairMany([$this->media($path)]);

        $replacement = $this->directory . '/new-upload.tmp';
        $this->writePng($replacement, 200, 1600, 900);

        $result = MediaReplacement::replace($this->media($path), $replacement, 'png');

        self::assertTrue($result['ok'], json_encode($result));
        self::assertNotSame($before, md5_file($path), '源文件内容已更新');
        self::assertFileExists((string) $result['backup'], '旧文件必须留有备份');
        self::assertSame(1600, $result['meta']['width']);
        self::assertSame(900, $result['meta']['height']);
        // 衍生重建（GD 无 webp 时 repairMany 会判不可修——文件存在性按能力分支）
        self::assertFileExists($this->directory . '/hero_thumb.png');
        self::assertNotSame($before, md5_file($this->directory . '/hero_thumb.png') ?: '', '缩略图随新图重建');
        if (function_exists('imagewebp')) {
            self::assertFileExists($this->directory . '/hero.webp');
        }
    }

    public function testExtensionMismatchIsRejectedAndNothingChanges(): void
    {
        $path = $this->directory . '/doc.pdf';
        file_put_contents($path, '%PDF-1.4 fake');
        $replacement = $this->directory . '/img.tmp';
        $this->writePng($replacement, 10);

        $result = MediaReplacement::replace($this->media($path), $replacement, 'png');

        self::assertFalse($result['ok']);
        self::assertSame('media_replace_ext_mismatch', $result['msg']);
        self::assertSame('%PDF-1.4 fake', file_get_contents($path), '被拒时原文件必须原样');
    }

    public function testFakeImageContentIsRejected(): void
    {
        $path = $this->directory . '/pic.png';
        $this->writePng($path, 30);
        $replacement = $this->directory . '/fake.tmp';
        file_put_contents($replacement, 'not a real image');

        $result = MediaReplacement::replace($this->media($path), $replacement, 'png');

        self::assertFalse($result['ok']);
        self::assertSame('media_replace_not_image', $result['msg']);
        self::assertNotSame('not a real image', substr((string) file_get_contents($path), 0, 16));
    }

    public function testMissingSourceFileFailsCleanly(): void
    {
        $replacement = $this->directory . '/new.tmp';
        $this->writePng($replacement, 60);

        $result = MediaReplacement::replace(
            $this->media($this->directory . '/ghost.png'),
            $replacement,
            'png'
        );

        self::assertFalse($result['ok']);
        self::assertSame('media_replace_source_missing', $result['msg']);
    }

    public function testBackupsArePrunedPerMedia(): void
    {
        $path = $this->directory . '/loop.png';
        $this->writePng($path, 80);
        $media = $this->media($path, 4242);
        $replacement = $this->directory . '/loop.tmp';
        $this->writePng($replacement, 90);

        // 连续替换超过保留数：每条媒体只留 KEEP_BACKUPS 份
        for ($i = 0; $i <= MediaReplacement::KEEP_BACKUPS + 1; $i++) {
            touch($path, time() + $i);   // 拉开 mtime，保证备份文件名与清理排序稳定
            $result = MediaReplacement::replace($media, $replacement, 'png');
            self::assertTrue($result['ok']);
            clearstatcache();
        }
        $backups = glob(ROOT_PATH . '/storage/backups/media/4242-*.*') ?: [];
        self::assertLessThanOrEqual(MediaReplacement::KEEP_BACKUPS, count($backups));
        foreach ($backups as $backup) {
            @unlink($backup);
        }
    }
}
