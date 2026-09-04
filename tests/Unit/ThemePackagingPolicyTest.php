<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__, 2));
}

final class ThemePackagingPolicyTest extends TestCase
{
    public function testBuildExcludesMarketplaceSources(): void
    {
        $script = file_get_contents(ROOT_PATH . '/build.sh');

        self::assertIsString($script);
        self::assertStringContainsString('"marketplace"', $script);
    }

    public function testFullPackageStagesBusinessAndMinimalFromMarketplaceSources(): void
    {
        $script = file_get_contents(ROOT_PATH . '/build.sh');

        self::assertIsString($script);
        self::assertStringContainsString('BUNDLED_THEMES=("business" "minimal")', $script);
        self::assertStringContainsString('source_dir="$ROOT_DIR/marketplace/themes/$theme"', $script);
        self::assertStringContainsString('target_dir="$PKG_DIR/themes/$theme"', $script);
        self::assertStringContainsString('cp -a "$source_dir/." "$target_dir/"', $script);
    }

    public function testDeltaUpdatesNeverDeleteInstalledThemes(): void
    {
        $script = file_get_contents(ROOT_PATH . '/build.sh');

        self::assertIsString($script);
        self::assertSame(
            2,
            substr_count($script, 'config/config.php|storage/*|uploads/*|install/*|themes/*'),
            'Both deleted-file branches must protect market themes installed on the site.'
        );
    }
}
