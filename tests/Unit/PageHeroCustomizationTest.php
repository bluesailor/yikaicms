<?php
/**
 * 页面标题区定制（背景、显示开关与样式来源）契约。
 *
 * 背景：客户高频需求「横幅背景单独换一张」，v1.18.0 起 channels 提供
 * hero_bg（与正文头图 image 解耦）与 show_hero（整条横幅开关）。
 * 本测试守住三类契约：
 *   1. schema 三处同步（迁移 + 双 install SQL）；
 *   2. 前台解析链与两份 page-hero 副本不再漂移（includes 版曾缺 default_bg 兜底）；
 *   3. 联系页与其它单页统一使用通用 page-hero，旧 partial 仅保留兼容入口。
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

        $sourceMigration = $this->source('migrations/20260829_channel_hero_style_source.php');
        $this->assertStringContainsString("_columnExists('channels', 'hero_style_source')", $sourceMigration);
        $this->assertStringContainsString('ADD COLUMN `hero_style_source`', $sourceMigration);
        $this->assertStringContainsString("`hero_style_source` varchar(20) NOT NULL DEFAULT 'self'", $mysql);
        $this->assertStringContainsString('"hero_style_source" TEXT NOT NULL DEFAULT \'self\'', $sqlite);

        $optionsMigration = $this->source('migrations/20260829_channel_hero_style_options.php');
        $this->assertStringContainsString("_columnExists('channels', 'hero_style_options')", $optionsMigration);
        $this->assertStringContainsString('ADD COLUMN `hero_style_options`', $optionsMigration);
        $this->assertStringContainsString('AFTER `show_hero`', $optionsMigration);
        $this->assertStringContainsString('`hero_style_options` longtext', $mysql);
        $this->assertStringContainsString('"hero_style_options" TEXT', $sqlite);
    }

    public function testThemeHeroResolvesHeroBgFirstAndHonorsToggle(): void
    {
        foreach (['themes/default/partials/page-hero.php', 'includes/partials/page-hero.php'] as $path) {
            $hero = $this->source($path);
            $this->assertStringContainsString("(int) \$channel['show_hero'] === 0", $hero, $path . ' 缺 show_hero 开关');
            $this->assertStringContainsString('PageHeroStyleResolver::resolve($channel)', $hero, $path . ' 未使用统一解析器');
            $this->assertStringContainsString("\$heroStyle['options']", $hero, $path . ' 未应用版式参数');
            $this->assertStringContainsString('PageHeroStyleResolver::heightClasses($heroOptions)', $hero, $path . ' 缺响应式高度');
            $this->assertStringContainsString('PageHeroStyleResolver::backgroundPosition($heroOptions)', $hero, $path . ' 缺背景焦点');
            $this->assertStringContainsString('PageHeroStyleResolver::textTone($heroOptions, $heroBg)', $hero, $path . ' 缺自动文字色');
            $this->assertStringContainsString('UrlPolicy::cssImageLiteral($heroBg)', $hero, $path . ' 未按 CSS 上下文编码背景图');
        }
    }

    public function testLegacyContactHeroDelegatesToGenericPageHero(): void
    {
        $contact = $this->source('includes/partials/contact-hero.php');
        $this->assertStringContainsString("require theme_path('partials/page-hero.php');", $contact);
        $this->assertStringNotContainsString('PageHeroStyleResolver::resolve', $contact);
        $this->assertStringNotContainsString('<section', $contact);
    }

    public function testAdminEditorsPersistHeroFieldsBehindColumnGuard(): void
    {
        foreach (['admin/page_edit.php', 'admin/channel.php'] as $path) {
            $admin = $this->source($path);
            $this->assertStringContainsString("SELECT hero_bg, hero_style_source FROM", $admin, $path . ' 缺列存在性守卫');
            $this->assertStringContainsString("UrlPolicy::image(post('hero_bg'))", $admin, $path . ' 未校验 hero_bg');
            $this->assertStringContainsString("isset(\$_POST['show_hero']) ? 1 : 0", $admin, $path . ' 未保存 show_hero');
            $this->assertStringContainsString("post('hero_style_source', 'self')", $admin, $path . ' 未保存 hero_style_source');
            $this->assertStringContainsString('name="hero_bg"', $admin, $path . ' 表单缺 hero_bg 输入');
            $this->assertStringContainsString('name="show_hero"', $admin, $path . ' 表单缺 show_hero 开关');
            $this->assertStringContainsString('name="hero_style_source"', $admin, $path . ' 表单缺样式来源');
        }
    }

    public function testBloxCanvasShowsAndEditsTheSystemPageHeroWithoutTouchingDocumentData(): void
    {
        $canvas = $this->source('includes/builder/BloxCanvasPreview.php');
        $editor = $this->source('admin/blox_editor.php')
            . $this->source('admin/blox_editor/partials/overlays.php');
        $api = $this->source('admin/blox_page_api.php');
        $bridge = $this->source('assets/js/blox-canvas-bridge.js');

        $this->assertStringContainsString('data-yk-page-hero', $canvas);
        $this->assertStringContainsString('PageHeroStyleResolver::resolve($pageRow)', $canvas);
        $this->assertStringContainsString("require theme_path('partials/page-hero.php');", $canvas);
        $this->assertStringNotContainsString("partials/contact-hero.php", $canvas);
        $this->assertStringContainsString('ykEditPageHero: true', $canvas);
        $this->assertStringContainsString('data-testid="blox-page-hero-dialog"', $editor);
        $this->assertStringContainsString('body.set("action", "save_page_hero")', $editor);
        $this->assertStringContainsString('body.set("hero_style_source"', $editor);
        $this->assertStringContainsString('body.set("hero_style_options"', $editor);
        $this->assertStringContainsString('data-testid="blox-page-hero-style-preview"', $editor);
        $this->assertStringContainsString('data-testid="blox-page-hero-color-picker"', $editor);
        $this->assertStringContainsString("applyPageHeroPreset('minimal')", $editor);
        $this->assertStringContainsString('copyPageHeroToSelf()', $editor);
        $this->assertStringContainsString('restorePageHeroInheritance()', $editor);
        $this->assertStringContainsString('data-testid="blox-page-hero-effective-source"', $editor);
        $this->assertStringContainsString('pageHeroPreviewHeight()', $editor);
        $this->assertStringContainsString('x-model.number="pageHero.style_options.focal_x"', $editor);
        $this->assertStringContainsString("if (\$action === 'save_page_hero')", $api);
        $this->assertStringContainsString('UrlPolicy::image($heroBgInput)', $api);
        $this->assertStringContainsString('PageHeroStyleResolver::normalizeMode($styleSourceInput)', $api);
        $this->assertStringContainsString('PageHeroStyleResolver::encodeOptions($styleOptionsRaw', $api);
        $this->assertStringNotContainsString('$isCompactContact', $api);
        $this->assertStringContainsString('onEditPageHero', $bridge);
        $this->assertStringNotContainsString('blocks_data', substr(
            $api,
            (int) strpos($api, "if (\$action === 'save_page_hero')"),
            (int) strpos($api, "if (\$action === 'preview')") - (int) strpos($api, "if (\$action === 'save_page_hero')")
        ));
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $path);
    }
}
