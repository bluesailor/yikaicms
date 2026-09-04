<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use MediaUsageAudit;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/MediaUsageAudit.php';

final class MediaUsageAuditTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE banners (id INTEGER PRIMARY KEY, title TEXT, image TEXT, image_mobile TEXT, video TEXT)',
            'CREATE TABLE settings (id INTEGER PRIMARY KEY, `key` TEXT, value TEXT)',
            'CREATE TABLE channels (id INTEGER PRIMARY KEY, name TEXT)',
            'CREATE TABLE blox_page_drafts (id INTEGER PRIMARY KEY, page_id INTEGER, draft_data TEXT, published_data TEXT)',
            'CREATE TABLE contents (id INTEGER PRIMARY KEY, channel_id INTEGER, title TEXT, blocks_data TEXT)',
            'CREATE TABLE blox_templates (id INTEGER PRIMARY KEY, name TEXT, draft_data TEXT, published_data TEXT)',
            'CREATE TABLE blocks_library (id INTEGER PRIMARY KEY, name TEXT, data TEXT)',
        ];
    }

    public function testFindsBannerBackgroundAndVideoElementReferencesAcrossStores(): void
    {
        $url = '/uploads/videos/launch.mp4';
        $this->insertRow('banners', [
            'id' => 4, 'title' => 'Launch banner', 'image' => '/poster.jpg', 'image_mobile' => '', 'video' => $url,
        ]);
        $this->insertRow('settings', [
            'id' => 1,
            'key' => 'home_blox_published',
            'value' => $this->document([['type' => 'container', 'data' => ['bg_video' => $url . '?v=2']]]),
        ]);
        $this->insertRow('channels', ['id' => 12, 'name' => 'About']);
        $this->insertRow('blox_page_drafts', [
            'id' => 1,
            'page_id' => 12,
            'draft_data' => $this->document([['type' => 'video', 'data' => ['url' => $url]]]),
            'published_data' => '',
        ]);
        $this->insertRow('blox_templates', [
            'id' => 8,
            'name' => 'Campaign',
            'draft_data' => $this->document([['type' => 'home-banner-item', 'data' => ['video' => $url]]]),
            'published_data' => '',
        ]);

        $audit = MediaUsageAudit::audit([['id' => 9, 'url' => $url]]);
        self::assertSame(4, $audit[9]['count']);
        $kinds = array_values(array_unique(array_column($audit[9]['items'], 'kind')));
        sort($kinds);
        self::assertSame(['background_video', 'banner_video', 'video_element'], $kinds);
        self::assertStringContainsString('media_usage_delete_blocked', MediaUsageAudit::blockedMessage($audit));
    }

    public function testIgnoresSameUrlInUnrelatedLinkFieldsAndMalformedJson(): void
    {
        $url = '/uploads/videos/launch.mp4';
        $this->insertRow('settings', [
            'id' => 1,
            'key' => 'home_blox_data',
            'value' => $this->document([['type' => 'button', 'data' => ['url' => $url]]]),
        ]);
        $this->insertRow('blox_templates', [
            'id' => 2, 'name' => 'Broken', 'draft_data' => '{"video":', 'published_data' => '',
        ]);

        self::assertSame(0, MediaUsageAudit::audit([['id' => 3, 'url' => $url]])[3]['count']);
    }

    public function testAuditsMultipleMediaRowsWithoutCrossMatching(): void
    {
        $this->insertRow('banners', [
            'id' => 1,
            'title' => 'Hero',
            'image' => '/uploads/hero.jpg',
            'image_mobile' => '/uploads/hero-mobile.jpg',
            'video' => '',
        ]);

        $audit = MediaUsageAudit::audit([
            ['id' => 1, 'url' => '/uploads/hero.jpg'],
            ['id' => 2, 'url' => '/uploads/unused.jpg'],
        ]);
        self::assertSame(1, $audit[1]['count']);
        self::assertSame('banner_image', $audit[1]['items'][0]['kind']);
        self::assertSame(0, $audit[2]['count']);
    }

    public function testOlderBannerSchemaStillAuditsImagesWithoutVideoColumn(): void
    {
        db()->getPdo()->exec('DROP TABLE banners');
        db()->getPdo()->exec('CREATE TABLE banners (id INTEGER PRIMARY KEY, title TEXT, image TEXT, image_mobile TEXT)');
        $this->insertRow('banners', [
            'id' => 5, 'title' => 'Legacy hero', 'image' => '/uploads/legacy.jpg', 'image_mobile' => '',
        ]);

        $audit = MediaUsageAudit::audit([['id' => 7, 'url' => '/uploads/legacy.jpg']]);
        self::assertSame(1, $audit[7]['count']);
        self::assertSame('banner_image', $audit[7]['items'][0]['kind']);
    }

    /** @param list<array<string,mixed>> $elements */
    private function document(array $elements): string
    {
        return json_encode([[
            'id' => 's1',
            'settings' => [],
            'columns' => [['id' => 'c1', 'elements' => $elements]],
        ]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
