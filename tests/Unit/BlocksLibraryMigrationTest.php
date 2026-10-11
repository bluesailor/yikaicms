<?php
/** 旧块库下线（v2.1.0）：报告引用、库块另存为区块模板、引用就地展开；可重复跑。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BlocksLibraryMigration;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/models/BloxTemplateModel.php';

final class BlocksLibraryMigrationTest extends TestCase
{
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
            "CREATE TABLE settings (id INTEGER PRIMARY KEY AUTOINCREMENT, \"group\" TEXT DEFAULT 'basic', \"key\" TEXT, value TEXT, type TEXT DEFAULT 'text', name TEXT DEFAULT '', tip TEXT DEFAULT '', options TEXT, sort_order INTEGER DEFAULT 0, UNIQUE(\"key\"))",
            "CREATE TABLE blocks_library (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, data TEXT, created_at INTEGER NOT NULL DEFAULT 0, updated_at INTEGER NOT NULL DEFAULT 0)",
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

    private function seed(): void
    {
        $section = ['id' => 'lib_s', 'type' => 'section', 'settings' => [], 'columns' => [['id' => 'lib_c', 'elements' => [
            ['id' => 'lib_h', 'type' => 'heading', 'data' => ['text' => '库里的标题', 'level' => 'h2']],
        ]]]];
        db()->execute('INSERT INTO blocks_library (name, data) VALUES (?, ?)', ['服务介绍', json_encode($section, JSON_UNESCAPED_UNICODE)]);
        $page = ['schema' => 1, 'sections' => [['id' => 'ref1', 'library_id' => 1, 'library_name' => '服务介绍'], ['id' => 'ghost', 'library_id' => 99]]];
        db()->execute('INSERT INTO contents (blocks_data) VALUES (?)', [json_encode($page, JSON_UNESCAPED_UNICODE)]);
        db()->execute('INSERT INTO settings ("key", value) VALUES (?, ?)', ['home_blox_published', json_encode(['schema' => 1, 'sections' => [['id' => 'ref2', 'library_id' => 1]]])]);
    }

    public function testReportsMigratesAndExpandsReferencesOnce(): void
    {
        $this->seed();
        $report = BlocksLibraryMigration::report();
        self::assertSame(3, $report['references'], '包括指向已删库块的引用');
        self::assertSame(2, $report['entries'][0]['refs']);
        self::assertFalse($report['entries'][0]['migrated']);

        $result = BlocksLibraryMigration::migrate();
        self::assertSame(['templates' => 1, 'documents' => 2, 'references' => 2], $result);

        $page = json_decode((string) db()->fetchColumn('SELECT blocks_data FROM contents WHERE id = 1'), true);
        self::assertSame('ref1', $page['sections'][0]['id'], '保留引用处的区块 id');
        self::assertSame('库里的标题', $page['sections'][0]['columns'][0]['elements'][0]['data']['text']);
        self::assertArrayNotHasKey('library_id', $page['sections'][0]);
        self::assertSame(99, $page['sections'][1]['library_id'], '库里已没有的引用原样留下');
        self::assertStringNotContainsString('"library_id":1', (string) db()->fetchColumn("SELECT value FROM settings WHERE \"key\" = 'home_blox_published'"));

        $template = bloxTemplateModel()->findWhere(['source' => 'import', 'source_ref' => 'library:1']);
        self::assertSame('section', $template['type']);
        self::assertSame('服务介绍', $template['name']);
        self::assertSame(1, (int) $template['status']);

        $after = BlocksLibraryMigration::report();
        self::assertTrue($after['entries'][0]['migrated']);
        self::assertSame(0, $after['entries'][0]['refs']);
        self::assertSame(['templates' => 0, 'documents' => 0, 'references' => 0], BlocksLibraryMigration::migrate(), '可重复跑');
        self::assertSame(1, (int) db()->fetchColumn('SELECT COUNT(*) FROM blocks_library'), '库数据保留一个版本');
    }

    public function testNewLibraryBlocksCanNoLongerBeSaved(): void
    {
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/page_edit_advance.php');
        self::assertStringContainsString("error(__('pea_library_retired'));", $editor);
        self::assertStringNotContainsString('saveToLibrary(', $editor);
        self::assertStringNotContainsString('insertLibRef(', $editor, '也不再插入新的引用，只能插入副本');
    }
}
