<?php
/**
 * 相册页（荣誉资质、厂房设备…）的编辑入口必须直达相册图片。
 *
 * 这类页面的主要内容是相册图片。前台管理条的「编辑此页」过去一律指向页面设计器，
 * 那里改不了图片；后台单页卡片的主按钮又只写着泛泛的「内容编辑」。客户站在
 * /honor.html 上，不知道证书图片该去哪换。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AlbumPageEditEntryTest extends TestCase
{
    private function source(string $relative): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(ROOT_PATH . '/' . $relative));
    }

    public function testFrontendAdminBarOpensTheAlbumPhotos(): void
    {
        $page = $this->source('page.php');
        self::assertStringContainsString(
            "    if ((\$channel['type'] ?? '') === 'album') {\n"
            . "        // 相册页",
            $page
        );
        self::assertStringContainsString("\$GLOBALS['ik_edit_url'] = pagePrimaryEditUrl(\$channel);", $page);
        self::assertStringContainsString("\$GLOBALS['ik_edit_label'] = 'ab_edit_album';", $page);

        // 管理条按页面给的语言键显示按钮文字，缺省仍是「编辑此页」
        self::assertStringContainsString(
            "__((string) (\$GLOBALS['ik_edit_label'] ?? 'ab_edit_page'))",
            $this->source('includes/admin_bar.php')
        );
    }

    /** 目标就是相册图片管理页（与后台单页列表同一个函数）。 */
    public function testPrimaryEditTargetForAlbumsIsThePhotoManager(): void
    {
        $functions = $this->source('includes/functions.php');
        self::assertStringContainsString("? '/admin/album_photos.php?id=' . \$albumId", $functions);
        self::assertFileExists(ROOT_PATH . '/admin/album_photos.php');
    }

    public function testAdminPageCardNamesTheAlbumAction(): void
    {
        $cards = $this->source('admin/includes/website_pages.php');
        self::assertStringContainsString(
            "'album' => ['ti-photo', 'website_pages_edit_album', 'website_pages_edit_album_tip'],",
            $cards
        );

        foreach (['zh-CN', 'en', 'ja'] as $lang) {
            $strings = require ROOT_PATH . '/lang/' . $lang . '.php';
            foreach (['ab_edit_album', 'website_pages_edit_album', 'website_pages_edit_album_tip'] as $key) {
                self::assertNotSame('', trim((string) ($strings[$key] ?? '')), "{$lang} 缺少 {$key}");
            }
        }
    }
}
