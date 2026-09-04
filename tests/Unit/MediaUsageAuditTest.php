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
        $this->insertRow('contents', [
            'id' => 15,
            'channel_id' => 12,
            'title' => 'Published story',
            'blocks_data' => $this->document([['type' => 'video', 'data' => ['url' => $url]]]),
        ]);
        $this->insertRow('blocks_library', [
            'id' => 6,
            'name' => 'Video callout',
            'data' => $this->document([['type' => 'container', 'data' => ['bg_video' => $url]]]),
        ]);

        $audit = MediaUsageAudit::audit([['id' => 9, 'url' => $url]]);
        self::assertSame(6, $audit[9]['count']);
        $kinds = array_values(array_unique(array_column($audit[9]['items'], 'kind')));
        sort($kinds);
        self::assertSame(['background_video', 'banner_video', 'video_element'], $kinds);
        self::assertStringContainsString('media_usage_delete_blocked', MediaUsageAudit::blockedMessage($audit));
    }

    public function testIgnoresSameUrlInUnrelatedLinkFields(): void
    {
        $url = '/uploads/videos/launch.mp4';
        $this->insertRow('settings', [
            'id' => 1,
            'key' => 'home_blox_data',
            'value' => $this->document([['type' => 'button', 'data' => ['url' => $url]]]),
        ]);

        self::assertSame(0, MediaUsageAudit::audit([['id' => 3, 'url' => $url]])[3]['count']);
    }

    public function testFindsImageElementsAndBackgroundsInSlashEscapedJson(): void
    {
        $url = '/uploads/images/brand-scene.jpg';
        $document = json_encode([[
            'id' => 's1',
            'settings' => [
                'bg_image' => $url,
                'container_bg_image' => $url,
            ],
            'columns' => [[
                'id' => 'c1',
                'card_bg_image' => $url,
                'elements' => [
                    ['type' => 'container', 'data' => ['bg_image' => $url]],
                    ['type' => 'image', 'data' => [
                        'src' => $url,
                        'loop_fallback' => $url,
                        'link_url' => $url,
                    ]],
                    ['type' => 'card', 'data' => ['image' => $url, 'link' => $url]],
                ],
            ]],
        ]], JSON_THROW_ON_ERROR);
        self::assertStringContainsString('\\/uploads\\/images', $document);
        $this->insertRow('settings', [
            'id' => 2,
            'key' => 'home_blox_data',
            'value' => $document,
        ]);

        $audit = MediaUsageAudit::audit([['id' => 4, 'url' => $url]]);

        self::assertSame(7, $audit[4]['count']);
        $kinds = array_values(array_unique(array_column($audit[4]['items'], 'kind')));
        sort($kinds);
        self::assertSame(['background_image', 'card_image', 'image_element'], $kinds);
    }

    public function testMalformedCandidateFailsClosed(): void
    {
        $url = '/uploads/videos/launch.mp4';
        $this->insertRow('blox_templates', [
            'id' => 2,
            'name' => 'Broken',
            'draft_data' => '{"type":"video","data":{"url":"' . $url . '"}',
            'published_data' => '',
        ]);

        $this->expectException(\RuntimeException::class);
        MediaUsageAudit::audit([['id' => 3, 'url' => $url]]);
    }

    public function testMatchesSameSiteAbsoluteMediaUrlToRelativeReference(): void
    {
        $path = '/uploads/hero.jpg';
        $previousHost = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'cms.example.test';
        $this->insertRow('banners', [
            'id' => 1, 'title' => 'Hero', 'image' => $path, 'image_mobile' => '', 'video' => '',
        ]);

        try {
            $audit = MediaUsageAudit::audit([[
                'id' => 8,
                'url' => 'https://cms.example.test' . $path,
            ]]);
        } finally {
            if ($previousHost === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $previousHost;
            }
        }

        self::assertSame(1, $audit[8]['count']);
    }

    public function testDoesNotMatchExternalHostWithTheSamePath(): void
    {
        $path = '/uploads/hero.jpg';
        $this->insertRow('banners', [
            'id' => 1,
            'title' => 'External hero',
            'image' => 'https://cdn.example.test' . $path,
            'image_mobile' => '',
            'video' => '',
        ]);

        $audit = MediaUsageAudit::audit([['id' => 8, 'url' => $path]]);

        self::assertSame(0, $audit[8]['count']);
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
