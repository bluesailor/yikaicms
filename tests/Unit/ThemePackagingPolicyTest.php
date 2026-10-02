<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}

/**
 * 安装包里带什么、不带什么（2.0.4 瘦身与拆市场）。
 * 拆出去的东西只从打包排除、仍留在 git 里：增量包不会把存量站已有的文件当废弃文件删掉。
 */
final class ThemePackagingPolicyTest extends TestCase
{
    private function script(): string
    {
        $script = file_get_contents(ROOT_PATH . '/build.sh');
        self::assertIsString($script);
        return $script;
    }

    /** @return list<string> */
    private function excludes(): array
    {
        self::assertSame(1, preg_match('/^EXCLUDES=\((.*?)^\)$/ms', $this->script(), $m));
        preg_match_all('/^\s*"([^"]+)"\s*$/m', $m[1], $items);
        return $items[1];
    }

    public function testBuildExcludesMarketplaceSources(): void
    {
        self::assertContains('marketplace', $this->excludes());
    }

    /** 2.0.4 起只带 Default 模板：Business、Minimal 走主题市场 */
    public function testFullPackageNoLongerStagesMarketThemes(): void
    {
        $script = $this->script();
        self::assertStringNotContainsString('BUNDLED_THEMES', $script);
        self::assertStringNotContainsString('$PKG_DIR/themes/$theme', $script);
    }

    /** 商城与繁体语言包只在插件市场提供；核心的繁体转换表同样不随包 */
    public function testMarketOnlyPluginsAndTraditionalChineseTableAreExcluded(): void
    {
        $excludes = $this->excludes();
        foreach (['plugins/shop', 'plugins/zh-tw', 'includes/i18n/s2t_maps.php'] as $path) {
            self::assertContains($path, $excludes, "{$path} 应只在市场提供");
        }
        // 留在 git 里：存量站升级时不会被当作删除文件
        self::assertFileExists(ROOT_PATH . '/includes/i18n/s2t_maps.php');
        self::assertFileExists(ROOT_PATH . '/plugins/shop/plugin.json');
    }

    /** 语言包插件里的表必须与生成脚本的产物一致（tools/build-s2t-map.sh 只写核心那份） */
    public function testTraditionalChinesePackCarriesTheSameTable(): void
    {
        self::assertFileEquals(
            ROOT_PATH . '/includes/i18n/s2t_maps.php',
            ROOT_PATH . '/plugins/zh-tw/s2t_maps.php',
            '重新生成转换表后要同步复制到 plugins/zh-tw/'
        );
        $meta = json_decode((string) file_get_contents(ROOT_PATH . '/plugins/zh-tw/plugin.json'), true);
        self::assertIsArray($meta);
        foreach (['name', 'name_en', 'name_ja', 'version', 'description', 'description_en', 'description_ja'] as $key) {
            self::assertNotSame('', (string) ($meta[$key] ?? ''), "plugin.json 缺 {$key}");
        }
    }

    public function testS2TFindsTheTableInCoreOrPack(): void
    {
        require_once ROOT_PATH . '/includes/i18n/S2T.php';
        self::assertSame('zh-tw', S2T::PACK_PLUGIN);
        $source = (string) file_get_contents(ROOT_PATH . '/includes/i18n/S2T.php');
        self::assertStringContainsString("__DIR__ . '/s2t_maps.php'", $source);
        self::assertStringContainsString("'/plugins/' . self::PACK_PLUGIN . '/s2t_maps.php'", $source);
        self::assertTrue(S2T::available());
        self::assertSame('臺灣', S2T::text('台湾'));
    }

    public function testDeltaUpdatesNeverDeleteInstalledThemes(): void
    {
        self::assertSame(
            2,
            substr_count($this->script(), 'config/config.php|storage/*|uploads/*|install/*|themes/*'),
            'Both deleted-file branches must protect market themes installed on the site.'
        );
    }
}
