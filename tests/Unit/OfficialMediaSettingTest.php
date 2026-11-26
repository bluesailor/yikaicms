<?php
/**
 * 媒体库设置「显示官方素材」（2.3.1）：缺省开启；关闭后两个选图弹窗不显示官方页签、不请求官方目录，
 * 浏览与导入接口在发出远程请求之前就拒绝。本站上传、选图、已导入的素材不受影响。
 */
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class OfficialMediaSettingTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/RemoteOfficialMedia.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['yikai_config_runtime_overrides'][RemoteOfficialMedia::SETTING_KEY]);
        parent::tearDown();
    }

    private static function set(?string $value): void
    {
        if ($value === null) {
            unset($GLOBALS['yikai_config_runtime_overrides'][RemoteOfficialMedia::SETTING_KEY]);
            return;
        }
        $GLOBALS['yikai_config_runtime_overrides'][RemoteOfficialMedia::SETTING_KEY] = $value;
    }

    public function testDefaultsToOnAndOnlyZeroTurnsItOff(): void
    {
        self::set(null);
        self::assertTrue(RemoteOfficialMedia::enabled());
        self::set('1');
        self::assertTrue(RemoteOfficialMedia::enabled());
        self::set('0');
        self::assertFalse(RemoteOfficialMedia::enabled());
    }

    public function testServiceRefusesBrowseAndImportBeforeAnyRemoteRequestWhenOff(): void
    {
        self::set('0');
        foreach ([
            static fn () => RemoteOfficialMedia::list(['page' => 1]),
            static fn () => RemoteOfficialMedia::import('asset-1', 1),
        ] as $call) {
            try {
                $call();
                self::fail('official media call ran while the setting is off');
            } catch (RuntimeException $e) {
                self::assertSame(__('official_media_disabled'), $e->getMessage());
            }
        }
    }

    public function testEndpointsAndBothPickersHonourTheSetting(): void
    {
        $api = (string) file_get_contents(ROOT_PATH . '/admin/media_api.php');
        // 浏览与导入都在调用服务（远程请求）之前判断设置
        foreach (['remote_list' => 'RemoteOfficialMedia::list(', 'remote_import' => 'RemoteOfficialMedia::import('] as $action => $call) {
            $start = strpos($api, "\$action === '" . $action . "'");
            self::assertNotFalse($start, $action);
            $guard = strpos($api, 'RemoteOfficialMedia::enabled()', $start);
            self::assertNotFalse($guard, $action);
            self::assertLessThan(strpos($api, $call, $start), $guard, $action . ' must check the setting first');
        }

        $footer = (string) file_get_contents(ROOT_PATH . '/admin/includes/footer.php');
        self::assertStringContainsString("var _mpOfficialEnabled = <?php echo \$_mpOfficialOn ? 'true' : 'false'; ?>;", $footer);
        self::assertStringContainsString("options.source === 'official' && _mpType === 'image' && _mpOfficialEnabled ? 'official' : 'local'", $footer);
        self::assertStringContainsString("var showOfficial = _mpType === 'image' && _mpOfficialEnabled;", $footer);
        self::assertStringContainsString("if (_mpSource === 'official' && _mpOfficialEnabled) {", $footer);

        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        self::assertStringContainsString("officialMediaEnabled: <?php require_once ROOT_PATH . '/includes/RemoteOfficialMedia.php'; echo RemoteOfficialMedia::enabled() ? 'true' : 'false'; ?>,", $editor);
        $overlay = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/overlays.php');
        self::assertStringContainsString("x-show=\"mediaType === 'image' && officialMediaEnabled\"", $overlay);
        $methods = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/media-editing-methods.php');
        self::assertStringContainsString('options.source === "official" && this.mediaType === "image" && this.officialMediaEnabled ? "official" : "local"', $methods);
        self::assertStringContainsString('var request = this.mediaSource === "official" && this.officialMediaEnabled', $methods);
    }

    public function testOnlySiteAdministratorsCanChangeTheSetting(): void
    {
        $media = (string) file_get_contents(ROOT_PATH . '/admin/media.php');
        $start = strpos($media, "if (\$action === 'settings') {");
        self::assertNotFalse($start);
        $deny = strpos($media, "if (!hasPermission('*')) {", $start);
        $write = strpos($media, 'settingModel()->set(RemoteOfficialMedia::SETTING_KEY', $start);
        self::assertNotFalse($deny);
        self::assertNotFalse($write);
        self::assertLessThan($write, $deny);
        self::assertStringContainsString("<?php if (!\$selectMode && hasPermission('*')): ?>", $media);
    }
}
