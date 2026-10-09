<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 随包内置字体（includes/font_presets.php 的 bundledFonts）与页尾代码复制脚本的按需输出。
 *
 * 渲染相关用例跑在独立进程里：要在加载前桩掉 configRawLang / __，并按需定义 SITE_BASE_PATH。
 */
final class BundledFontsTest extends TestCase
{
    public function testRegistryMatchesGeneratedManifest(): void
    {
        require_once ROOT_PATH . '/includes/font_presets.php';

        foreach (bundledFonts() as $family => $font) {
            $dir = ROOT_PATH . $font['dir'];
            self::assertFileExists($dir . '/OFL.txt', "{$family} 必须随包附带许可");
            $manifest = json_decode((string) file_get_contents($dir . '/manifest.json'), true, 16, JSON_THROW_ON_ERROR);
            self::assertSame($family, $manifest['family']);
            self::assertSame(array_keys($manifest['files']), array_keys($font['files']), '分片集合与生成脚本一致');
            self::assertArrayHasKey($font['preload'], $font['files']);
            foreach ($font['files'] as $slice => $item) {
                $entry = $manifest['files'][$slice];
                self::assertSame($entry['file'], $item['file']);
                self::assertSame($entry['range'], $item['range'], "{$family}/{$slice} 的 unicode-range 与生成脚本不一致");
                self::assertSame($entry['sha256'], hash_file('sha256', $dir . '/' . $item['file']), "{$item['file']} 与 manifest 不符");
            }
        }
    }

    public function testOnlyRegisteredFamiliesInAStackAreDetected(): void
    {
        require_once ROOT_PATH . '/includes/font_presets.php';

        self::assertSame(['Inter'], bundledFontsInStacks('Inter,"Helvetica Neue",Arial,sans-serif'));
        self::assertSame(['Inter'], bundledFontsInStacks(' "inter" , serif', "'Inter'"), '大小写、引号、重复都按同一族');
        self::assertSame([], bundledFontsInStacks('"Inter Tight",Helvetica,sans-serif', ''), '前缀相同的别的族不算');
        self::assertSame([], bundledFontsInStacks('system-ui,-apple-system,"Segoe UI",Roboto,sans-serif'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSystemPresetsKeepOutputUnchangedAndGroteskLoadsInter(): void
    {
        self::bootFontRenderer('en');

        $GLOBALS['yk_test_cfg'] = [];
        self::assertSame('', renderFontStyles(), '未配置字体的站点不得多输出任何内容');

        $GLOBALS['yk_test_cfg'] = ['font_preset' => 'system'];
        $system = renderFontStyles();
        self::assertStringNotContainsString('@font-face', $system);
        self::assertStringNotContainsString('rel="preload"', $system);
        self::assertStringStartsWith('<style id="yk-fonts">', $system, '系统字体预设的输出形态不变');

        $GLOBALS['yk_test_cfg'] = ['font_preset' => 'grotesk'];
        $grotesk = renderFontStyles();
        self::assertSame(count(bundledFonts()['Inter']['files']), substr_count($grotesk, '@font-face{font-family:"Inter"'));
        self::assertStringContainsString('font-weight:100 900', $grotesk);
        self::assertStringContainsString('unicode-range:U+0000-00FF', $grotesk);
        self::assertSame(1, substr_count($grotesk, 'rel="preload"'), '只预加载 latin 一片');
        self::assertStringContainsString('href="/assets/fonts/inter/inter-latin.woff2"', $grotesk);
        self::assertStringContainsString('--yk-font-heading:Inter,', $grotesk);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testSubdirectoryInstallPrefixesStyleUrlsButNotPreloadAttribute(): void
    {
        define('SITE_BASE_PATH', '/sub');
        require_once ROOT_PATH . '/includes/BasePath.php';
        self::bootFontRenderer('en');

        $GLOBALS['yk_test_cfg'] = ['font_preset' => 'grotesk'];
        $html = renderFontStyles();
        // <style> 里的 url() 出口改写够不着，必须在生成处补前缀
        self::assertStringContainsString('url("/sub/assets/fonts/inter/inter-latin.woff2")', $html);
        // 属性由 BasePath 出口统一补，这里再补就会变成 /sub/sub/
        self::assertStringContainsString('href="/assets/fonts/inter/inter-latin.woff2"', $html);
        $rewritten = BasePath::rewriteHtml($html, '/sub');
        self::assertStringContainsString('href="/sub/assets/fonts/inter/inter-latin.woff2"', $rewritten);
        self::assertStringNotContainsString('/sub/sub/', $rewritten);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testCodeCopyScriptOnlyFollowsRenderedCodeBlocksAndHidesVersion(): void
    {
        // __() 与 e() 由 tests/bootstrap.php 提供
        eval('function assetVer(string $p): string { return $p . "?v=123"; }');
        eval(self::functionSource('codeBlockSeen'));
        eval(self::functionSource('renderCodeCopy'));

        ob_start();
        renderCodeCopy();
        self::assertSame('', ob_get_clean(), '没有代码块的页面页尾不挂脚本');

        codeBlockSeen('<p>寿司培训</p>');
        ob_start();
        renderCodeCopy();
        self::assertSame('', ob_get_clean());

        codeBlockSeen('<pre class="language-php"><code>echo 1;</code></pre>');
        ob_start();
        renderCodeCopy();
        $out = (string) ob_get_clean();
        self::assertStringContainsString('src="/assets/js/code-copy.js?v=123"', $out);
        self::assertStringContainsString('__ykCodeCopyI18n', $out);
        self::assertStringNotContainsString('CMS_VERSION', self::functionSource('renderCodeCopy'), '前台不公开版本号');
    }

    public function testEveryRichTextExitFeedsTheCodeBlockFlag(): void
    {
        foreach (['renderContent', 'parseShortcodes'] as $fn) {
            self::assertStringContainsString('codeBlockSeen(', self::functionSource($fn), "{$fn}() 必须登记代码块");
        }
        foreach (['TextElement', 'ProductFieldElement'] as $class) {
            $source = (string) file_get_contents(ROOT_PATH . "/includes/builder/elements/{$class}.php");
            self::assertStringContainsString('codeBlockSeen($html)', $source, "{$class} 必须登记代码块");
        }
    }

    private static function bootFontRenderer(string $lang): void
    {
        define('SITE_LANG', $lang);
        eval('function configRawLang(string $k, string $d = ""): string { return (string) ($GLOBALS["yk_test_cfg"][$k] ?? $d); }');
        require_once ROOT_PATH . '/includes/font_presets.php';
    }

    private static function functionSource(string $name): string
    {
        $src = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertSame(1, preg_match('/^function ' . $name . '\(.*?^\}$/ms', $src, $m), "找不到 {$name}()");
        return $m[0];
    }
}
