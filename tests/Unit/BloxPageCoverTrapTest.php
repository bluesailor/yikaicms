<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 自动封面陷阱（2.0.3，英文模板一批 36 页中招）：Blox 单页有正文封面且开着「正文顶部显示头图」时，
 * 前台在 Blox 内容之前输出整宽封面图、把标题挤出首屏，而编辑画布里看不到。
 * 导出检查报出来；页面编辑器提示并可一键关闭。
 */
final class BloxPageCoverTrapTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/SiteExportChecks.php';
    }

    public function testExportCheckFlagsBloxPagesThatStillShowTheAutoCover(): void
    {
        $data = ['settings' => [], 'tables' => [
            'channels' => [
                ['id' => 1, 'type' => 'page', 'name' => 'Features', 'show_cover' => 1],
                ['id' => 2, 'type' => 'page', 'name' => 'Pricing', 'show_cover' => 0],
                ['id' => 3, 'type' => 'page', 'name' => 'Legacy HTML', 'show_cover' => 1],
                ['id' => 4, 'type' => 'list', 'name' => 'News', 'show_cover' => 1],
                ['id' => 5, 'type' => 'page', 'name' => 'No cover', 'show_cover' => 1],
            ],
            'contents' => [
                ['channel_id' => 1, 'content_type' => 'blocks', 'cover' => '/uploads/features.webp'],
                ['channel_id' => 2, 'content_type' => 'blocks', 'cover' => '/uploads/pricing.webp'],
                ['channel_id' => 3, 'content_type' => 'html', 'cover' => '/uploads/legacy.webp'],
                ['channel_id' => 4, 'content_type' => 'blocks', 'cover' => '/uploads/news.webp'],
                ['channel_id' => 5, 'content_type' => 'blocks', 'cover' => ''],
            ],
        ]];
        $issues = SiteExportChecks::bloxCoverIssues($data);
        self::assertCount(1, $issues, 'only the Blox single page with a cover and the switch on');
        self::assertSame('usability_export_blox_cover', $issues[0]['code']);
        self::assertSame('Features', $issues[0]['label']);
        self::assertSame('/uploads/features.webp', $issues[0]['detail']);
        self::assertSame('/admin/blox_editor.php?id=1', $issues[0]['url']);
    }

    public function testEditorOffersToTurnTheCoverOffAndTheApiSavesIt(): void
    {
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        self::assertStringContainsString("'cover_applicable' => \$pageCover !== ''", $editor);
        self::assertStringContainsString('disablePageCover()', $editor);
        self::assertStringContainsString('body.set("show_cover"', $editor);
        $workspace = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/workspace.php');
        self::assertStringContainsString('data-testid="blox-page-cover-notice"', $workspace);
        self::assertStringContainsString('pageHero.cover_applicable && pageHero.show_cover', $workspace);

        $api = (string) file_get_contents(ROOT_PATH . '/admin/blox_page_api.php');
        // 只有单页、且请求里带了 show_cover 才改——标题区弹窗的其它页面类型不受影响
        self::assertStringContainsString("\$coverInput !== null && (\$targetChannel['type'] ?? '') === 'page'", $api);
        self::assertStringContainsString("\$heroUpdate['show_cover']", $api);
    }

    public function testExportCheckIncludesTheNewChecks(): void
    {
        $service = (string) file_get_contents(ROOT_PATH . '/includes/SiteTemplateService.php');
        self::assertStringContainsString('SiteExportChecks::settingDependencyIssues($data), SiteExportChecks::bloxCoverIssues($data)', $service);
    }
}
