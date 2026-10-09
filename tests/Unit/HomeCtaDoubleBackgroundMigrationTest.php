<?php
/**
 * 迁移 20261010_home_cta_double_background：只去掉与里面 CTA 同一张图的外层背景，站长自己设的不动，可重复跑。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

class HomeCtaDoubleBackgroundMigrationTest extends TestCase
{
    private const CTA_IMAGE = '/themes/default/assets/images/cta/cta-smart-manufacturing.webp';

    /** @var array<string,mixed> */
    private array $mig;

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', '
            . '"key" TEXT, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', '
            . 'options TEXT, sort_order INTEGER DEFAULT 0, UNIQUE("key"))',   // 与线上表一致：迁移后清缓存会 upsert 设置
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->mig = require ROOT_PATH . '/migrations/20261010_home_cta_double_background.php';
    }

    /** @return array<string,mixed> */
    private static function doc(string $sectionImage, string $ctaImage): array
    {
        $settings = ['padding' => 'none'];
        if ($sectionImage !== '') {
            $settings += ['container_bg_image' => $sectionImage, 'container_bg_overlay_color' => '#000000', 'container_bg_overlay_opacity' => 45];
        }
        return ['schema' => 1, 'sections' => [[
            'id' => 'home_s_6', 'type' => 'section', 'settings' => $settings,
            'columns' => [['id' => 'c', 'elements' => [['id' => 'e', 'type' => 'home-block',
                'data' => ['block_type' => 'cta', 'bg_image' => $ctaImage, 'bg_color' => '#172554', 'bg_opacity' => 60]]]]],
        ]]];
    }

    private function seed(string $key, array $doc): void
    {
        db()->execute('INSERT INTO settings ("key", value) VALUES (?, ?)', [$key, json_encode($doc, JSON_UNESCAPED_SLASHES)]);
    }

    /** @return array<string,mixed> */
    private function section(string $key): array
    {
        $row = db()->fetchOne('SELECT value FROM settings WHERE "key" = ?', [$key]);
        return json_decode((string) $row['value'], true)['sections'][0];
    }

    public function testRemovesOnlyTheDuplicatedOuterBackground(): void
    {
        $this->seed('home_blox_published', self::doc(self::CTA_IMAGE, self::CTA_IMAGE));
        $this->seed('home_blox_data_en', self::doc(self::CTA_IMAGE, self::CTA_IMAGE));
        $this->seed('home_blox_data', self::doc('/uploads/2026/10/brand.jpg', self::CTA_IMAGE));   // 站长换过的
        $this->seed('homepage_title', ['sections' => [self::doc(self::CTA_IMAGE, self::CTA_IMAGE)['sections'][0]]]); // 不是 home_blox_*

        self::assertFalse(($this->mig['check'])());
        self::assertSame('fixed 2', ($this->mig['php'])());
        self::assertTrue(($this->mig['check'])(), '再跑一遍无事可做');

        foreach (['home_blox_published', 'home_blox_data_en'] as $key) {
            $section = $this->section($key);
            self::assertArrayNotHasKey('container_bg_image', $section['settings'], $key);
            self::assertArrayNotHasKey('container_bg_overlay_opacity', $section['settings'], $key);
            self::assertSame('none', $section['settings']['padding'], '其余设置不动');
            self::assertSame(self::CTA_IMAGE, $section['columns'][0]['elements'][0]['data']['bg_image'], 'CTA 自己的背景保留');
        }
        self::assertSame('/uploads/2026/10/brand.jpg', $this->section('home_blox_data')['settings']['container_bg_image'], '不同的图不动');
        self::assertSame(self::CTA_IMAGE, $this->section('homepage_title')['settings']['container_bg_image'], '只处理 home_blox_* 设置');
    }

    public function testNothingToDoOnFreshInstallData(): void
    {
        $this->seed('home_blox_published', self::doc('', self::CTA_IMAGE));
        self::assertTrue(($this->mig['check'])());
    }
}
