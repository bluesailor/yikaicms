<?php
/** 预置组件（v2.1.0）：全新 / 老站补装 8 个已发布母版，带中英日默认值；按 uuid 跳过已有的，可重复跑。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxComponentPresets;
use BloxComponents;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/models/BloxTemplateModel.php';

final class BloxComponentPresetsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BloxComponents::resetForTests();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_templates (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                type TEXT NOT NULL,
                name TEXT NOT NULL,
                source TEXT NOT NULL DEFAULT 'user',
                source_ref TEXT NOT NULL DEFAULT '',
                schema_version INTEGER NOT NULL DEFAULT 1,
                draft_data TEXT NOT NULL,
                published_data TEXT,
                requirements TEXT,
                metadata TEXT,
                conditions TEXT,
                thumbnail TEXT NOT NULL DEFAULT '',
                status INTEGER NOT NULL DEFAULT 0,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0,
                published_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_component_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                component_uuid TEXT NOT NULL,
                doc_key TEXT NOT NULL,
                ref_count INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_page_drafts (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, draft_data TEXT NOT NULL, published_data TEXT, admin_id INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0, published_at INTEGER NOT NULL DEFAULT 0)",
            "CREATE TABLE contents (id INTEGER PRIMARY KEY AUTOINCREMENT, blocks_data TEXT)",
            // 与线上表一致：迁移后清整页缓存会 upsert 设置（全量跑时 HtmlCache 已加载）
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, \"group\" TEXT DEFAULT 'basic', \"key\" TEXT, value TEXT, type TEXT DEFAULT 'text', name TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT, sort_order INTEGER DEFAULT 0, UNIQUE(\"key\"))",
            "CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'page')",
            "CREATE TABLE blox_component_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                component_uuid TEXT NOT NULL,
                version INTEGER NOT NULL DEFAULT 0,
                snapshot TEXT NOT NULL,
                admin_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    public function testInstallsEveryStarterAsAPublishedMasterWithLocalizedDefaults(): void
    {
        self::assertSame(BloxComponentPresets::PRESETS, BloxComponentPresets::missing());
        self::assertSame(count(BloxComponentPresets::PRESETS), BloxComponentPresets::installMissing());

        foreach (BloxComponentPresets::PRESETS as $slug) {
            $definition = BloxComponents::find(BloxComponentPresets::uuid($slug));
            self::assertNotNull($definition, $slug . ' 已发布、能被实例使用');
            self::assertNotSame([], $definition['props'], $slug . ' 的属性都指得到母版里的节点（指不到的会被丢掉）');
        }
        $feature = BloxComponents::find(BloxComponentPresets::uuid('feature-card'));
        self::assertSame(['icon', 'title', 'text', 'link_text', 'link_url'], array_column($feature['props'], 'key'));
        // 中文没有单独的语言默认值：不替换，直接用母版里的文字
        self::assertArrayNotHasKey('title', BloxComponents::effectiveProps($feature, [], 'zh-CN'));
        self::assertSame('快速部署', $feature['props'][1]['default']);
        self::assertSame('Fast Deployment', BloxComponents::effectiveProps($feature, [], 'en')['title']);
        self::assertSame('スピード導入', BloxComponents::effectiveProps($feature, [], 'ja')['title']);

        $row = bloxTemplateModel()->findWhere(['source' => 'builtin', 'source_ref' => 'component:feature-card']);
        self::assertSame(1, (int) $row['status']);
        self::assertSame('cards', json_decode((string) $row['metadata'], true)['component']['category']);
        self::assertSame('功能卡片', $row['name'], '中文站用中文名');
        self::assertSame('Feature card', BloxComponentPresets::name('feature-card', 'en'));
        self::assertSame('機能カード', BloxComponentPresets::name('feature-card', 'ja'));
        self::assertSame('Feature card', BloxComponentPresets::name('feature-card', 'fr'), '其余语言用英文');
        self::assertSame('功能卡片', BloxComponentPresets::name('feature-card', 'zh-TW'));
    }

    public function testRerunsSkipInstalledAndAuthorOwnedMasters(): void
    {
        BloxComponentPresets::installMissing();
        self::assertSame([], BloxComponentPresets::missing());
        self::assertSame(0, BloxComponentPresets::installMissing(), '可重复跑');

        // 作者改过、且只剩草稿的同一 uuid 母版：不补装、不覆盖
        $uuid = BloxComponentPresets::uuid('cta-bar');
        db()->execute("UPDATE blox_templates SET status = 0, draft_data = '{\"edited\":true}' WHERE source_ref = 'component:cta-bar'");
        self::assertSame([], BloxComponentPresets::missing());
        self::assertSame('{"edited":true}', (string) db()->fetchColumn("SELECT draft_data FROM blox_templates WHERE source_ref = 'component:cta-bar'"));
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM blox_templates WHERE metadata LIKE ?', ['%' . $uuid . '%']));
    }

    public function testFreshInstallRenamesStartersToTheSiteLanguage(): void
    {
        BloxComponentPresets::installMissing();
        db()->execute("UPDATE blox_templates SET name = '我的卡片' WHERE source_ref = 'component:pricing-card'");
        $GLOBALS['_test_config']['site_lang'] = 'en';
        try {
            self::assertSame(count(BloxComponentPresets::PRESETS) - 1, BloxComponentPresets::localizeNames());
            self::assertSame('Feature card', (string) db()->fetchColumn("SELECT name FROM blox_templates WHERE source_ref = 'component:feature-card'"));
            self::assertSame('我的卡片', (string) db()->fetchColumn("SELECT name FROM blox_templates WHERE source_ref = 'component:pricing-card'"), '改过名的不动');
            self::assertSame(0, BloxComponentPresets::localizeNames());
        } finally {
            unset($GLOBALS['_test_config']['site_lang']);
        }
        self::assertStringContainsString('BloxComponentPresets::localizeNames();', (string) file_get_contents(ROOT_PATH . '/install/index.php'));
    }

    public function testPackagesAreGeneratedAndMigrationInstallsThem(): void
    {
        foreach (BloxComponentPresets::PRESETS as $slug) {
            $package = json_decode((string) file_get_contents(ROOT_PATH . '/templates/blox/components/' . $slug . '.json'), true);
            self::assertSame('component', $package['type'], $slug);
            self::assertSame('cmp_' . substr(md5('yikai-starter:' . $slug), 0, 16), $package['component']['uuid'], '固定 uuid：各站同一预置组件是同一母版');
        }
        $migration = require ROOT_PATH . '/migrations/20261011_starter_components.php';
        self::assertFalse(($migration['check'])());
        ($migration['php'])();
        self::assertTrue(($migration['check'])());
    }
}
