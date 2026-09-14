<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** E03：受保护文档的真实保存入口在“收费但无模块”策略下的允许/拒绝两侧。 */
final class BloxProtectedSaveTest extends TestCase
{
    public function testBasicEditsSaveWhileProtectedChangesAreRejected(): void
    {
        $lines = [];
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/blox-protected-save-probe.php') . ' 2>&1',
            $lines,
            $exit
        );
        self::assertSame(0, $exit, implode("\n", $lines));
        $result = json_decode((string) end($lines), true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($result['policy_denies_query_loop']);
        self::assertSame('saved', $result['page_basic_edit']);
        self::assertTrue($result['page_binding_preserved']);
        self::assertTrue($result['page_text_saved']);
        self::assertSame('conflict', $result['page_missing_revision']);
        self::assertSame('conflict', $result['page_stale_revision']);
        self::assertSame('protected', $result['page_change_binding']);
        self::assertSame('protected', $result['page_drop_binding']);
        self::assertTrue($result['page_draft_unchanged_after_rejections']);
        self::assertNotSame('saved', $result['fresh_page_new_binding']);

        self::assertSame('conflict', $result['home_missing_revision']);
        self::assertSame('protected', $result['home_change_binding']);
        self::assertSame('saved', $result['home_basic_edit']);
        self::assertTrue($result['home_binding_preserved']);

        self::assertSame('saved', $result['loop_child_text_edit']);
        self::assertTrue($result['loop_child_text_saved']);
        self::assertSame('protected', $result['loop_child_binding_change']);

        self::assertSame('protected', $result['restore_changed_binding']);
        self::assertSame('protected', $result['restore_html_only_drops_binding']);
        self::assertSame('saved', $result['restore_basic_version']);
        self::assertTrue($result['restore_binding_preserved']);

        self::assertSame('saved', $result['template_basic_edit']);
        self::assertSame('protected', $result['template_change_binding']);
        self::assertSame('license', $result['template_import_without_baseline']);
        self::assertSame('saved', $result['popup_basic_edit']);

        self::assertSame('saved', $result['preview_basic_edit']);
        self::assertSame('protected', $result['preview_change_binding']);
        self::assertSame('license', $result['preview_without_baseline']);
    }
}
