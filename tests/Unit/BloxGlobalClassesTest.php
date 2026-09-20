<?php
/** v1.23 全局样式类：存储/归一/授权门/CSS 输出/用量索引契约。 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxGlobalClasses;
use BloxResponsiveValue;
use BlockRenderer;
use RuntimeException;
use Yikai\Tests\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxGlobalClassesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        BloxGlobalClasses::resetForTests();
    }

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE blox_global_classes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                class_id TEXT NOT NULL,
                name TEXT NOT NULL,
                category TEXT NOT NULL DEFAULT '',
                settings TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'active',
                trashed_at INTEGER NOT NULL DEFAULT 0,
                modified INTEGER NOT NULL DEFAULT 0,
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

    // ── 授权门：变更付费、渲染免费 ──────────────────────────────────

    public function testMutationsRequireAdvancedLicenseButRenderingStaysFree(): void
    {
        try {
            BloxGlobalClasses::mutate('class_add', ['name' => 'card-featured'], false);
            self::fail('未授权的 class_add 应当被拒绝');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_class_license_required', $e->getMessage());
        }

        // 已存在的类（例如授权到期前创建的）渲染照常输出——渲染永远免费
        $row = BloxGlobalClasses::mutate('class_add', [
            'name' => 'card-featured',
            'settings' => ['bg_color' => '#ffffff'],
        ], true);
        BloxGlobalClasses::resetForTests();
        $attribute = BloxGlobalClasses::classAttributeFor(['_classes' => [$row['class_id']]]);
        self::assertSame(' yk-c-card-featured', $attribute);
        self::assertStringContainsString('.yk-c-card-featured{background-color:#ffffff', BloxGlobalClasses::stylesheet());
    }

    public function testCreateValidatesNameAndDuplicates(): void
    {
        // 大写输入不算非法：按 Bricks 语义小写化收编
        $upper = BloxGlobalClasses::mutate('class_add', ['name' => 'Card-Hero'], true);
        self::assertSame('card-hero', $upper['name']);

        foreach (['', '1bad', 'has space', 'x', str_repeat('a', 49)] as $bad) {
            try {
                BloxGlobalClasses::mutate('class_add', ['name' => $bad], true);
                self::fail("非法类名应被拒绝：{$bad}");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('blox_class_bad_name', $e->getMessage());
            }
        }
        BloxGlobalClasses::mutate('class_add', ['name' => 'hero-cta'], true);
        $this->expectExceptionMessage('blox_class_duplicate_name');
        BloxGlobalClasses::mutate('class_add', ['name' => 'hero-cta'], true);
    }

    // ── 设置白名单归一（值会进样式表，安全边界必须白名单） ─────────────

    public function testSettingsNormalizationDropsUnsafeValues(): void
    {
        $normalized = BloxGlobalClasses::normalizeSettings([
            'text_color' => '#111827',
            'bg_color' => 'var(--yk-color-primary)',
            'border_color' => 'red;}body{display:none',
            'radius' => 'xl',
            'padding_px' => ['d' => 24, 'm' => 16, 't' => 'junk', 'w' => 999],
            'gap_px' => 12,
            'font_size_px' => 6,
            'evil' => 'expression(alert(1))',
        ]);

        self::assertSame('#111827', $normalized['text_color']);
        self::assertSame('var(--yk-color-primary)', $normalized['bg_color']);
        self::assertArrayNotHasKey('border_color', $normalized);
        self::assertArrayNotHasKey('radius', $normalized);
        self::assertSame(['d' => 24, 'm' => 16], $normalized['padding_px']);
        self::assertSame(12, $normalized['gap_px']);
        self::assertArrayNotHasKey('font_size_px', $normalized); // 低于下限
        self::assertArrayNotHasKey('evil', $normalized);
    }

    public function testClassRulesEmitMobileFirstMediaQueries(): void
    {
        BloxResponsiveValue::overrideWideEnabled(true);
        try {
            $css = BloxGlobalClasses::classRules('card', [
                'text_color' => '#333333',
                'border_color' => '#dddddd',
                'radius' => 'md',
                'padding_px' => ['d' => 32, 't' => 24, 'm' => 16, 'w' => 48],
            ]);
        } finally {
            BloxResponsiveValue::overrideWideEnabled(null);
        }

        self::assertStringContainsString('.yk-c-card{color:#333333;border-color:#dddddd;border-radius:0.5rem;border-style:solid;border-width:1px;padding:16px}', $css);
        self::assertStringContainsString('@media (min-width:768px){.yk-c-card{padding:24px}}', $css);
        self::assertStringContainsString('@media (min-width:1024px){.yk-c-card{padding:32px}}', $css);
        self::assertStringContainsString('@media (min-width:1440px){.yk-c-card{padding:48px}}', $css);
    }

    // ── 元素侧 _classes 归一与渲染 ──────────────────────────────────

    public function testElementClassesNormalization(): void
    {
        $data = BloxGlobalClasses::normalizeElementData(['_classes' => [
            'gc_aabbccddeeff', 'gc_aabbccddeeff', 'not-a-class', 42, 'gc_112233445566',
        ]]);
        self::assertSame(['gc_aabbccddeeff', 'gc_112233445566'], $data['_classes']);

        $empty = BloxGlobalClasses::normalizeElementData(['_classes' => ['junk']]);
        self::assertArrayNotHasKey('_classes', $empty);
    }

    public function testRendererAppendsClassNamesToElementRoot(): void
    {
        $row = BloxGlobalClasses::mutate('class_add', ['name' => 'lead-text'], true);
        BloxGlobalClasses::resetForTests();

        $html = BlockRenderer::renderElementNode([
            'id' => 'e1',
            'type' => 'heading',
            'data' => ['text' => 'Hi', '_classes' => [$row['class_id'], 'gc_ffffffffffff']],
        ], 0, false, [0, 0, 0]);

        self::assertStringContainsString('yk-c-lead-text', $html);
        self::assertStringNotContainsString('gc_ffffffffffff', $html); // 悬挂引用渲染期静默跳过
    }

    // ── 改名零迁移 + 乐观并发 + 回收站 ───────────────────────────────

    public function testRenameConflictTrashAndRestoreLifecycle(): void
    {
        $a = BloxGlobalClasses::mutate('class_add', ['name' => 'promo'], true);

        // 乐观并发：过期的 modified 视为冲突
        try {
            BloxGlobalClasses::mutate('class_update', [
                'id' => $a['class_id'], 'modified' => ((int) $a['modified']) - 10, 'settings' => [],
            ], true);
            self::fail('过期 modified 应触发冲突');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('blox_design_conflict', $e->getMessage());
        }

        BloxGlobalClasses::mutate('class_rename', ['id' => $a['class_id'], 'name' => 'promo-old'], true);
        BloxGlobalClasses::resetForTests();
        self::assertSame(' yk-c-promo-old', BloxGlobalClasses::classAttributeFor(['_classes' => [$a['class_id']]]));

        // 回收 → 渲染消失；同名新建 → 恢复旧类自动加后缀
        BloxGlobalClasses::mutate('class_trash', ['id' => $a['class_id']], true);
        BloxGlobalClasses::resetForTests();
        self::assertSame('', BloxGlobalClasses::classAttributeFor(['_classes' => [$a['class_id']]]));

        BloxGlobalClasses::mutate('class_add', ['name' => 'promo-old'], true);
        $restored = BloxGlobalClasses::mutate('class_restore', ['id' => $a['class_id']], true);
        self::assertSame('promo-old-2', $restored['name']);
    }

    // ── 共享样式表文件：惰性重建 + 变更即失效 ────────────────────────

    public function testSharedStylesheetFileLifecycle(): void
    {
        $path = BloxGlobalClasses::stylesheetFilePath();
        @unlink($path);
        try {
            $row = BloxGlobalClasses::mutate('class_add', [
                'name' => 'file-test',
                'settings' => ['bg_color' => '#123456'],
            ], true);
            BloxGlobalClasses::resetForTests();

            $head = BloxGlobalClasses::headOutput();
            self::assertStringContainsString('/uploads/blox/css/classes.css?v=', $head);
            self::assertFileExists($path);
            self::assertStringContainsString('.yk-c-file-test{background-color:#123456}', (string) file_get_contents($path));

            // 变更即失效：文件删除，下一次头部输出惰性重建出新内容
            BloxGlobalClasses::mutate('class_rename', ['id' => $row['class_id'], 'name' => 'file-test-2'], true);
            self::assertFileDoesNotExist($path);
            BloxGlobalClasses::resetForTests();
            BloxGlobalClasses::headOutput();
            self::assertStringContainsString('.yk-c-file-test-2{', (string) file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    // ── 用量反向索引 ────────────────────────────────────────────────

    public function testReferenceCollectionAndReverseIndex(): void
    {
        $sections = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'div',
                    'data' => [
                        '_classes' => ['gc_aabbccddeeff'],
                        'children' => [[
                            'type' => 'heading',
                            'data' => ['_classes' => ['gc_aabbccddeeff', 'gc_112233445566']],
                        ]],
                    ],
                ]],
            ]],
        ]];
        $counts = BloxGlobalClasses::collectReferences($sections);
        self::assertSame(['gc_aabbccddeeff' => 2, 'gc_112233445566' => 1], $counts);

        BloxGlobalClasses::replaceDocumentRefs('content:9', $counts);
        BloxGlobalClasses::replaceDocumentRefs('home', ['gc_112233445566' => 3]);
        $usage = BloxGlobalClasses::usage();
        self::assertSame(['docs' => 1, 'refs' => 2], $usage['gc_aabbccddeeff']);
        self::assertSame(['docs' => 2, 'refs' => 4], $usage['gc_112233445566']);

        // 再保存同一文档：整体替换，不累加
        BloxGlobalClasses::replaceDocumentRefs('content:9', ['gc_112233445566' => 1]);
        $usage = BloxGlobalClasses::usage();
        self::assertArrayNotHasKey('gc_aabbccddeeff', $usage);
        self::assertSame(['docs' => 2, 'refs' => 4], $usage['gc_112233445566']);
    }
}
