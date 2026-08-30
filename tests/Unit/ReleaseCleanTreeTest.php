<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ReleaseCleanTreeTest extends TestCase
{
    public function testBuildRejectsDirtyTreeBeforePackaging(): void
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        $guard = strpos($build, 'status --porcelain --untracked-files=normal');
        $failure = strpos($build, '正式构建已中止');
        $copy = strpos($build, '[1/5] 复制项目文件');

        self::assertNotFalse($guard);
        self::assertNotFalse($failure);
        self::assertNotFalse($copy);
        self::assertLessThan($copy, $guard);
        self::assertLessThan($copy, $failure);
        self::assertStringContainsString('git.exe', $build);
        self::assertStringContainsString('repo_git()', $build);
        self::assertStringNotContainsString('cp -r "$ROOT_DIR/." "$PKG_DIR/"', $build);
        self::assertStringContainsString('tools/build-product-manifest.php', $build);
    }
}
