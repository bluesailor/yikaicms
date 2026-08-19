<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tailwind v4 独立编译器会跳过 .gitignore 的源文件；Blox 编辑器（私库，公开仓
 * gitignored）里独有的工具类必须靠 app.css 的显式 @source 豁免才能进产物。
 * 2026-08-20 事故：min-h-0/pb-16 从未被编译，元素库高度约束链断裂、从不滚动，
 * 1.18.1/1.18.2 均带病发布。本测试锁住编辑器布局关键类必须存在于编译产物。
 */
final class TailwindCompiledCssTest extends TestCase
{
    public function testEditorCriticalUtilitiesAreCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);

        // 元素库/设置面板滚动布局的生命线：flex 高度约束 + 底部留白。
        foreach (['.min-h-0{', '.pb-24{'] as $selector) {
            self::assertStringContainsString(
                $selector,
                $css,
                "编译产物缺少 {$selector}——大概率 app.css 的 blox_editor @source 豁免被移除，" .
                '或编译时源文件是旧版。重跑 tailwindcss 编译并核对 CLAUDE.md 的 Tailwind 备忘。'
            );
        }

        // 豁免声明本身也不许丢。
        $appCss = file_get_contents(ROOT_PATH . '/assets/css/src/app.css');
        self::assertNotFalse($appCss);
        self::assertStringContainsString('admin/blox_editor/**/*.php', $appCss);
    }
}
