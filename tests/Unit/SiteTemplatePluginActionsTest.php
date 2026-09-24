<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PluginMarketInstall;
use SiteTemplateService;

require_once ROOT_PATH . '/config/version.php';
require_once ROOT_PATH . '/includes/SiteTemplateService.php';

/**
 * 整站模板「一并安装所需插件」：每个缺失插件先判定怎么补——本站已有且版本够就启用，
 * 官方插件市场有可下载的合格版本就安装，否则只能手动；市场不可达不影响预览。
 */
final class SiteTemplatePluginActionsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/yk-st-plugins-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/plugins/shop', 0777, true);
        file_put_contents($this->root . '/plugins/shop/plugin.json', json_encode(['name' => 'Shop', 'version' => '0.8.2']));
        mkdir($this->root . '/plugins/old-plugin', 0777, true);
        file_put_contents($this->root . '/plugins/old-plugin/plugin.json', json_encode(['name' => 'Old', 'version' => '1.0.0']));
    }

    protected function tearDown(): void
    {
        foreach (['shop', 'old-plugin'] as $slug) {
            @unlink($this->root . '/plugins/' . $slug . '/plugin.json');
            @rmdir($this->root . '/plugins/' . $slug);
        }
        @rmdir($this->root . '/plugins');
        @rmdir($this->root);
    }

    /** @param null|list<array<string,mixed>> $plugins null = 市场不可达 */
    private function service(?array $plugins, int &$calls = 0): SiteTemplateService
    {
        $market = new PluginMarketInstall($this->root, static function (string $url) use ($plugins, &$calls): array {
            $calls++;
            $body = $plugins === null ? null : json_encode(['code' => 0, 'data' => ['protocol_version' => 2, 'plugins' => $plugins]]);
            return ['body' => $body, 'status' => $plugins === null ? 0 : 200, 'too_large' => false];
        });
        return new SiteTemplateService($this->root, $market);
    }

    private function item(string $slug, string $version, array $extra = []): array
    {
        return $extra + ['slug' => $slug, 'name' => $slug, 'version' => $version, 'source' => 'official',
            'download_url' => 'https://update.yikaicms.com/packages/plugins/' . $slug . '-v' . $version . '.zip',
            'hash' => 'sha256:' . str_repeat('a', 64), 'sig' => 'x'];
    }

    public function testEachMissingPluginGetsTheCheapestWayToSatisfyIt(): void
    {
        $calls = 0;
        $service = $this->service([
            $this->item('stay-inquiry', '1.0.0'),
            $this->item('old-plugin', '1.2.0'),
            $this->item('too-old', '0.9.0'),
            $this->item('paid-one', '2.0.0', ['paid' => true, 'download_url' => '']),
            $this->item('locked', '1.0.0', ['locked_reason' => 'cms_version_required']),
        ], $calls);
        $actions = array_column($service->pluginActions([
            ['slug' => 'shop', 'version' => '0.8.2'],
            ['slug' => 'stay-inquiry', 'version' => '1.0.0'],
            ['slug' => 'old-plugin', 'version' => '1.1.0'],
            ['slug' => 'too-old', 'version' => '1.0.0'],
            ['slug' => 'paid-one', 'version' => '1.0.0'],
            ['slug' => 'locked', 'version' => '1.0.0'],
            ['slug' => 'nowhere', 'version' => '1.0.0'],
        ]), null, 'slug');

        self::assertSame('enable', $actions['shop']['action'], '本站已有同版本：只需启用，不下载');
        self::assertSame('0.8.2', $actions['shop']['available']);
        self::assertSame('market', $actions['stay-inquiry']['action']);
        self::assertSame('market', $actions['old-plugin']['action'], '本地版本太低、市场有新版：从市场升级');
        self::assertSame('1.2.0', $actions['old-plugin']['available']);
        self::assertSame('manual', $actions['too-old']['action'], '市场版本低于模板要求');
        self::assertSame('manual', $actions['paid-one']['action'], '付费未授权拿不到下载地址');
        self::assertSame('manual', $actions['locked']['action']);
        self::assertSame('manual', $actions['nowhere']['action']);
        self::assertSame(1, $calls, '一次预览只拉一次市场目录');
    }

    public function testNothingMissingMeansNoMarketRequestAndAnUnreachableMarketOnlyDowngradesToManual(): void
    {
        $calls = 0;
        self::assertSame([], $this->service([], $calls)->pluginActions([]));
        self::assertSame(0, $calls);

        $actions = array_column($this->service(null)->pluginActions([
            ['slug' => 'shop', 'version' => '0.8.2'],
            ['slug' => 'stay-inquiry', 'version' => '1.0.0'],
        ]), null, 'slug');
        self::assertSame('enable', $actions['shop']['action']);
        self::assertSame('manual', $actions['stay-inquiry']['action']);
    }

    /** 主题声明的依赖插件交给导入流程处理（可一键安装），检查模板包时不因「本站还没装」就判主题不完整 */
    public function testThemePluginRequirementIsLeftToTheImportFlow(): void
    {
        $meta = ['schema_version' => 1, 'name' => 'Stay', 'version' => '2.0.0', 'author' => 'Yikai',
            'requires_cms' => '>=1.20.1', 'requires_php' => '>=8.0.0', 'category' => 'services',
            'required_plugins' => ['stay-inquiry', '', 'stay-inquiry', 7]];
        self::assertSame(['stay-inquiry'], \SiteTemplateArchive::themeRequiredPlugins($meta));
        self::assertSame([], \ThemeValidator::validateMeta($meta, 'yikai-minsu', false)['errors']);
        self::assertSame([], \SiteTemplateArchive::themeRequiredPlugins(['name' => 'Plain']));
    }

    public function testMarketInstallRejectsUnlistedAndUnsafeEntriesBeforeDownloading(): void
    {
        $downloads = 0;
        $market = new PluginMarketInstall($this->root, function (string $url) use (&$downloads): array {
            if (!str_contains($url, '/api/plugins/list.php')) $downloads++;
            return ['body' => json_encode(['code' => 0, 'data' => ['protocol_version' => 2, 'plugins' => [
                $this->item('evil', '1.0.0', ['download_url' => 'https://evil.test/evil-v1.0.0.zip']),
                $this->item('shop', '0.8.0'),
            ]]]), 'status' => 200, 'too_large' => false];
        });
        self::assertFalse($market->install('missing')['ok']);
        self::assertFalse($market->install('evil')['ok'], '非官方下载地址');
        self::assertFalse($market->install('shop')['ok'], '不把本地 0.8.2 降级成 0.8.0');
        self::assertFalse($market->install('../x')['ok']);
        self::assertSame(0, $downloads, '以上都在下载之前拒绝');
    }
}
