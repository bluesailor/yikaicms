<?php
/**
 * SEO 助手插件的取数契约。
 *
 * 核心 contentUrl() 的注释里点名了一类反复出事的缺陷：查询漏 select `c.type`
 * → 文章类 URL 退化成 /{栏目}/{slug}.html 这种 404 地址。它已经在
 * ContentModel::getPrev/getNext/getRelated、StaticHtml::enumerate、sitemap.php
 * 上各犯过一次，共同点是「页面看着正常、点进去才 404」。
 *
 * 本插件把这些 URL **提交给搜索引擎**，犯了就是把 404 推给百度，比页面出错更隐蔽，
 * 所以在这里钉死：两处取内容 URL 的 SQL 必须带 c.type。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SeoPluginTest extends TestCase
{
    /** @return array<int, array{0: string, 1: string}> */
    public static function urlQueryFiles(): array
    {
        return [
            'free 手动推送 / llms.txt' => ['plugins/seo/lib.php', 'seo_all_urls'],
            'pro 自动推送增量'         => ['plugins/seo/autopush.php', 'seo_autopush_changed'],
        ];
    }

    /** @dataProvider urlQueryFiles */
    public function testContentUrlQueriesSelectTypeColumn(string $relPath, string $fn): void
    {
        $src = file_get_contents(ROOT_PATH . '/' . $relPath);
        self::assertIsString($src, $relPath . ' 应可读');
        self::assertStringContainsString('function ' . $fn, $src);

        // 取 contents 的 SELECT 必须含 c.type —— 漏了就会把 404 地址推给搜索引擎
        preg_match_all('/SELECT\s+c\.[^\']+/i', $src, $m);
        self::assertNotEmpty($m[0], $relPath . ' 应有取 contents 的 SELECT');
        foreach ($m[0] as $select) {
            self::assertMatchesRegularExpression(
                '/\bc\.type\b/',
                $select,
                $relPath . " 的 SELECT 漏了 c.type：文章 URL 会退化成 404 地址被提交给搜索引擎\n" . $select
            );
        }
    }

    public function testAutopushOnlySubmitsPubliclyVisibleContent(): void
    {
        $src = file_get_contents(ROOT_PATH . '/plugins/seo/autopush.php');
        self::assertIsString($src);
        // 草稿与回收站内容推给搜索引擎只会拿到 404
        self::assertStringContainsString('c.status = 1', $src);
        self::assertStringContainsString('c.deleted_at IS NULL', $src);
    }

    public function testAutopushIsGatedAndBatched(): void
    {
        $src = file_get_contents(ROOT_PATH . '/plugins/seo/autopush.php');
        self::assertIsString($src);
        // Pro 闸：任务体内自己判，非 Pro 站点早退
        self::assertStringContainsString("license_has_module('seo-pro')", $src);
        // 单批上限：百度普通收录每日配额有限
        self::assertStringContainsString('SEO_AUTOPUSH_BATCH', $src);
        // 推送失败不推进游标，这批内容下次还要重试
        self::assertStringContainsString('if ($anyOk) {', $src);
    }

    public function testCronCommandLoadsPluginsSoPluginTasksRun(): void
    {
        // bin/yikai.php 不走 init.php，不显式加载插件的话，插件注册的 cron 任务
        // 只在 web 版 cron.php 下生效，用 crontab 跑 CLI 的站点会静默漏掉。
        $src = file_get_contents(ROOT_PATH . '/includes/commands/cron.php');
        self::assertIsString($src);
        self::assertStringContainsString('hooks.php', $src);
        self::assertStringContainsString('loadActivePlugins', $src);
    }
}
