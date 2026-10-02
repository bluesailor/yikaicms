<?php
/**
 * 随包富文本编辑器的依赖契约（2026-09-18 复审 R03）。
 *
 * TinyMCE 6.8.5 命中官方安全公告 CVE-2026-47759 / CVE-2026-47761（影响 6.0.0–6.8.6），
 * 6.x 的开源维护已结束，官方修复只在 GPLv2+ 的 7.x / 8.x——与本软件的闭源许可冲突。
 * v1.20.0 起改用 HugeRTE：TinyMCE 在改 GPL 之前那个 MIT 提交处的分支，1.0.11 起修复
 * 上述两个 CVE。本测试守住：版本不低于修复版、许可是 MIT、TinyMCE 不会回来。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class RichEditorDependencyContractTest extends TestCase
{
    /** 首个修复 CVE-2026-47759 / 47761 的 HugeRTE 版本（GHSA-v9j8-qc36-jqgq）。 */
    private const FIRST_PATCHED = '1.0.11';

    private function source(string $relative): string
    {
        $path = ROOT_PATH . '/' . $relative;
        self::assertFileExists($path);
        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    public function testBundledHugeRteIsPatchedAndMitLicensed(): void
    {
        $bundle = $this->source('assets/hugerte/hugerte.min.js');
        self::assertSame(1, preg_match('/HugeRTE version (\d+\.\d+\.\d+)/', $bundle, $m), '找不到 HugeRTE 版本声明');
        self::assertTrue(
            version_compare($m[1], self::FIRST_PATCHED, '>='),
            "随包 HugeRTE {$m[1]} 低于修复版本 " . self::FIRST_PATCHED . '，仍受 CVE-2026-47759 / 47761 影响'
        );
        self::assertStringContainsString('Licensed under the MIT license', $bundle);

        $license = $this->source('assets/hugerte/license.txt');
        self::assertStringStartsWith('MIT License', ltrim($license));

        // 清单写的版本要和随包的一致
        self::assertStringContainsString('| ' . $m[1] . ' | MIT |', $this->source('THIRD-PARTY-NOTICES.md'));
    }

    /** TinyMCE（6.x 已无补丁；7/8 是 GPL）不得以任何形式回到包里。 */
    public function testTinyMceIsGoneForGood(): void
    {
        self::assertDirectoryDoesNotExist(ROOT_PATH . '/assets/tinymce');

        foreach (['admin/includes/footer.php', 'admin/blox_editor.php'] as $page) {
            $source = $this->source($page);
            self::assertStringNotContainsString('/assets/tinymce/', $source, "{$page} 还在加载 TinyMCE");
            self::assertStringContainsString('<script src="/assets/hugerte/hugerte.min.js"></script>', $source);
            self::assertStringContainsString('<script src="/assets/js/rich-editor.js', $source, "{$page} 缺少共用的别名/语言表");
            // 胶水脚本必须排在编辑器本体之后，别名才拿得到 window.hugerte
            self::assertLessThan(
                (int) strpos($source, '<script src="/assets/js/rich-editor.js'),
                (int) strpos($source, '/assets/hugerte/hugerte.min.js'),
                "{$page} 里 rich-editor.js 排到了编辑器本体前面"
            );
        }
    }

    /** 本仓代码直接用 hugerte；window.tinymce 只作为给第三方插件的别名存在。 */
    public function testOwnCodeUsesTheHugeRteGlobal(): void
    {
        foreach ([
            'admin/includes/footer.php',
            'admin/includes/ai_panel_js.php',
            'admin/article_edit.php',
            'admin/blox_editor.php',
            'admin/blox_editor/partials/media-editing-methods.php',
            'assets/js/blox-compact-richtext.js',
        ] as $file) {
            $source = $this->source($file);
            self::assertDoesNotMatchRegularExpression(
                '/(?<![\w.-])tinymce\.(init|get|activeEditor|triggerSave)\b/',
                $source,
                "{$file} 还在直接调 tinymce.*"
            );
        }

        $glue = $this->source('assets/js/rich-editor.js');
        self::assertStringContainsString('global.tinymce = global.hugerte;', $glue);
    }

    /** 语言包改挂到 hugerte 上，否则加载时报 tinymce is not defined（别名不一定先就位）。 */
    public function testLanguagePacksRegisterOnHugeRte(): void
    {
        foreach (['zh_CN', 'ja'] as $pack) {
            $source = $this->source('assets/hugerte/langs/' . $pack . '.js');
            self::assertStringStartsWith('hugerte.addI18n("' . $pack . '"', ltrim($source));
        }
    }

    /** 两个编辑器入口用到的插件都得随包，缺一个编辑器就起不来。 */
    public function testEveryConfiguredPluginShips(): void
    {
        $sources = $this->editorInitSources();
        preg_match_all("/plugins:\s*[\"']([a-z ]+)[\"']/", $sources, $m);
        self::assertNotEmpty($m[1], '没解析到插件清单');
        $plugins = array_unique(array_merge(...array_map(
            static fn (string $list): array => preg_split('/\s+/', trim($list)) ?: [],
            $m[1]
        )));
        // 发行包按 build.sh 的 HUGERTE_PLUGINS 裁剪插件目录
        self::assertSame(1, preg_match('/^HUGERTE_PLUGINS=\(([a-z ]+)\)$/m', $this->source('build.sh'), $bundled));
        $bundled = preg_split('/\s+/', trim($bundled[1])) ?: [];
        foreach ($plugins as $plugin) {
            self::assertFileExists(
                ROOT_PATH . '/assets/hugerte/plugins/' . $plugin . '/plugin.min.js',
                "编辑器配置用到的插件 {$plugin} 没随包"
            );
            self::assertContains($plugin, $bundled, "插件 {$plugin} 不在 build.sh 的 HUGERTE_PLUGINS 里，发行包会裁掉它");
        }
    }

    /** 发行包只留默认皮肤（oxide / default）：哪个编辑器改了 skin 或 content_css，build.sh 要同步 */
    public function testEditorsUseTheBundledDefaultSkin(): void
    {
        $sources = $this->editorInitSources();
        self::assertDoesNotMatchRegularExpression('/\bskin(_url)?\s*:/', $sources);
        self::assertDoesNotMatchRegularExpression('/\bcontent_css\s*:\s*["\']/', $sources);
        $build = $this->source('build.sh');
        self::assertStringContainsString('! -name oxide', $build);
        self::assertStringContainsString('! -name default', $build);
    }

    /** 所有调用 hugerte.init 的入口（testEveryHugeRteInitIsListed 守住清单完整） */
    private function editorInitSources(): string
    {
        return implode("\n", array_map(fn (string $path): string => $this->source($path), self::INIT_SOURCES));
    }

    private const INIT_SOURCES = [
        'admin/blox_editor/partials/media-editing-methods.php',
        'admin/includes/footer.php',
        'assets/js/blox-compact-richtext.js',
        'assets/js/footer-content-editor.js',
    ];

    public function testEveryHugeRteInitIsListed(): void
    {
        $found = [];
        foreach (['admin', 'assets/js', 'includes', 'plugins', 'themes', 'marketplace'] as $dir) {
            if (!is_dir(ROOT_PATH . '/' . $dir)) continue;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT_PATH . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $path = str_replace('\\', '/', substr((string) $file, strlen(ROOT_PATH) + 1));
                if (!preg_match('/\.(php|js)$/', $path) || str_contains($path, '.min.')) {
                    continue;
                }
                if (preg_match('/\b(hugerte|tiny)\.init\(/', (string) file_get_contents((string) $file))) {
                    $found[] = $path;
                }
            }
        }
        sort($found);
        self::assertSame(self::INIT_SOURCES, $found, '新的 hugerte.init 入口：加进 INIT_SOURCES，并核对 build.sh 的 HUGERTE_PLUGINS');
    }
}
