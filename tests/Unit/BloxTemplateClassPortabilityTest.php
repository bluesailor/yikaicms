<?php
/**
 * V2.0.0 单模板 JSON 的全局类可移植性：导出被引用类的定义（可选字段，包仍是 v1），
 * 导入时映射到本站（同名同定义复用 / 同名异定义稳定改名 / 新建），与模板草稿同一事务。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxGlobalClasses;
use BloxTemplateImporter;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxTemplateClassPortabilityTest extends TestCase
{
    private const SOURCE_ID = 'gc_0123456789ab';

    protected function setUp(): void
    {
        parent::setUp();
        BloxGlobalClasses::resetForTests();
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
            "CREATE TABLE blox_global_classes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id TEXT NOT NULL,
                name TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT '',
                settings TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                trashed_at INTEGER NOT NULL DEFAULT 0,
                modified INTEGER NOT NULL DEFAULT 0,
                revision INTEGER NOT NULL DEFAULT 0,
                user_id INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE blox_class_refs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id TEXT NOT NULL,
                doc_key TEXT NOT NULL,
                ref_count INTEGER NOT NULL DEFAULT 0,
                updated_at INTEGER NOT NULL DEFAULT 0
            )",
        ];
    }

    /** @param list<string> $classIds */
    private function sections(array $classIds): array
    {
        return [[
            'type' => 'section',
            'columns' => [['elements' => [
                ['type' => 'heading', 'data' => ['text' => 'First', 'level' => 'h2', '_classes' => $classIds]],
                ['type' => 'container', 'data' => ['children' => [
                    ['type' => 'heading', 'data' => ['text' => 'Nested', 'level' => 'h3', '_classes' => $classIds]],
                ]]],
            ]]],
        ]];
    }

    private function package(mixed $classes, array $classIds = [self::SOURCE_ID]): string
    {
        $package = [
            'format' => BloxTemplateImporter::FORMAT,
            'version' => BloxTemplateImporter::VERSION,
            'type' => 'section',
            'name' => 'Class portability',
            'document' => $this->sections($classIds),
        ];
        if ($classes !== null) {
            $package['classes'] = $classes;
        }
        return json_encode($package, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function definition(array $settings = ['text_color' => '#c2410c', 'font_size_px' => 36], string $name = 'hero-title'): array
    {
        return [['class_id' => self::SOURCE_ID, 'name' => $name, 'settings' => $settings]];
    }

    private function seedClass(string $classId, string $name, array $settings, string $status = 'active'): void
    {
        db()->insert('blox_global_classes', [
            'class_id' => $classId, 'name' => $name, 'settings' => json_encode($settings, JSON_THROW_ON_ERROR),
            'status' => $status, 'created_at' => time(), 'updated_at' => time(),
        ]);
        BloxGlobalClasses::resetForTests();
    }

    /** @return list<array<string,mixed>> */
    private function classRows(): array
    {
        return db()->fetchAll('SELECT class_id, name, settings FROM ' . DB_PREFIX . 'blox_global_classes ORDER BY id');
    }

    /** @return list<string> */
    private function importedClassIds(int $templateId): array
    {
        $row = db()->fetchOne('SELECT draft_data FROM ' . DB_PREFIX . 'blox_templates WHERE id = ?', [$templateId]);
        $document = json_decode((string) $row['draft_data'], true, 512, JSON_THROW_ON_ERROR);
        return array_keys(BloxGlobalClasses::collectReferences($document['sections']));
    }

    public function testExportCarriesOnlyReferencedActiveClassesAndLeavesClasslessPackagesUnchanged(): void
    {
        $this->seedClass(self::SOURCE_ID, 'hero-title', ['text_color' => '#c2410c']);
        $this->seedClass('gc_aaaaaaaaaaaa', 'unused', ['text_color' => '#000000']);
        $this->seedClass('gc_bbbbbbbbbbbb', 'in-trash', ['text_color' => '#111111'], 'trashed');
        $template = [
            'type' => 'section', 'name' => 'Export',
            'draft_data' => json_encode(['sections' => $this->sections([self::SOURCE_ID, 'gc_bbbbbbbbbbbb'])], JSON_THROW_ON_ERROR),
        ];
        $package = BloxTemplateImporter::exportPackage($template);
        self::assertSame(1, $package['version'], '可选字段，不升级包版本');
        self::assertSame([['class_id' => self::SOURCE_ID, 'name' => 'hero-title', 'settings' => ['text_color' => '#c2410c']]], $package['classes']);

        $plain = BloxTemplateImporter::exportPackage([
            'type' => 'section', 'name' => 'Plain',
            'draft_data' => json_encode(['sections' => $this->sections([])], JSON_THROW_ON_ERROR),
        ]);
        self::assertArrayNotHasKey('classes', $plain);
    }

    public function testCleanSiteCreatesTheClassWithANewIdAndRemapsEveryReference(): void
    {
        $prepared = BloxTemplateImporter::prepare($this->package($this->definition()));
        self::assertSame([], $this->classRows(), 'prepare 只规划不写库');
        self::assertSame(['hero-title'], $prepared['class_diagnostics']['created']);

        $result = BloxTemplateImporter::importJson($this->package($this->definition()), 3);
        $rows = $this->classRows();
        self::assertCount(1, $rows);
        self::assertSame('hero-title', $rows[0]['name']);
        self::assertNotSame(self::SOURCE_ID, $rows[0]['class_id'], '不沿用来源站 ID');
        self::assertSame([$rows[0]['class_id']], $this->importedClassIds($result['id']));
        self::assertSame(['docs' => 1, 'refs' => 2], BloxGlobalClasses::usage()[$rows[0]['class_id']]);
        BloxGlobalClasses::resetForTests();
        self::assertStringContainsString('.yk-c-hero-title:not(yk-none){color:#c2410c;font-size:36px}', BloxGlobalClasses::stylesheet());
    }

    public function testSameNameSameDefinitionReusesTheLocalClass(): void
    {
        // 键序不同、数值表示不同也算同一定义（按规范化结果比较）
        $this->seedClass('gc_cccccccccccc', 'hero-title', ['font_size_px' => '36', 'text_color' => '#C2410C']);
        $prepared = BloxTemplateImporter::prepare($this->package($this->definition()));
        self::assertSame(['hero-title'], $prepared['class_diagnostics']['reused']);
        self::assertSame([], $prepared['class_plan']);

        $result = BloxTemplateImporter::importJson($this->package($this->definition()));
        self::assertCount(1, $this->classRows());
        self::assertSame(['gc_cccccccccccc'], $this->importedClassIds($result['id']));
    }

    public function testSameNameDifferentDefinitionGetsAStableNewNameAndNeverOverwrites(): void
    {
        $this->seedClass('gc_cccccccccccc', 'hero-title', ['text_color' => '#000000']);
        $first = BloxTemplateImporter::importJson($this->package($this->definition()));
        $rows = $this->classRows();
        self::assertCount(2, $rows);
        self::assertSame('{"text_color":"#000000"}', $rows[0]['settings'], '本站同名类不被覆盖');
        self::assertMatchesRegularExpression('/^hero-title-[a-f0-9]{6}$/', $rows[1]['name']);
        self::assertSame([$rows[1]['class_id']], $this->importedClassIds($first['id']));

        // 同一个包再导入一次：得到同一个新名字并复用，不会再多一个类
        $prepared = BloxTemplateImporter::prepare($this->package($this->definition()));
        self::assertSame([['from' => 'hero-title', 'to' => $rows[1]['name']]], $prepared['class_diagnostics']['renamed']);
        self::assertSame([$rows[1]['name']], $prepared['class_diagnostics']['reused']);
        $second = BloxTemplateImporter::importJson($this->package($this->definition()));
        self::assertCount(2, $this->classRows());
        self::assertSame([$rows[1]['class_id']], $this->importedClassIds($second['id']));
    }

    public function testOldPackagesWithoutClassesStillImport(): void
    {
        // 同站导出的旧包：ID 在本站存在 → 引用保留
        $this->seedClass(self::SOURCE_ID, 'hero-title', ['text_color' => '#c2410c']);
        $same = BloxTemplateImporter::importJson($this->package(null));
        self::assertSame([self::SOURCE_ID], $this->importedClassIds($same['id']));

        // 跨站旧包：ID 不存在 → 报缺失，引用原样保留（渲染期跳过），导入照常成功
        $prepared = BloxTemplateImporter::prepare($this->package(null, ['gc_dddddddddddd']));
        self::assertSame(['gc_dddddddddddd'], $prepared['class_diagnostics']['missing']);
        $other = BloxTemplateImporter::importJson($this->package(null, ['gc_dddddddddddd']));
        self::assertSame(['gc_dddddddddddd'], $this->importedClassIds($other['id']));
        self::assertCount(1, $this->classRows());
    }

    public function testAFailedImportLeavesNoClassesBehind(): void
    {
        db()->execute('DROP TABLE ' . DB_PREFIX . 'blox_templates');
        try {
            BloxTemplateImporter::importJson($this->package($this->definition()));
            self::fail('草稿写入失败应当抛错');
        } catch (\Throwable) {
        }
        self::assertSame([], $this->classRows(), '类与模板草稿同一事务回滚');
    }

    public function testMaliciousOrOversizedClassDefinitionsAreRejectedOrSanitized(): void
    {
        foreach (['not-a-list', [['class_id' => 'gc_bad', 'name' => 'x']], [['class_id' => self::SOURCE_ID, 'name' => 'Bad Name']]] as $classes) {
            try {
                BloxTemplateImporter::prepare($this->package($classes));
                self::fail('无效的类定义应当被拒绝');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('blox_class_import_invalid', $e->getMessage());
            }
        }

        // 危险值被白名单归一丢弃，不进样式表
        BloxTemplateImporter::importJson($this->package($this->definition([
            'text_color' => 'red;}body{display:none', 'font_size_px' => '16px;x', 'evil' => 'expression(1)', 'bg_color' => '#ffffff',
        ])));
        self::assertSame('{"bg_color":"#ffffff"}', $this->classRows()[0]['settings']);

        // 超出站点 200 个类的上限：导入前就拒绝，不写任何类
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_global_classes');
        for ($i = 0; $i < BloxGlobalClasses::MAX_CLASSES; $i++) {
            db()->insert('blox_global_classes', ['class_id' => sprintf('gc_%012x', $i + 1), 'name' => 'c' . $i . 'x', 'settings' => '{}', 'status' => 'trashed']);
        }
        BloxGlobalClasses::resetForTests();
        try {
            BloxTemplateImporter::prepare($this->package($this->definition()));
            self::fail('超过类上限应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_class_limit', $e->getMessage());
        }
        self::assertCount(BloxGlobalClasses::MAX_CLASSES, $this->classRows());
    }
}
