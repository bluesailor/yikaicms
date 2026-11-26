<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Yikai\Tests\TestCase;

/**
 * 2.3.1 后台顶栏设置：每个账号自己的偏好存在 metas（owner_type = admin_user），没存过 = 原来的顶栏。
 */
#[RunTestsInSeparateProcesses]
final class AdminToolbarPrefsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // getMeta / setMeta / delMeta 的替身走真实 MetaModel + 内存 SQLite（同 ExtFieldsTest）
        require_once __DIR__ . '/../fixtures/ext-fields-stubs.php';
        require_once ROOT_PATH . '/includes/AdminToolbarPrefs.php';
    }

    protected function schemaSql(): array
    {
        return [
            'CREATE TABLE metas (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_type TEXT NOT NULL, owner_id INTEGER NOT NULL DEFAULT 0, meta_key TEXT NOT NULL, meta_value TEXT, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)',
            'CREATE UNIQUE INDEX uk_owner_key ON metas (owner_type, owner_id, meta_key)',
        ];
    }

    public function testAccountWithoutSavedPreferenceKeepsEveryShortcutPinned(): void
    {
        self::assertSame([], AdminToolbarPrefs::hidden(1));
        self::assertSame([], AdminToolbarPrefs::hidden(0), 'no signed-in account means the default toolbar');
    }

    public function testNormalizeKeepsKnownItemsOnceInToolbarOrder(): void
    {
        self::assertSame(['search', 'help', 'ai'], AdminToolbarPrefs::normalize(['ai', 'help', 'bogus', 'help', 3, 'search']));
        self::assertSame([], AdminToolbarPrefs::normalize('help'));
        self::assertSame([], AdminToolbarPrefs::normalize(null));
    }

    public function testUnpinningAndPinningChangesOnlyThatItem(): void
    {
        self::assertSame(['help'], AdminToolbarPrefs::setPinned(1, 'help', false));
        self::assertSame(['help', 'cache'], AdminToolbarPrefs::setPinned(1, 'cache', false));
        self::assertSame(['notifications', 'help', 'cache'], AdminToolbarPrefs::setPinned(1, 'notifications', false));
        self::assertSame(['notifications', 'help', 'cache'], AdminToolbarPrefs::setPinned(1, 'help', false), 'unpinning twice is idempotent');
        self::assertSame(['notifications', 'cache'], AdminToolbarPrefs::setPinned(1, 'help', true));
        self::assertSame(['notifications', 'cache'], AdminToolbarPrefs::hidden(1));

        $row = db()->fetchOne('SELECT owner_type, owner_id, meta_key, meta_value FROM metas');
        self::assertSame(['owner_type' => 'admin_user', 'owner_id' => 1, 'meta_key' => 'admin_toolbar_hidden', 'meta_value' => '["notifications","cache"]'], [
            'owner_type' => $row['owner_type'], 'owner_id' => (int) $row['owner_id'], 'meta_key' => $row['meta_key'], 'meta_value' => $row['meta_value'],
        ]);
    }

    public function testShortcutUnavailableTodayKeepsItsSavedPlace(): void
    {
        // AI 助手没配置时设置面板不列它，改别的项不能把它原来的选择冲掉
        AdminToolbarPrefs::setPinned(1, 'ai', false);
        AdminToolbarPrefs::setPinned(1, 'help', false);
        AdminToolbarPrefs::setPinned(1, 'help', true);
        self::assertSame(['ai'], AdminToolbarPrefs::hidden(1));
    }

    public function testEachAccountKeepsIndependentSettings(): void
    {
        AdminToolbarPrefs::setPinned(1, 'help', false);
        AdminToolbarPrefs::setPinned(2, 'search', false);
        AdminToolbarPrefs::setPinned(2, 'visit', false);

        self::assertSame(['help'], AdminToolbarPrefs::hidden(1));
        self::assertSame(['search', 'visit'], AdminToolbarPrefs::hidden(2));
        self::assertSame([], AdminToolbarPrefs::hidden(3));

        AdminToolbarPrefs::reset(2);
        self::assertSame(['help'], AdminToolbarPrefs::hidden(1));
        self::assertSame([], AdminToolbarPrefs::hidden(2));
        self::assertSame(0, (int) db()->fetchOne("SELECT COUNT(*) AS n FROM metas WHERE owner_id = 2")['n'], 'restore defaults removes the saved row');
    }

    public function testDeletingAnAccountForgetsItsToolbar(): void
    {
        AdminToolbarPrefs::setPinned(5, 'cache', false);
        db()->insert('metas', ['owner_type' => 'content', 'owner_id' => 5, 'meta_key' => 'color', 'meta_value' => 'red', 'created_at' => 0, 'updated_at' => 0]);

        AdminToolbarPrefs::forget(5);

        self::assertSame([], AdminToolbarPrefs::hidden(5));
        self::assertSame('red', getMeta('content', 5, 'color'), 'content metas sharing the numeric id stay');
    }

    public function testUnreadableStoredValueFallsBackToDefaults(): void
    {
        setMeta('admin_user', 1, 'admin_toolbar_hidden', '{not json');
        self::assertSame([], AdminToolbarPrefs::hidden(1));
        setMeta('admin_user', 1, 'admin_toolbar_hidden', '{"help":true}');
        self::assertSame([], AdminToolbarPrefs::hidden(1));
        setMeta('admin_user', 1, 'admin_toolbar_hidden', '["help","<script>"]');
        self::assertSame(['help'], AdminToolbarPrefs::hidden(1));
    }

    public function testUnknownItemOrMissingAccountIsRejected(): void
    {
        foreach ([[1, 'logout'], [0, 'help']] as [$adminId, $item]) {
            try {
                AdminToolbarPrefs::setPinned($adminId, $item, false);
                self::fail("{$item} for account {$adminId} should be rejected");
            } catch (InvalidArgumentException) {
                self::assertSame(0, (int) db()->fetchOne('SELECT COUNT(*) AS n FROM metas')['n']);
            }
        }
    }

    public function testToolbarPreferencesDoNotTravelWithSiteTemplates(): void
    {
        require_once ROOT_PATH . '/includes/SiteTemplateData.php';
        $portable = new ReflectionMethod(SiteTemplateData::class, 'metaOwnerPortable');
        self::assertFalse($portable->invoke(null, AdminToolbarPrefs::OWNER_TYPE));
    }
}
