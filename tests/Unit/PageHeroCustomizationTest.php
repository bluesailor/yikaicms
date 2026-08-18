<?php
/**
 * 内页横幅定制（hero_bg / show_hero）契约。
 *
 * 背景：客户高频需求「横幅背景单独换一张」，v1.18.0 起 channels 提供
 * hero_bg（与正文头图 image 解耦）与 show_hero（整条横幅开关）。
 * 本测试守住三类契约：
 *   1. schema 三处同步（迁移 + 双 install SQL）；
 *   2. 前台解析链与两份 page-hero 副本不再漂移（includes 版曾缺 default_bg 兜底）；
 *   3. 联系页仅认显式 hero_bg（不继承 image/全局默认——升级不改变存量站观感）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PageHeroCustomizationTest extends TestCase
{
    public function testSchemaShipsHeroColumnsEverywhere(): void
    {
        $migration = $this->source('migrations/20260819_channel_hero_options.php');
        $this->assertStringContainsString("_columnExists('channels', 'hero_bg')", $migration);
        $this->assertStringContainsString("_columnExists('channels', 'show_hero')", $migration);
        $this->assertStringContainsString('ADD COLUMN `hero_bg`', $migration);
        $this->assertStringContainsString('ADD COLUMN `show_hero`', $migration);

        $mysql = $this->source('install/sql/mysql.sql');
        $this->assertStringContainsString('`hero_bg` varchar(500) NOT NULL DEFAULT \'\'', $mysql);
        $this->assertStringContainsString('`show_hero` tinyint(1) NOT NULL DEFAULT 1', $mysql);

        $sqlite = $this->source('install/sql/sqlite.sql');
        $this->assertStringContainsString('"hero_bg" TEXT NOT NULL DEFAULT \'\'', $sqlite);
        $this->assertStringContainsString('"show_hero" INTEGER NOT NULL DEFAULT 1', $sqlite);
    }

    public function testThemeHeroResolvesHeroBgFirstAndHonorsToggle(): void
    {
        foreach (['themes/default/partials/page-hero.php', 'includes/partials/page-hero.php'] as $path) {
            $hero = $this->source($path);
            $this->assertStringContainsString("(int) \$channel['show_hero'] === 0", $hero, $path . ' 缺 show_hero 开关');
            // 解析链整条表达式逐字断言：hero_bg → image → 全局默认（两份副本必须一字不差，防漂移）
            $this->assertStringContainsString(
                "\$heroBg = (\$channel['hero_bg'] ?? '') ?: ((\$channel['image'] ?? '') ?: (string) config('page_hero_default_bg', ''));",
                $hero,
                $path . ' 解析链与契约不符'
            );
        }
    }

    public function testContactHeroOnlyHonorsExplicitHeroBg(): void
    {
        $contact = $this->source('includes/partials/contact-hero.php');
        $this->assertStringContainsString("(int) \$channel['show_hero'] === 0", $contact);
        $this->assertStringContainsString("\$channel['hero_bg']", $contact);
        // 联系页不继承 image / 全局默认：留空必须保持紧凑白底
        $this->assertStringNotContainsString('page_hero_default_bg', $contact);
        $this->assertStringNotContainsString("\$channel['image']", $contact);
        $this->assertStringContainsString('bg-white border-y border-gray-100', $contact);
    }

    public function testAdminEditorsPersistHeroFieldsBehindColumnGuard(): void
    {
        foreach (['admin/page_edit.php', 'admin/channel.php'] as $path) {
            $admin = $this->source($path);
            $this->assertStringContainsString("SELECT hero_bg FROM", $admin, $path . ' 缺列存在性守卫');
            $this->assertStringContainsString("post('hero_bg')", $admin, $path . ' 未保存 hero_bg');
            $this->assertStringContainsString("isset(\$_POST['show_hero']) ? 1 : 0", $admin, $path . ' 未保存 show_hero');
            $this->assertStringContainsString('name="hero_bg"', $admin, $path . ' 表单缺 hero_bg 输入');
            $this->assertStringContainsString('name="show_hero"', $admin, $path . ' 表单缺 show_hero 开关');
        }
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $path);
    }
}
