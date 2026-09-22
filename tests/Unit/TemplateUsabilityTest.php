<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SettingSources;
use SiteExportChecks;

require_once ROOT_PATH . '/includes/SettingSources.php';
require_once ROOT_PATH . '/includes/SiteExportChecks.php';

final class TemplateUsabilityTest extends TestCase
{
    public function testSourcePriorityPreservesEmptyOverride(): void
    {
        $info = SettingSources::inspect('site_name', ['site_name' => 'Database'], ['site_name' => 'File'], ['site_name' => '']);
        self::assertSame('runtime', $info['source']);
        self::assertTrue($info['locked']);
        self::assertSame('', $info['effective']);
        self::assertSame('Database', $info['stored']);
        self::assertSame('File', SettingSources::inspect('site_name', [], ['site_name' => 'File'], [])['effective']);
        self::assertFalse(SettingSources::inspect('site_name', [], [], [])['locked']);
    }

    public function testUnknownAndSensitiveValuesAreNeverDisplayed(): void
    {
        foreach (['smtp_password', 'custom_json', 'site_url', 'contact_email', 'api_token'] as $key) {
            $info = SettingSources::inspect($key, [$key => 'saved-secret'], [$key => 'file-secret'], []);
            self::assertSame('••••', $info['stored']);
            self::assertSame('••••', $info['effective']);
        }
        self::assertSame('••••', SettingSources::inspect('site_name', [], ['site_name' => ['secret']], [])['effective']);
    }

    private function report(mixed $links): array
    {
        $channels = [
            ['id' => 1, 'slug' => 'about', 'type' => 'page', 'lang' => 'zh-CN'],
            ['id' => 2, 'slug' => 'retired', 'type' => 'page', 'parent_id' => 1, 'lang' => 'en'],
        ];
        return SiteExportChecks::inspect(['tables' => ['channels' => [$channels[0]]], 'settings' => ['footer_nav' => $links]], $channels, 'https://site.test:8098');
    }

    public function testOmittedChannelsInHtmlJsonAndQueryUrlsAreReported(): void
    {
        foreach (['/retired.html', '/about/retired.html', '/en/about/retired.html', '/page/2.html',
            'https://site.test:8098/retired.html#intro', '/index.php?yk_route=%2Fretired.html&amp;x=1'] as $link) {
            $report = $this->report(json_encode([['url' => $link]]));
            self::assertCount(1, $report['issues'], $link);
            self::assertSame('usability_export_omitted', $report['issues'][0]['code']);
            self::assertSame('/admin/setting.php?tab=footer', $report['issues'][0]['url']);
        }
        self::assertCount(1, $this->report('<a href="/retired.html">Old</a>')['issues']);
    }

    public function testExternalUnknownAndIncludedLinksAreNotMisreported(): void
    {
        foreach (['/about.html', '/custom-plugin.html', '#section', 'mailto:a@site.test',
            'https://external.test:8098/retired.html', 'https://site.test:8099/retired.html'] as $link) {
            self::assertSame([], $this->report(json_encode(['url' => $link]))['issues'], $link);
        }
        self::assertSame([], $this->report(json_encode(['image' => '/retired.html']))['issues']);
    }

    public function testSettingsForDisabledLanguagesAreNotScanned(): void
    {
        $channels = [
            ['id' => 1, 'slug' => 'privacy', 'type' => 'page', 'lang' => 'zh-CN'],
        ];
        $data = [
            'tables' => ['channels' => []],
            'settings' => [
                'enabled_languages' => '["zh-CN"]',
                'footer_nav_en' => json_encode([['url' => '/privacy.html']]),
                'footer_nav_ja' => json_encode([['url' => '/privacy.html']]),
            ],
        ];

        $report = SiteExportChecks::inspect($data, $channels, '');

        self::assertSame([], $report['issues']);
        self::assertSame(1, $report['scanned']);
    }

    public function testSettingsForEnabledLanguagesAreStillScannedAndDeduplicated(): void
    {
        $channels = [
            ['id' => 1, 'slug' => 'privacy', 'type' => 'page', 'lang' => 'zh-CN'],
        ];
        $link = json_encode([['url' => '/privacy.html'], ['url' => '/privacy.html']]);
        $data = [
            'tables' => ['channels' => []],
            'settings' => [
                'enabled_languages' => '["zh-CN","en","ja"]',
                'footer_nav_en' => $link,
                'footer_nav_ja' => $link,
            ],
        ];

        $report = SiteExportChecks::inspect($data, $channels, '');

        self::assertCount(1, $report['issues']);
        self::assertSame('footer_nav_ja', $report['issues'][0]['label']);
        self::assertSame(3, $report['scanned']);
    }

    public function testOrdinarySettingSuffixesAreNotTreatedAsLanguages(): void
    {
        $channels = [
            ['id' => 1, 'slug' => 'privacy', 'type' => 'page', 'lang' => 'zh-CN'],
        ];
        $data = [
            'tables' => ['channels' => []],
            'settings' => [
                'enabled_languages' => '["zh-CN"]',
                'footer_nav_mobile' => json_encode([['url' => '/privacy.html']]),
            ],
        ];

        $report = SiteExportChecks::inspect($data, $channels, '');

        self::assertCount(1, $report['issues']);
        self::assertSame('footer_nav_mobile', $report['issues'][0]['label']);
    }

    public function testExportCheckIsBoundedAndDoesNotChangeInput(): void
    {
        $channels = [];
        $links = [];
        for ($i = 1; $i <= 120; $i++) {
            $channels[] = ['id' => $i, 'slug' => 'old-' . $i];
            $links[] = ['url' => '/old-' . $i . '.html'];
        }
        $data = ['settings' => ['footer_nav' => json_encode($links)], 'tables' => ['channels' => []]];
        $before = $data;
        $report = SiteExportChecks::inspect($data, $channels, '');
        self::assertCount(100, $report['issues']);
        self::assertTrue($report['limited']);
        self::assertSame($before, $data);
    }
}
