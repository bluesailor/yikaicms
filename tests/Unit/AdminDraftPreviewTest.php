<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/frontend_preview.php';

final class AdminDraftPreviewTest extends TestCase
{
    /** @runInSeparateProcess */
    public function testQueryFlagsCannotAuthorizeADraft(): void
    {
        $_GET = ['preview' => 'admin', 'type' => 'products', 'id' => 37];
        $_SESSION = ['admin_id' => 1];
        self::assertNull(adminDraftPreviewRow('products', 37));
    }

    /** @runInSeparateProcess */
    public function testServerContextStillRequiresLogin(): void
    {
        define('YK_ADMIN_DRAFT_PREVIEW', ['table' => 'products', 'row' => ['id' => 37, 'status' => 0], 'channel' => ['id' => 103, 'status' => 0]]);
        $_SESSION = [];
        self::assertNull(adminDraftPreviewRow('products', 37));
        self::assertNull(adminDraftPreviewLayoutChannel(103));
        self::assertSame([], adminDraftPreviewQuery());
    }

    /** @runInSeparateProcess */
    public function testAuthorizedContextOnlySelectsOneRowInOneTable(): void
    {
        $row = ['id' => 37, 'status' => 0, 'lang' => 'fr'];
        define('YK_ADMIN_DRAFT_PREVIEW', ['table' => 'products', 'row' => $row, 'channel' => ['id' => 103, 'status' => 0]]);
        $_SESSION = ['admin_id' => 1];
        self::assertSame($row, adminDraftPreviewRow('products', 37));
        self::assertNull(adminDraftPreviewRow('products', 38));
        self::assertNull(adminDraftPreviewRow('contents', 37));
        self::assertSame(0, adminDraftPreviewRow('products', 37)['status']);
        self::assertSame(['id' => 103, 'status' => 0], adminDraftPreviewLayoutChannel(103));
        self::assertNull(adminDraftPreviewLayoutChannel(104));
        self::assertSame(['type' => 'products', 'id' => '37'], adminDraftPreviewQuery());
    }
}
