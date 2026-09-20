<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/plugins/yikai-builder/access.php';

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
        self::assertSame(['query_loop', 'display_conditions', 'style_presets', 'table', 'pricing', 'global_classes'], BloxProAccess::FEATURES);
        self::assertFalse(BloxProAccess::allows('unknown'));
        self::assertFalse(BloxProAccess::allows('product-detail'));
        self::assertFalse(BloxProAccess::allows('theme_download'));
    }

    public function testPackageIsProOnlyAndMetadataIsLocalized(): void
    {
        $meta = json_decode((string) file_get_contents(ROOT_PATH . '/plugins/yikai-builder/plugin.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1.20.0', $meta['requires_cms']);
        self::assertSame('8.0', $meta['requires_php']);
        self::assertSame('blox', $meta['module']);
        self::assertSame('pro', $meta['tier']);
        foreach (['name', 'description', 'name_en', 'description_en', 'name_ja', 'description_ja'] as $key) {
            self::assertNotSame('', $meta[$key]);
        }
        // 任一能力为 licensed 时插件必须在 pro 清单（受控下载、不随完整包）；全部 free 时才随完整包（core）分发。
        $manifest = json_decode((string) file_get_contents(ROOT_PATH . '/config/blox-assets.json'), true, 512, JSON_THROW_ON_ERROR);
        $policy = require ROOT_PATH . '/config/blox-feature-policy.php';
        $licensed = in_array('licensed', array_values($policy), true);
        self::assertSame($licensed, in_array('plugins/yikai-builder', $manifest['pro'], true));
        self::assertSame(!$licensed, in_array('plugins/yikai-builder', $manifest['core'], true));
        self::assertNotContains('plugins/yikai-builder', $manifest['runtime']);
    }
}
