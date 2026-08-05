<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 模板解析完整性：根模板里 require 的每个 theme_path() 目标，
 * 在**每一套主题**下都必须能解析到真实文件。
 *
 * 起因：v1.16.0 新增 partials/contact-hero.php 只放进了 themes/default，
 * 其余四套主题既无自带文件、回落目录 includes/partials/ 也没有，
 * 导致所有非 default 主题的联系页 require 失败（线上客户站实际中招）。
 * 单元测试与冒烟测试都只跑默认主题，故未拦住。
 */
final class ThemeTemplateResolutionTest extends TestCase
{
    /** @return list<string> 所有随仓库的主题目录名 */
    private function themes(): array
    {
        $out = [];
        foreach ((array) glob(ROOT_PATH . '/themes/*', GLOB_ONLYDIR) as $dir) {
            if (is_file($dir . '/theme.json')) {
                $out[] = basename($dir);
            }
        }
        sort($out);
        return $out;
    }

    /** @return list<string> 根模板里出现的 theme_path('...') 字面量 */
    private function requiredTemplates(): array
    {
        $found = [];
        foreach ((array) glob(ROOT_PATH . '/*.php') as $file) {
            $src = (string) file_get_contents($file);
            if (preg_match_all("/theme_path\(\s*'([^']+)'\s*\)/", $src, $m)) {
                foreach ($m[1] as $tpl) {
                    // 动态拼接的（如 'partials/timeline-' . $layout . '.php'）跳过
                    if (str_ends_with($tpl, '.php')) {
                        $found[$tpl] = true;
                    }
                }
            }
        }
        $out = array_keys($found);
        sort($out);
        return $out;
    }

    /** 复刻 theme_path() 的解析顺序：覆盖层 → 主题目录 → includes 回落 */
    private function resolve(string $theme, string $file): ?string
    {
        $candidates = [
            ROOT_PATH . '/overrides/' . $file,
            ROOT_PATH . '/themes/' . $theme . '/' . $file,
            str_starts_with($file, 'layouts/')
                ? ROOT_PATH . '/includes/' . basename($file)
                : (str_starts_with($file, 'pages/')
                    ? ROOT_PATH . '/' . basename($file)
                    : ROOT_PATH . '/includes/' . $file),
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }

    public function testEveryThemeResolvesEveryRequiredTemplate(): void
    {
        $themes    = $this->themes();
        $templates = $this->requiredTemplates();

        self::assertNotEmpty($themes, '未扫描到任何主题');
        self::assertNotEmpty($templates, '未扫描到任何 theme_path() 引用');

        $missing = [];
        foreach ($themes as $theme) {
            foreach ($templates as $tpl) {
                if ($this->resolve($theme, $tpl) === null) {
                    $missing[] = $theme . ' → ' . $tpl;
                }
            }
        }

        self::assertSame([], $missing, "以下主题解析不到模板，该主题的对应页面会直接 require 失败：\n  " . implode("\n  ", $missing));
    }
}
