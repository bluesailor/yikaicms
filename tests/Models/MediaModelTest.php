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
                ext TEXT NOT NULL
            )",
        ];
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
