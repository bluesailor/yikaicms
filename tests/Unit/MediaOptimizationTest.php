<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use MediaOptimization;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/MediaOptimization.php';

final class MediaOptimizationTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD support is not available');
        }
        $this->directory = ROOT_PATH . '/uploads/images/media-health-' . getmypid() . '-' . bin2hex(random_bytes(3));
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

    public function testInspectAndRepairCompleteResponsiveDerivativeSet(): void
    {
        $this->requireWebp();
        $path = $this->directory . '/photo.png';
        $this->writePng($path, 1600, 900);
        $media = $this->media($path);

        $before = MediaOptimization::inspect($media);
        $this->assertSame('pending', $before['status']);
        $this->assertTrue($before['repairable']);
        $this->assertSame(6, $before['expected']);
        $this->assertSame(1, $before['ready']);
        $this->assertCount(5, $before['pending']);

        $summary = MediaOptimization::repairMany([$media]);
        $this->assertSame(1, $summary['processed']);
        $this->assertSame(1, $summary['repaired']);
        $this->assertSame(0, $summary['failed']);

        $after = MediaOptimization::inspect($media);
        $this->assertSame('healthy', $after['status']);
        $this->assertSame(6, $after['ready']);
        foreach (['photo_thumb.png', 'photo_medium.png', 'photo.webp', 'photo_thumb.webp', 'photo_medium.webp'] as $file) {
            $this->assertFileExists($this->directory . '/' . $file);
        }
    }

    public function testStaleDerivativesAreDetectedAndRegenerated(): void
    {
        $this->requireWebp();
        $path = $this->directory . '/stale.png';
        $this->writePng($path, 1200, 675);
        $media = $this->media($path, 2);
        MediaOptimization::repairMany([$media]);
        $sourceTime = filemtime($path);
        self::assertIsInt($sourceTime);
        foreach (['stale_medium.png', 'stale_thumb.png', 'stale.webp', 'stale_medium.webp', 'stale_thumb.webp'] as $file) {
            touch($this->directory . '/' . $file, $sourceTime - 10);
        }
        clearstatcache();
        $oldWebpTime = filemtime($this->directory . '/stale.webp');
        self::assertIsInt($oldWebpTime);

        $stale = MediaOptimization::inspect($media);
        $this->assertSame('pending', $stale['status']);
        $this->assertContains('thumbnail:medium', $stale['pending']);
        $this->assertContains('webp:original', $stale['pending']);

        MediaOptimization::repairMany([$media]);
        clearstatcache();
        $this->assertSame('healthy', MediaOptimization::inspect($media)['status']);
        $newWebpTime = filemtime($this->directory . '/stale.webp');
        self::assertIsInt($newWebpTime);
        $this->assertGreaterThan($oldWebpTime, $newWebpTime);
    }

    public function testUnsupportedAndOutsideFilesAreNeverRepairedOrDeleted(): void
    {
        $svg = $this->directory . '/vector.svg';
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $outside = ROOT_PATH . '/config/config.sample.php';

        $this->assertSame('unsupported', MediaOptimization::inspect($this->media($svg, 3, 'svg'))['status']);
        $outsideHealth = MediaOptimization::inspect($this->media($outside, 4, 'php'));
        $this->assertSame('missing', $outsideHealth['status']);
        $this->assertFalse($outsideHealth['repairable']);
        $this->assertSame(0, MediaOptimization::deleteArtifacts($this->media($outside, 4, 'php')));
        $this->assertFileExists($outside);
    }

    public function testMovedSiteResolvesMediaFromCurrentUploadsLocation(): void
    {
        $path = $this->directory . '/moved.png';
        $this->writePng($path, 80, 45);
        $uploadsPath = defined('UPLOADS_PATH') ? UPLOADS_PATH : ROOT_PATH . '/uploads';
        $relative = ltrim(str_replace('\\', '/', substr($path, strlen($uploadsPath))), '/');
        $media = $this->media('D:/retired-site/uploads/' . $relative, 9, 'png', '/uploads/' . $relative);

        $health = MediaOptimization::inspect($media, false);

        $this->assertSame('healthy', $health['status']);
        $this->assertSame(1, $health['ready']);
    }

    public function testDeleteArtifactsRemovesSourceThumbnailsAndWebpOnlyInsideUploads(): void
    {
        $this->requireWebp();
        $path = $this->directory . '/delete.png';
        $this->writePng($path, 1600, 900);
        $media = $this->media($path, 5);
        MediaOptimization::repairMany([$media]);

        $this->assertSame(6, MediaOptimization::deleteArtifacts($media));
        $this->assertSame([], glob($this->directory . '/delete*') ?: []);
    }

    public function testIdsAndRepairBatchAreBounded(): void
    {
        $this->assertSame([2, 5], MediaOptimization::normalizeIds(['2', 2, 'bad', 0, '5']));

        $path = $this->directory . '/bounded.png';
        $this->writePng($path, 80, 45);
        $rows = [];
        for ($id = 1; $id <= 30; $id++) {
            $rows[] = $this->media($path, $id);
        }
        $summary = MediaOptimization::repairMany($rows);
        $this->assertSame(MediaOptimization::MAX_BATCH, $summary['processed']);
    }

    public function testSummaryCountsStatusesAndBoundsAttentionSamples(): void
    {
        $this->requireWebp();
        $pendingPath = $this->directory . '/pending.png';
        $healthyPath = $this->directory . '/healthy.png';
        $svgPath = $this->directory . '/vector.svg';
        $this->writePng($pendingPath, 1200, 675);
        $this->writePng($healthyPath, 1200, 675);
        file_put_contents($svgPath, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        MediaOptimization::repairMany([$this->media($healthyPath, 2)]);

        $rows = [
            $this->media($pendingPath, 1),
            $this->media($healthyPath, 2),
            $this->media($this->directory . '/missing.png', 3),
            $this->media($svgPath, 4, 'svg'),
        ];
        $summary = MediaOptimization::summarizeMany($rows);

        $this->assertSame(4, $summary['scanned']);
        $this->assertSame(1, $summary['healthy']);
        $this->assertSame(1, $summary['pending']);
        $this->assertSame(1, $summary['missing']);
        $this->assertSame(1, $summary['unsupported']);
        $this->assertSame(1, $summary['repairable']);
        $this->assertSame([1, 3], $summary['sample_ids']);

        $manyMissing = [];
        for ($id = 10; $id < 50; $id++) {
            $manyMissing[] = $this->media($this->directory . "/missing-$id.png", $id);
        }
        $bounded = MediaOptimization::summarizeMany($manyMissing);
        $this->assertSame(MediaOptimization::MAX_BATCH, $bounded['scanned']);
        $this->assertCount(MediaOptimization::MAX_BATCH, $bounded['sample_ids']);
    }

    public function testInspectDoesNotRequireWebpWhenEncoderIsUnavailable(): void
    {
        $smallPath = $this->directory . '/small-without-webp.png';
        $this->writePng($smallPath, 80, 45);
        $smallHealth = MediaOptimization::inspect($this->media($smallPath), false);

        $this->assertSame('healthy', $smallHealth['status']);
        $this->assertSame(1, $smallHealth['expected']);
        $this->assertSame([], $smallHealth['pending']);

        $largePath = $this->directory . '/large-without-webp.png';
        $this->writePng($largePath, 1600, 900);

        $health = MediaOptimization::inspect($this->media($largePath), false);

        $this->assertSame('pending', $health['status']);
        $this->assertTrue($health['repairable']);
        $this->assertSame(3, $health['expected']);
        $this->assertSame(['thumbnail:thumb', 'thumbnail:medium'], $health['pending']);
    }

    public function testOversizedImageIsNeverOfferedForRepair(): void
    {
        $previous = $GLOBALS['yikai_config_runtime_overrides'] ?? null;
        $path = $this->directory . '/oversized.png';
        $this->writePng($path, 1600, 900);

        try {
            $GLOBALS['yikai_config_runtime_overrides']['upload_max_megapixels'] = 1;
            $media = $this->media($path);
            $health = MediaOptimization::inspect($media);

            $this->assertSame('unsupported', $health['status']);
            $this->assertTrue($health['applicable']);
            $this->assertFalse($health['repairable']);

            $summary = MediaOptimization::repairMany([$media]);
            $this->assertSame(1, $summary['skipped']);
            $this->assertSame(0, $summary['failed']);
            $this->assertFileDoesNotExist($this->directory . '/oversized_thumb.png');
            $this->assertFileDoesNotExist($this->directory . '/oversized.webp');
        } finally {
            if ($previous === null) {
                unset($GLOBALS['yikai_config_runtime_overrides']);
            } else {
                $GLOBALS['yikai_config_runtime_overrides'] = $previous;
            }
        }
    }

    /** @return array<string,mixed> */
    private function media(string $path, int $id = 1, string $ext = 'png', string $url = ''): array
    {
        return [
            'id' => $id,
            'type' => 'image',
            'path' => $path,
            'url' => $url,
            'ext' => $ext,
        ];
    }

    private function writePng(string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefill($image, 0, 0, imagecolorallocate($image, 38, 112, 92));
        self::assertTrue(imagepng($image, $path));
        imagedestroy($image);
    }

    private function requireWebp(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support is not available');
        }
    }
}
