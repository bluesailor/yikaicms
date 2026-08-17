<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ThemeRuntime;

require_once ROOT_PATH . '/includes/ThemeRuntime.php';

final class ThemeRuntimeTest extends TestCase
{
    public function testInstalledDefaultThemeIsSelected(): void
    {
        self::assertSame('default', ThemeRuntime::resolve('default', ROOT_PATH . '/themes'));
    }

    public function testRemovedMarketplaceThemeFallsBackToDefault(): void
    {
        self::assertDirectoryDoesNotExist(ROOT_PATH . '/themes/business');
        self::assertSame('default', ThemeRuntime::resolve('business', ROOT_PATH . '/themes'));
    }

    public function testUnsafeOrIncompleteThemeFallsBackToDefault(): void
    {
        self::assertSame('default', ThemeRuntime::resolve('../marketplace/themes/aurora', ROOT_PATH . '/themes'));

        $root = sys_get_temp_dir() . '/yikai-theme-runtime-' . bin2hex(random_bytes(5));
        mkdir($root . '/incomplete', 0777, true);
        file_put_contents($root . '/incomplete/theme.json', '{}');
        try {
            self::assertSame('default', ThemeRuntime::resolve('incomplete', $root));
        } finally {
            unlink($root . '/incomplete/theme.json');
            rmdir($root . '/incomplete');
            rmdir($root);
        }
    }
}
