<?php

declare(strict_types=1);

namespace Yikai\Tests\Models;

use MediaModel;
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
                path TEXT NOT NULL DEFAULT '',
                url TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'image',
                ext TEXT NOT NULL DEFAULT '',
                mime TEXT NOT NULL DEFAULT '',
                size INTEGER NOT NULL DEFAULT 0,
                width INTEGER NOT NULL DEFAULT 0,
                height INTEGER NOT NULL DEFAULT 0,
                md5 TEXT NOT NULL DEFAULT '',
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    private function seed(): MediaModel
    {
        $this->insertRow('media', [
            'name' => 'Beta', 'url' => '/beta.jpg', 'type' => 'image',
            'size' => 400, 'width' => 1200, 'height' => 800, 'created_at' => 200,
        ]);
        $this->insertRow('media', [
            'name' => 'Alpha', 'url' => '/alpha.jpg', 'type' => 'image',
            'size' => 100, 'width' => 2400, 'height' => 1200, 'created_at' => 300,
        ]);
        $this->insertRow('media', [
            'name' => 'Gamma', 'url' => '/gamma.mp4', 'type' => 'video',
            'size' => 900, 'created_at' => 100,
        ]);

        return new MediaModel();
    }

    public function testMediaTypeIsDerivedFromTheSharedExtensionPolicy(): void
    {
        self::assertSame('image', MediaModel::typeForExtension('.JPG'));
        self::assertSame('video', MediaModel::typeForExtension('WEBM'));
        self::assertSame('video', MediaModel::typeForExtension('mp4'));
        self::assertSame('file', MediaModel::typeForExtension('pdf'));
        self::assertSame('file', MediaModel::typeForExtension('php'));
        self::assertContains('webm', MediaModel::supportedExtensions());
        self::assertContains('pdf', MediaModel::supportedExtensions());
    }

    public function testListSupportsWhitelistedDateSizeAndNameSorts(): void
    {
        $model = $this->seed();

        self::assertSame(['Alpha', 'Beta'], array_column($model->getList([
            'type' => 'image', 'sort' => MediaModel::SORT_NEWEST,
        ])['items'], 'name'));
        self::assertSame(['Beta', 'Alpha'], array_column($model->getList([
            'type' => 'image', 'sort' => MediaModel::SORT_LARGEST,
        ])['items'], 'name'));
        self::assertSame(['Alpha', 'Beta'], array_column($model->getList([
            'type' => 'image', 'sort' => MediaModel::SORT_NAME,
        ])['items'], 'name'));
    }

    public function testWideBackgroundPreferenceRemainsAheadOfSelectedSort(): void
    {
        $model = $this->seed();
        $items = $model->getList([
            'type' => 'image',
            'sort' => MediaModel::SORT_LARGEST,
            'preferred_min_width' => 1920,
        ])['items'];

        self::assertSame(['Alpha', 'Beta'], array_column($items, 'name'));
    }

    public function testWideBackgroundImagesArePrioritizedWithoutHidingSmallerImages(): void
    {
        foreach ([
            ['name' => 'small-new.png', 'width' => 1200],
            ['name' => 'wide-old.png', 'width' => 1920],
            ['name' => 'wide-new.png', 'width' => 2560],
        ] as $item) {
            $this->insertRow('media', [
                'name' => $item['name'],
                'path' => '/tmp/' . $item['name'],
                'url' => '/uploads/' . $item['name'],
                'type' => 'image',
                'ext' => 'png',
                'width' => $item['width'],
                'height' => 800,
            ]);
        }

        $result = (new MediaModel())->getList([
            'type' => 'image',
            'preferred_min_width' => 1920,
        ]);

        self::assertSame(['wide-new.png', 'wide-old.png', 'small-new.png'], array_column($result['items'], 'name'));
        self::assertSame(3, $result['total']);
    }

    public function testUnknownSortFallsBackWithoutEnteringOrderBy(): void
    {
        $model = $this->seed();
        $items = $model->getList(['type' => 'image', 'sort' => 'size DESC; DROP TABLE media'])['items'];

        self::assertSame(['Alpha', 'Beta'], array_column($items, 'name'));
        self::assertSame(3, (int) db()->fetchColumn('SELECT COUNT(*) FROM media'));
    }

    public function testBundledAndStoredItemsShareTheExplicitSortOrder(): void
    {
        $items = [
            ['id' => 'builtin-one', 'url' => '/builtin.jpg', 'name' => 'Built in', 'size' => 500, 'created_at' => 150, 'builtin' => true],
            ['id' => 2, 'url' => '/stored.jpg', 'name' => 'Stored', 'size' => 100, 'created_at' => 250],
        ];

        usort($items, static fn(array $left, array $right): int => MediaModel::compareItems(
            $left,
            $right,
            MediaModel::SORT_NEWEST
        ));
        self::assertSame(['Stored', 'Built in'], array_column($items, 'name'));

        usort($items, static fn(array $left, array $right): int => MediaModel::compareItems(
            $left,
            $right,
            MediaModel::SORT_DEFAULT
        ));
        self::assertSame(['Built in', 'Stored'], array_column($items, 'name'));
    }

    public function testImageCursorBatchIsOrderedFilteredAndBounded(): void
    {
        for ($id = 1; $id <= 30; $id++) {
            $this->insertRow('media', [
                'name' => "item-$id",
                'path' => "/tmp/item-$id.png",
                'url' => "/uploads/item-$id.png",
                'type' => $id === 8 ? 'file' : 'image',
                'ext' => $id === 8 ? 'pdf' : 'png',
            ]);
        }

        $model = new MediaModel();
        self::assertSame(29, $model->countImages());
        $batch = $model->getImageBatchAfterId(5, 1000);
        self::assertCount(MediaOptimization::MAX_BATCH, $batch);
        self::assertSame(6, (int) $batch[0]['id']);
        self::assertSame(30, (int) $batch[array_key_last($batch)]['id']);
        self::assertNotContains(8, array_map(static fn(array $row): int => (int) $row['id'], $batch));
    }
}
