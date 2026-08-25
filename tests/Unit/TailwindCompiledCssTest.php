<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tailwind v4 独立编译器会跳过 .gitignore 的源文件；Blox 编辑器现已由主仓跟踪，
 * app.css 仍用显式 @source 锁定扫描范围，避免编译入口调整后漏掉编辑器工具类。
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

    /** 区域预设卡片线框示意图（blox_templates.php）的关键类必须在产物里。 */
    public function testAreaPresetPreviewUtilitiesAreCompiled(): void
    {
        $css = file_get_contents(ROOT_PATH . '/assets/css/tailwind.css');
        self::assertNotFalse($css);

        foreach (['.xl\:grid-cols-4{', '.text-\[8px\]{', '.rounded-t-sm{', '.w-4\/5{'] as $selector) {
            self::assertStringContainsString(
                $selector,
                $css,
                "编译产物缺少 {$selector}——改动 admin/blox_templates.php 线框类后需重跑 tailwindcss 编译。"
            );
        }
    }
}
