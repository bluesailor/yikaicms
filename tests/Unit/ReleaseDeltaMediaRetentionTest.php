<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 增量升级不得删除随包图片。
 *
 * v1.20.0 把 images/case-demo.jpg、images/cert-1.jpg、assets/images/demo/about-office.jpg 等换成 webp。
 * 1.19.9 站点的演示内容（安装 SQL）、用户从内置模板插入的页面、站点 Logo 设置都以 URL 引用这些文件；
 * 若增量包把它们列入 deleted，升级后客户页面破图。完整包（新装）不再携带旧图不受影响。
 */
final class ReleaseDeltaMediaRetentionTest extends TestCase
{
    /** @return list<string> build.sh 中 D / R 两个分支的豁免模式 */
    private function exemptPatterns(string $branch): array
    {
        $build = (string) file_get_contents(ROOT_PATH . '/build.sh');
        $terminator = $branch === 'D' ? 'continue;;' : ';;';
        $marker = $branch === 'D' ? "                D)\n" : "                R*)\n";
        $start = strpos(str_replace("\r\n", "\n", $build), $marker);
        self::assertNotFalse($start, 'build.sh 缺少 ' . $branch . ' 分支');
        $section = substr(str_replace("\r\n", "\n", $build), $start, 2000);
        $quoted = preg_quote($terminator, '/');
        self::assertSame(1, preg_match('/^\s*(config\/config\.php\|[^\n]*?)\)\s*' . $quoted . '\s*$/m', $section, $m), $branch . ' 分支豁免行解析失败');
        return explode('|', $m[1]);
    }

    private function exempt(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            // bash case 的 * 可以跨越 /，与不带 FNM_PATHNAME 的 fnmatch 一致
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }
        return false;
    }

    public function testBundledImagesReplacedSinceTheLastReleaseAreNeverDeletedByADelta(): void
    {
        $legacyReferenced = [
            'images/case-demo.jpg',
            'images/cert-1.jpg',
            'images/logo.png',
            'images/gaba.png',
            'assets/images/demo/about-office.jpg',
            'assets/images/blox-templates/service-process.png',
        ];
        foreach (['D', 'R'] as $branch) {
            $patterns = $this->exemptPatterns($branch);
            foreach ($legacyReferenced as $path) {
                self::assertTrue($this->exempt($path, $patterns), "{$branch} 分支会删除仍被存量内容引用的 {$path}");
            }
            // 豁免只针对图片目录，模板 JSON 等目录项仍应被清理
            self::assertFalse($this->exempt('templates/blox/sections/case-grid.json', $patterns));
            self::assertFalse($this->exempt('includes/old-helper.php', $patterns));
        }
    }
}
