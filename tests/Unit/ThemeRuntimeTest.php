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
        // Business/Minimal 源码只存在于 marketplace/themes;themes/ 下的同名目录是
        // 开发站运行时安装副本(ignored)。用临时主题根模拟"Business 未安装",
        // 不再断言真实 ROOT_PATH/themes/business 不存在。
        $root = sys_get_temp_dir() . '/yikai-theme-runtime-' . bin2hex(random_bytes(5));
        mkdir($root, 0777, true);
        try {
            self::assertSame('default', ThemeRuntime::resolve('business', $root));
        } finally {
            @rmdir($root);
        }
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
