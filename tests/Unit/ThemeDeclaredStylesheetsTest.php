<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 主题展示样式表（2.0.3，英文模板反馈 #2）：单独编辑页头 / 页尾时画布缺主题样式。
 * - theme.json 可声明 stylesheets（相对 assets/ 的 .css），前台默认页头与所有编辑画布同源加载；
 * - 未声明的老主题：画布补渲染一次主题页头来收集样式表（整页画布早已这样做）。
 */
final class ThemeDeclaredStylesheetsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/ThemeValidator.php';
    }

    public function testStylesheetPathsStayInsideTheThemeAssets(): void
    {
        foreach (['css/theme.css', 'theme.css', 'css/brand-2.0.css', 'fonts/icons_v1.css'] as $ok) {
            self::assertTrue(ThemeValidator::stylesheetPath($ok), $ok);
        }
        foreach (['../x.css', 'css/../../x.css', '/assets/css/x.css', 'https://cdn.test/x.css', '//cdn.test/x.css',
            'css/theme.php', 'css/theme.css?v=1', 'css\\theme.css', '', 'css/.css'] as $bad) {
            self::assertFalse(ThemeValidator::stylesheetPath($bad), $bad);
        }
    }

    public function testMetaValidationRejectsBadDeclarations(): void
    {
        $base = ['schema_version' => 1, 'name' => 'T', 'version' => '1.0.0', 'author' => 'A', 'category' => 'general',
            'name_en' => 'T', 'name_ja' => 'T', 'description' => 'd', 'description_en' => 'd', 'description_ja' => 'd'];
        self::assertSame([], array_values(array_filter(ThemeValidator::validateMeta($base + ['stylesheets' => ['css/theme.css']], 'demo')['errors'],
            static fn (string $e): bool => str_contains($e, 'stylesheets'))));
        foreach ([['stylesheets' => 'css/theme.css'], ['stylesheets' => ['../evil.css']], ['stylesheets' => ['a' => 'css/x.css']],
            ['stylesheets' => array_fill(0, 11, 'css/x.css')]] as $bad) {
            $errors = ThemeValidator::validateMeta($base + $bad, 'demo')['errors'];
            self::assertNotEmpty(array_filter($errors, static fn (string $e): bool => str_contains($e, 'stylesheets')), json_encode($bad));
        }
    }

    public function testDirectoryValidationRequiresTheDeclaredFile(): void
    {
        $dir = sys_get_temp_dir() . '/yk-theme-' . bin2hex(random_bytes(4));
        mkdir($dir . '/assets/css', 0777, true);
        mkdir($dir . '/layouts', 0777, true);
        try {
            file_put_contents($dir . '/theme.json', json_encode(['schema_version' => 1, 'name' => 'T', 'version' => '1.0.0', 'author' => 'A',
                'stylesheets' => ['css/theme.css', 'css/missing.css']]));
            file_put_contents($dir . '/assets/css/theme.css', 'body{}');
            $errors = ThemeValidator::validateDir($dir, 'demo')['errors'];
            self::assertContains('stylesheets 指向的文件不存在：assets/css/missing.css', $errors);
            self::assertNotContains('stylesheets 指向的文件不存在：assets/css/theme.css', $errors);
        } finally {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($dir);
        }
    }

    public function testFrontHeaderAndEveryCanvasLoadTheSameDeclaredList(): void
    {
        $header = (string) file_get_contents(ROOT_PATH . '/themes/default/layouts/header.php');
        self::assertStringContainsString("themeDeclaredStylesheetTags((string) (\$extraCss ?? ''))", $header, 'deduped against manual $extraCss links');
        $canvas = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxCanvasPreview.php');
        self::assertStringContainsString("if (!array_key_exists('ykCanvasThemeStylesheets', \$GLOBALS))", $canvas, 'area-only canvases harvest the theme head too');
        self::assertStringContainsString('themeDeclaredStylesheets()', $canvas);
        $functions = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');
        self::assertStringContainsString("ThemeValidator::stylesheetPath(\$sheet) && is_file(ROOT_PATH . '/themes/' . \$theme . '/assets/' . \$sheet)", $functions);
    }
}
