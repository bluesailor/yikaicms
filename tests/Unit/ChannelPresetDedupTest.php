<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/RecipeService.php';

final class ChannelPresetDedupTest extends TestCase
{
    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE, lang TEXT,
                parent_id INTEGER DEFAULT 0, name TEXT, type TEXT, status INTEGER DEFAULT 1,
                content TEXT, redirect_type TEXT, redirect_url TEXT, icon TEXT, description TEXT, seo_title TEXT, seo_keywords TEXT,
                seo_description TEXT, is_nav INTEGER, is_home INTEGER, sort_order INTEGER,
                created_at INTEGER, updated_at INTEGER)",
            'CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, `key` TEXT UNIQUE, `value` TEXT, `group` TEXT, name TEXT, tip TEXT)',
            'CREATE TABLE extfields (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT, field_key TEXT, field_name TEXT)',
            'CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT)',
        ];
    }

    private function channel(string $slug, string $name = 'Products', string $type = 'product', array $extra = []): int
    {
        return $this->insertRow('channels', array_merge([
            'slug' => $slug, 'name' => $name, 'type' => $type, 'lang' => 'zh-CN',
            'parent_id' => 0, 'status' => 1, 'content' => 'KEEP', 'sort_order' => 8,
        ], $extra));
    }

    private function apply(array $channels, string $lang = 'zh-CN'): array
    {
        return (new \RecipeService())->applyRecipe(['slug' => 'dedup-test', 'lang' => $lang, 'channels' => $channels]);
    }

    public function testOldAliasIsReusedWithoutChangingContentOrUrl(): void
    {
        $id = $this->channel('products', 'Customer catalogue', 'product', ['status' => 0]);
        $before = channelModel()->find($id);
        $report = $this->apply([['slug' => 'product', 'name' => 'Products', 'type' => 'product', 'content' => 'REPLACE']]);
        self::assertSame(0, $report['channels_created']);
        self::assertSame(1, $report['channels_skipped']);
        self::assertSame($before, channelModel()->find($id));
    }

    public function testRepeatedApplicationCreatesOnlyOneChannel(): void
    {
        $channels = [['slug' => 'product', 'name' => 'Products', 'type' => 'product']];
        self::assertSame(1, $this->apply($channels)['channels_created']);
        self::assertSame(0, $this->apply($channels)['channels_created']);
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM channels'));
    }

    public function testRenamedUrlMatchesSameNameWithinParentAndLanguage(): void
    {
        $id = $this->channel('catalogue');
        $match = channelModel()->matchPreset(['slug' => 'product', 'name' => 'Products', 'type' => 'product'], 'zh-CN');
        self::assertSame('existing', $match['status']);
        self::assertSame($id, (int) $match['channel']['id']);
    }

    public function testConflictingTypeRollsBackEntireApplication(): void
    {
        $this->channel('solutions', 'Solutions', 'list');
        try {
            $this->apply([
                ['slug' => 'new-page', 'name' => 'New page', 'type' => 'page'],
                ['slug' => 'solution', 'name' => 'Solutions', 'type' => 'case'],
            ]);
            self::fail('Conflicting preset should be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('chbatch_conflict', $e->getMessage());
        }
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM channels'));
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM settings'));
        self::assertSame(1, $this->apply([['slug' => 'other', 'name' => 'Other', 'type' => 'page']])['channels_created']);
    }

    public function testAlreadyDuplicatedAliasesAreNotSilentlyMerged(): void
    {
        $this->channel('product');
        $this->channel('products');
        self::assertSame('conflict', channelModel()->matchPreset(['slug' => 'product', 'type' => 'product'], 'zh-CN')['status']);
        self::assertSame(2, (int) db()->fetchColumn('SELECT COUNT(*) FROM channels'));
    }

    public function testOtherLanguagesAndParentsAreNotReused(): void
    {
        $this->channel('products', 'Products', 'product', ['lang' => 'en']);
        $this->channel('child-products', 'Products', 'product', ['parent_id' => 30]);
        self::assertSame('new', channelModel()->matchPreset(['slug' => 'product', 'name' => 'Products', 'type' => 'product'], 'zh-CN')['status']);
        self::assertSame('conflict', channelModel()->matchPreset(['slug' => 'products', 'type' => 'product'], 'zh-CN')['status']);
    }

    public function testLanguageSuffixAliasUsesTheCorrectLanguage(): void
    {
        $id = $this->channel('product-en', 'Customer products', 'product', ['lang' => 'en']);
        $match = channelModel()->matchPreset(['slug' => 'products', 'name' => 'Products', 'type' => 'product'], 'en');
        self::assertSame($id, (int) $match['channel']['id']);
    }

    public function testChildrenUseReusedParentWithoutMovingExistingRows(): void
    {
        $parent = $this->channel('products');
        $channels = [
            ['slug' => 'spec', 'parent_slug' => 'product', 'name' => 'Specifications', 'type' => 'page'],
            ['slug' => 'product', 'name' => 'Products', 'type' => 'product'],
        ];
        self::assertSame(1, $this->apply($channels)['channels_created']);
        self::assertSame($parent, (int) db()->fetchColumn("SELECT parent_id FROM channels WHERE slug = 'spec'"));
        self::assertSame(0, $this->apply($channels)['channels_created']);
    }

    public function testCycleIsRejectedWithoutLeavingPlaceholderRows(): void
    {
        $this->expectExceptionMessage('chbatch_parent_conflict');
        try {
            $this->apply([['slug' => 'a', 'parent_slug' => 'b'], ['slug' => 'b', 'parent_slug' => 'a']]);
        } finally {
            self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM channels'));
        }
    }

    public function testConcurrentApplicationIsRejectedBeforeAnyWrite(): void
    {
        $handle = fopen(ROOT_PATH . '/storage/.recipe-apply.lock', 'c');
        self::assertNotFalse($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            $this->apply([['slug' => 'product', 'type' => 'product']]);
            self::fail('A competing application must not write');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('chbatch_busy', $e->getMessage());
            self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM channels'));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testCanonicalPresetsAgreeWithInstallData(): void
    {
        $catalog = require ROOT_PATH . '/includes/channel_catalog.php';
        foreach (['products' => ['product', 'product'], 'solutions' => ['solution', 'case'],
            'services' => ['service', 'page'], 'jobs' => ['job', 'job']] as $key => [$slug, $type]) {
            self::assertSame($slug, $catalog['items'][$key]['slug']);
            self::assertSame($type, $catalog['items'][$key]['type']);
        }
    }

    public function testPreviewDoesNotWriteAndCanBeAppliedOnce(): void
    {
        $service = new \RecipeService(ROOT_PATH . '/tests/fixtures/site-setup');
        $plan = $service->preview('basic', false);
        self::assertFalse($plan['blocked']);
        self::assertSame('new', $plan['channels'][0]['action']);
        self::assertSame(0, (int) db()->fetchColumn('SELECT COUNT(*) FROM channels'));
        $report = $service->apply('basic', ['expected_fingerprint' => $plan['fingerprint']]);
        self::assertSame(1, $report['channels_created']);
        self::assertSame('none', db()->fetchColumn('SELECT redirect_type FROM channels'));
        $this->expectExceptionMessage('setup_plan_stale');
        $service->apply('basic', ['expected_fingerprint' => $plan['fingerprint']]);
    }

    public function testChangesAfterPreviewRequireAnotherPreview(): void
    {
        $service = new \RecipeService(ROOT_PATH . '/tests/fixtures/site-setup');
        $plan = $service->preview('basic', false);
        $this->channel('unrelated');
        $this->expectExceptionMessage('setup_plan_stale');
        try {
            $service->apply('basic', ['expected_fingerprint' => $plan['fingerprint']]);
        } finally {
            self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM channels'));
        }
    }

    public function testManifestCannotAuthorizeOverwrite(): void
    {
        $id = $this->channel('setup-page', 'Customer page', 'page');
        $service = new \RecipeService(ROOT_PATH . '/tests/fixtures/site-setup');
        $plan = $service->preview('basic', false);
        self::assertSame('keep', $plan['channels'][0]['action']);
        $service->apply('basic', ['expected_fingerprint' => $plan['fingerprint']]);
        self::assertSame('Customer page', channelModel()->find($id)['name']);
    }

    public function testExistingCustomFieldsArePreservedByDefault(): void
    {
        $this->insertRow('extfields', ['owner_type' => 'article', 'field_key' => 'extra', 'field_name' => 'Customer field']);
        $report = (new \RecipeService())->applyRecipe(['slug' => 'safe-fields',
            'extfields' => [['owner_type' => 'article', 'field_key' => 'extra', 'field_name' => 'Replace']]]);
        self::assertSame(1, $report['extfields_skipped']);
        self::assertSame('Customer field', db()->fetchColumn('SELECT field_name FROM extfields'));
    }
}
