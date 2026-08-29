<?php
/** 全局页面标题区草稿发布契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PageHeroDesignDraft;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/PageHeroStyleResolver.php';
require_once ROOT_PATH . '/includes/PageHeroDesignDraft.php';

final class PageHeroDesignDraftTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, "group" TEXT DEFAULT \'basic\', "key" TEXT, value TEXT, type TEXT DEFAULT \'text\', name TEXT DEFAULT \'\', tip TEXT DEFAULT \'\', options TEXT, sort_order INT DEFAULT 0)',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        // SettingModel 是进程级单例；测试重建表后同步清掉其内存缓存。
        settingModel()->saveBatch([]);
        $GLOBALS['_test_config'] = [
            'page_hero_default_bg' => '/published.jpg',
            'page_hero_style_options' => '',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['_test_config']);
        parent::tearDown();
    }

    public function testSavingDraftDoesNotOverwritePublishedKeys(): void
    {
        $snapshot = PageHeroDesignDraft::saveDraft([
            'background' => '/draft.jpg',
            'options' => ['height' => 'large', 'overlay_opacity' => 35],
        ], 0);

        self::assertSame('/draft.jpg', $snapshot['draft']['background']);
        self::assertSame('large', $snapshot['draft']['options']['height']);
        self::assertTrue($snapshot['has_draft']);
        self::assertNull(db()->fetchOne('SELECT value FROM settings WHERE "key" = ?', ['page_hero_default_bg']));
        self::assertNull(db()->fetchOne('SELECT value FROM settings WHERE "key" = ?', ['page_hero_style_options']));
    }

    public function testPublishingCopiesNormalizedDraftIntoFrontendSettings(): void
    {
        PageHeroDesignDraft::saveDraft([
            'background' => '/draft.jpg',
            'options' => ['height' => 'large', 'overlay_opacity' => 35, 'unknown' => 'drop-me'],
        ], 0);
        PageHeroDesignDraft::publish([
            'background' => '/draft.jpg',
            'options' => ['height' => 'large', 'overlay_opacity' => 35, 'unknown' => 'drop-me'],
        ], 1);

        self::assertSame('/draft.jpg', db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['page_hero_default_bg']));
        $options = (string) db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['page_hero_style_options']);
        self::assertStringContainsString('"height":"large"', $options);
        self::assertStringNotContainsString('unknown', $options);
        self::assertSame('2', db()->fetchColumn('SELECT value FROM settings WHERE "key" = ?', ['page_hero_design_published_revision']));
    }

    public function testStaleRevisionCannotOverwriteANewerDraft(): void
    {
        PageHeroDesignDraft::saveDraft(['background' => '/one.jpg', 'options' => []], 0);

        $this->expectException(RuntimeException::class);
        PageHeroDesignDraft::saveDraft(['background' => '/stale.jpg', 'options' => []], 0);
    }

    public function testAdminSurfaceKeepsDraftAndPublishAsSeparateActions(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/blox_design.php');
        $api = (string) file_get_contents(ROOT_PATH . '/admin/blox_design_api.php');

        self::assertStringContainsString("savePageHeroDraft() { return this.mutatePageHero('page_hero_save_draft'); }", $page);
        self::assertStringContainsString("publishPageHero() { return this.mutatePageHero('page_hero_publish'); }", $page);
        self::assertStringContainsString("['page_hero_save_draft', 'page_hero_publish']", $api);
        self::assertStringContainsString('data-testid="blox-design-page-hero"', $page);
        self::assertStringNotContainsString('x-model="pageHeroJson"', $page);
    }
}
