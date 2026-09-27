<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * The classic → Blox import snapshots "about" into standard elements, which
 * only makes sense when the theme's about template shows the site's about
 * content. A theme with a static about design (Havenform and friends) must
 * keep a home-block reference, or the canvas and the published homepage lose
 * the theme design and fall back to the generic look and default copy.
 */
final class HomeAboutThemeTemplateImportTest extends TestCase
{
    private static function decide(?string $themeTemplateSource): bool
    {
        $dir = sys_get_temp_dir() . '/yk-about-theme-' . bin2hex(random_bytes(4));
        mkdir($dir . '/includes/blocks', 0777, true);
        mkdir($dir . '/themes/t/blocks', 0777, true);
        file_put_contents($dir . '/includes/blocks/about.php', '<?php echo $aboutChannel["name"];');
        if ($themeTemplateSource !== null) {
            file_put_contents($dir . '/themes/t/blocks/about.php', $themeTemplateSource);
        }
        if (!defined('INCLUDES_PATH')) {
            define('INCLUDES_PATH', $dir . '/includes/');
        }
        $GLOBALS['ykTestAboutThemeDir'] = $dir;
        if (!function_exists('theme_path')) {
            eval('function theme_path(string $file): string {
                $dir = $GLOBALS["ykTestAboutThemeDir"];
                $theme = $dir . "/themes/t/" . $file;
                return is_file($theme) ? $theme : INCLUDES_PATH . "blocks/" . basename($file);
            }');
        }
        require_once ROOT_PATH . '/includes/builder/HomeLayoutDocument.php';
        $method = new ReflectionMethod(HomeLayoutDocument::class, 'aboutTemplateShowsSiteContent');

        return (bool) $method->invoke(null);
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCoreAboutTemplateIsSnapshotted(): void
    {
        self::assertTrue(self::decide(null));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testDataDrivenThemeAboutIsSnapshotted(): void
    {
        self::assertTrue(self::decide('<section><?= e($aboutChannel["name"] ?? "") ?></section>'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testStaticThemeAboutKeepsTheThemeBlock(): void
    {
        self::assertFalse(self::decide('<section class="hf-intro"><h2>MORE THAN A HOME.</h2></section>'));
    }

    public function testLegacyImportConsultsTheThemeTemplate(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/builder/HomeLayoutDocument.php');

        self::assertStringContainsString("if (\$type === 'about' && self::aboutTemplateShowsSiteContent()) {", $source);
    }
}
