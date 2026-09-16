<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/blox-pro/access.php';

final class BloxProAccessTest extends TestCase
{
    public function testMinimumVersionAndUnknownVersion(): void
    {
        foreach (['', 'unknown', '1.19.9', '1.20.0-rc1', '1.20.0-beta.1'] as $version) {
            self::assertFalse(BloxProAccess::supports($version), $version);
        }
        foreach (['1.20.0', '1.20.1', '1.21.0', '2.0.0'] as $version) {
            self::assertTrue(BloxProAccess::supports($version), $version);
        }
    }

    public function testOnlyKnownAuthoringFeaturesAreExposed(): void
    {
        self::assertSame(['query_loop', 'display_conditions', 'style_presets'], BloxProAccess::FEATURES);
        self::assertFalse(BloxProAccess::allows('unknown'));
        self::assertFalse(BloxProAccess::allows('product-detail'));
        self::assertFalse(BloxProAccess::allows('theme_download'));
    }

    public function testPackageIsProOnlyAndMetadataIsLocalized(): void
    {
        $meta = json_decode((string) file_get_contents(ROOT_PATH . '/plugins/blox-pro/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.20.0', $meta['requires_cms']);
        self::assertSame('8.0', $meta['requires_php']);
        self::assertSame('blox', $meta['module']);
        self::assertSame('pro', $meta['tier']);
        foreach (['name', 'description', 'name_en', 'description_en', 'name_ja', 'description_ja'] as $key) {
            self::assertNotSame('', $meta[$key]);
        }
        // 免费期（三项能力均为 free）随完整包分发并默认启用；切换 licensed 前须改回 pro 受控下载。
        $manifest = json_decode((string) file_get_contents(ROOT_PATH . '/config/blox-assets.json'), true, 512, JSON_THROW_ON_ERROR);
        $policy = require ROOT_PATH . '/config/blox-feature-policy.php';
        $licensed = in_array('licensed', array_values($policy), true);
        self::assertSame($licensed, in_array('plugins/blox-pro', $manifest['pro'], true));
        self::assertSame(!$licensed, in_array('plugins/blox-pro', $manifest['core'], true));
        self::assertNotContains('plugins/blox-pro', $manifest['runtime']);
    }
}
