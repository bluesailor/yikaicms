<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * README 里所有带版本号的地方都跟 config/version.php 走。
 *
 * 2.0.1、2.0.2 两版只改了标题，「3 分钟安装」与「下载部署」的下载链接一直停在 v2.0.0：
 * 打包与发版预检当时只看第 1 行。build.sh / release-precheck.sh 已补上链接检查，
 * 这里让每次并 main 前的门禁就能发现，而不是等到打包那一刻。
 */
final class ReadmeVersionTest extends TestCase
{
    private static function version(): string
    {
        $src = (string) file_get_contents(ROOT_PATH . '/config/version.php');
        self::assertSame(1, preg_match("/define\('CMS_VERSION', '([0-9.]+)'\)/", $src, $m));
        return $m[1];
    }

    public function testTitleMatchesVersion(): void
    {
        $first = strtok((string) file_get_contents(ROOT_PATH . '/README.md'), "\n");
        self::assertSame('# YikaiCMS v' . self::version(), $first);
    }

    public function testDownloadAndReleaseLinksMatchVersion(): void
    {
        $readme = (string) file_get_contents(ROOT_PATH . '/README.md');
        preg_match_all('#(?:releases/(?:download|tag)/v|yikaicms-v)([0-9]+\.[0-9]+\.[0-9]+(?:\.[0-9]+)?)#', $readme, $m);
        self::assertNotEmpty($m[1], 'README should link the current full package');
        self::assertSame([self::version()], array_values(array_unique($m[1])));
    }
}
