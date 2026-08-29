<?php

declare(strict_types=1);

namespace Yikai\Tests\Models;

use MediaOptimization;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/MediaOptimization.php';

final class MediaModelTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                path TEXT NOT NULL,
                url TEXT NOT NULL,
                type TEXT NOT NULL,
                ext TEXT NOT NULL,
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testWideBackgroundImagesArePrioritizedWithoutHidingSmallerImages(): void
    {
        foreach ([
            ['name' => 'small-new.png', 'width' => 1200],
            ['name' => 'wide-old.png', 'width' => 1920],
            ['name' => 'wide-new.png', 'width' => 2560],
        ] as $item) {
            db()->insert('media', [
                'name' => $item['name'],
                'path' => '/tmp/' . $item['name'],
                'url' => '/uploads/' . $item['name'],
                'type' => 'image',
                'ext' => 'png',
                'width' => $item['width'],
                'height' => 800,
            ]);
        }

        $result = mediaModel()->getList([
            'type' => 'image',
            'preferred_min_width' => 1920,
        ]);

        self::assertSame(['wide-new.png', 'wide-old.png', 'small-new.png'], array_column($result['items'], 'name'));
        self::assertSame(3, $result['total']);
    }

    public function testImageCursorBatchIsOrderedFilteredAndBounded(): void
    {
        for ($id = 1; $id <= 30; $id++) {
            db()->insert('media', [
                'name' => "item-$id",
                'path' => "/tmp/item-$id.png",
                'url' => "/uploads/item-$id.png",
                'type' => $id === 8 ? 'file' : 'image',
                'ext' => $id === 8 ? 'pdf' : 'png',
            ]);
        }

        self::assertSame(29, mediaModel()->countImages());
        $batch = mediaModel()->getImageBatchAfterId(5, 1000);
        self::assertCount(MediaOptimization::MAX_BATCH, $batch);
        self::assertSame(6, (int) $batch[0]['id']);
        self::assertSame(30, (int) $batch[array_key_last($batch)]['id']);
        self::assertNotContains(8, array_map(static fn(array $row): int => (int) $row['id'], $batch));
    }
}
