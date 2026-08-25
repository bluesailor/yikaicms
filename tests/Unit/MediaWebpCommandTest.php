<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/image.php';
require_once ROOT_PATH . '/includes/CLI.php';

if (!defined('IK_CLI')) {
    define('IK_CLI', true);
}
require_once ROOT_PATH . '/includes/commands/media.php';

final class MediaWebpCommandTest extends TestCase
{
    private string $relativeDirectory;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available');
        }

        $name = 'webp-command-' . getmypid() . '-' . bin2hex(random_bytes(3));
        $this->relativeDirectory = 'images/' . $name;
        $this->directory = ROOT_PATH . '/uploads/' . $this->relativeDirectory;
        mkdir($this->directory, 0775, true);
        $this->writePng($this->directory . '/photo.png', 160, 90);
        $this->writePng($this->directory . '/photo_medium.png', 80, 45);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/photo*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        parent::tearDown();
    }

    public function testDryRunThenBackfillGeneratesOriginalAndExistingThumbnail(): void
    {
        [$dryCode, $dryOutput] = $this->runCommand([
            'media:webp',
            '--path=' . $this->relativeDirectory,
            '--dry-run',
        ]);
        $this->assertSame(0, $dryCode);
        $this->assertStringContainsString('待生成 2', $dryOutput);
        $this->assertFileDoesNotExist($this->directory . '/photo.webp');

        [$code, $output] = $this->runCommand([
            'media:webp',
            '--path=' . $this->relativeDirectory,
        ]);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('生成 2', $output);
        $this->assertFileExists($this->directory . '/photo.webp');
        $this->assertFileExists($this->directory . '/photo_medium.webp');
    }

    public function testPathResolutionCannotEscapeUploads(): void
    {
        $this->assertNull(mediaWebpResolveRoot('../'));
        $this->assertNull(mediaWebpResolveRoot('../../config'));
        $this->assertSame(realpath($this->directory), mediaWebpResolveRoot($this->relativeDirectory));
    }

    /** @param list<string> $arguments @return array{0:int,1:string} */
    private function runCommand(array $arguments): array
    {
        ob_start();
        $code = \CLI::dispatch(array_merge(['yikai'], $arguments));
        $output = (string) ob_get_clean();
        return [$code, $output];
    }

    private function writePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, imagecolorallocate($image, 26, 86, 138));
        self::assertTrue(imagepng($image, $path));
        imagedestroy($image);
    }
}
