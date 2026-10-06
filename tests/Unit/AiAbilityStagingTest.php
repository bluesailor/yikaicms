<?php
/**
 * 2.0.3：AI 助手的写操作一律「暂存 → 用户确认 → 生效，可撤销」。
 * 此前只有「修改设置」走确认；发布内容、切换置顶/推荐、自动打标签由 AI 直接执行。
 * 创建草稿例外：草稿不公开，且后续步骤需要拿到新 id（见 §AI 分级确认）。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use Abilities;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Yikai\Tests\TestCase;

#[RunTestsInSeparateProcesses]
final class AiAbilityStagingTest extends TestCase
{
    /** 会改站点、且允许 AI 直接执行的能力（白名单，新增须在此说明理由）。 */
    private const DIRECT_WRITES = [
        'cms_create_article_draft', // 草稿不公开；后续「发布」需要新 id，发布本身仍走确认
    ];

    protected function schemaSql(): array
    {
        return [
            "CREATE TABLE channels (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER DEFAULT 0, name TEXT, slug TEXT, type TEXT DEFAULT 'list', status INTEGER DEFAULT 1)",
            "CREATE TABLE contents (
                id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER DEFAULT 1, type TEXT DEFAULT 'article',
                title TEXT NOT NULL, slug TEXT DEFAULT '', summary TEXT DEFAULT '', content TEXT DEFAULT '', tags TEXT DEFAULT '',
                status INTEGER DEFAULT 0, publish_time INTEGER DEFAULT 0, updated_at INTEGER DEFAULT 0,
                is_top INTEGER DEFAULT 0, is_recommend INTEGER DEFAULT 0, is_hot INTEGER DEFAULT 0,
                views INTEGER DEFAULT 0, lang TEXT DEFAULT 'zh-CN', deleted_at INTEGER DEFAULT NULL
            )",
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!function_exists('hasPermission')) {
            // 独立进程：超管身份（行级权限另有契约测试覆盖）
            eval('function hasPermission(string $p): bool { return true; }
                  function isSuperAdmin(): bool { return true; }
                  function adminLog(...$args): void {}
                  function aiService() { throw new RuntimeException("AI must not be called when tags are pinned"); }');
        }
        // v2.1 按能力授权：能力只给已登录的后台用户（权限键之外先看登录态）
        $_SESSION['admin_id'] = 1;
        require_once ROOT_PATH . '/includes/permissions.php';
        require_once ROOT_PATH . '/includes/Abilities.php';
        require_once ROOT_PATH . '/includes/abilities/cms_basics.php';
        require_once ROOT_PATH . '/includes/abilities/cms_admin.php';
        $this->insertRow('channels', ['name' => 'News', 'slug' => 'news']);
        $this->insertRow('contents', ['title' => 'Draft post', 'status' => 0, 'publish_time' => 0, 'tags' => 'old']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_id']);
        parent::tearDown();
    }

    public function testEveryWriteAbilityIsStagedUnlessExplicitlyAllowed(): void
    {
        $writes = ['cms_publish_content', 'cms_set_content_flags', 'cms_auto_tag_content', 'cms_update_setting'];
        foreach ($writes as $name) {
            self::assertTrue(Abilities::has($name), $name);
            self::assertTrue(Abilities::isMutating($name), "$name must be staged for confirmation");
            self::assertNotNull(Abilities::get($name)['revert'] ?? null, "$name must be undoable");
        }
        foreach (array_keys(Abilities::all()) as $name) {
            if (preg_match('/publish|update|set_|delete|auto_tag|create/', $name) === 1 && !in_array($name, self::DIRECT_WRITES, true)) {
                self::assertTrue(Abilities::isMutating($name), "$name looks like a write: stage it or whitelist it with a reason");
            }
        }
    }

    public function testPublishPreviewsAppliesAndReverts(): void
    {
        $preview = Abilities::previewChange('cms_publish_content', ['id' => 1]);
        self::assertTrue($preview['success']);
        self::assertSame(['status' => 0, 'publish_time' => 0], $preview['preview']['before']);
        self::assertStringContainsString('Draft post', $preview['preview']['summary']);
        self::assertSame(0, (int) db()->fetchColumn('SELECT status FROM contents WHERE id = 1'), 'preview never writes');

        self::assertTrue(Abilities::execute('cms_publish_content', ['id' => 1])['success']);
        self::assertSame(1, (int) db()->fetchColumn('SELECT status FROM contents WHERE id = 1'));

        self::assertTrue(Abilities::revertChange('cms_publish_content', $preview['preview']['before'], ['id' => 1])['success']);
        self::assertSame(0, (int) db()->fetchColumn('SELECT status FROM contents WHERE id = 1'));
    }

    public function testFlagsPreviewOnlyRequestedFlagsAndRevert(): void
    {
        $input = ['id' => 1, 'is_top' => 1, 'is_hot' => 1];
        $preview = Abilities::previewChange('cms_set_content_flags', $input);
        self::assertSame(['is_top' => 0, 'is_hot' => 0], $preview['preview']['before']);
        self::assertSame(['is_top' => 1, 'is_hot' => 1], $preview['preview']['after']);

        self::assertTrue(Abilities::execute('cms_set_content_flags', $input)['success']);
        self::assertSame([1, 1, 0], array_map('intval', array_values((array) db()->fetchOne('SELECT is_top, is_hot, is_recommend FROM contents WHERE id = 1'))));

        Abilities::revertChange('cms_set_content_flags', $preview['preview']['before'], $input);
        self::assertSame([0, 0], array_map('intval', array_values((array) db()->fetchOne('SELECT is_top, is_hot FROM contents WHERE id = 1'))));
        // contents 没有 is_new 列：不再出现在输入契约里（原来传入即 SQL 报错）
        self::assertArrayNotHasKey('is_new', Abilities::get('cms_set_content_flags')['input_schema']['properties']);
    }

    public function testAutoTagPinsTheReviewedTagsAndReverts(): void
    {
        $preview = Abilities::previewChange('cms_auto_tag_content', ['id' => 1, 'tags' => ' #cnc， 精密加工、"export" ']);
        self::assertTrue($preview['success']);
        self::assertSame('old', $preview['preview']['before']);
        self::assertSame('cnc,精密加工,export', $preview['preview']['after']);
        // 预览固定入参：确认时执行的是这份（不会再调一次 AI 得到另一组标签）
        self::assertSame(['id' => 1, 'tags' => 'cnc,精密加工,export'], $preview['preview']['input']);

        self::assertTrue(Abilities::execute('cms_auto_tag_content', $preview['preview']['input'])['success']);
        self::assertSame('cnc,精密加工,export', db()->fetchColumn('SELECT tags FROM contents WHERE id = 1'));
        Abilities::revertChange('cms_auto_tag_content', 'old', ['id' => 1]);
        self::assertSame('old', db()->fetchColumn('SELECT tags FROM contents WHERE id = 1'));
    }

    public function testAgentStagesThePinnedInput(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/AiService.php');
        self::assertStringContainsString("\$stagedArgs = is_array(\$pv['input'] ?? null) ? \$pv['input'] : \$args;", $source);
        self::assertStringContainsString('AiStaging::add($stageSetId, $fnName, $stagedArgs, $pv)', $source);
    }
}
