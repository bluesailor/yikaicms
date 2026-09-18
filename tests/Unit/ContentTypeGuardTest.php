<?php
/**
 * 固定类型入口的行级权限守卫（2026-09-17 发版前审计 F01）。
 *
 * 文章 / 案例 / 单页 / 下载同住 contents 表。入口过去只检查模块级权限，
 * 于是只有 edit_article 的投稿者能经 article.php 下架、改写、软删除案例。
 * 这里锁住两件事：守卫本身的判定，以及每个入口确实走了守卫。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContentTypeGuardTest extends TestCase
{
    /** @return array<string,array{ok:bool,value?:mixed,denied?:string}> */
    private function probe(array $permissions): array
    {
        $cmd = escapeshellarg(PHP_BINARY)
            . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/content-type-guard-probe.php')
            . ' ' . escapeshellarg(implode(',', $permissions));
        $lines = [];
        $exit = 0;
        exec($cmd, $lines, $exit);
        self::assertSame(0, $exit, "探针执行失败：\n" . implode("\n", $lines));
        $decoded = json_decode(implode("\n", $lines), true);
        self::assertIsArray($decoded, '探针输出不是 JSON：' . implode("\n", $lines));
        return $decoded;
    }

    public function testArticleEntryOnlyTouchesArticleRows(): void
    {
        $r = $this->probe(['edit_article', 'delete_article']);

        self::assertSame([1, 2], $r['own_type']['value']);
        self::assertSame([1], $r['duplicate_ids']['value'], '重复 id 应去重');
        self::assertSame([1], $r['delete_mode']['value']);
        self::assertSame('A1', $r['row_own_type']['value']);

        // 越界：混批、纯案例、单页、以及单条取行
        foreach (['mixed_batch', 'other_type', 'page_type', 'row_other_type'] as $case) {
            self::assertFalse($r[$case]['ok'], "{$case} 应被拒绝");
            self::assertSame('perm_denied:403', $r[$case]['denied']);
        }

        // 不存在的 id 没有行可操作；批量里出现时不影响其余项
        self::assertSame([], $r['missing_id']['value']);
        self::assertSame([], $r['empty_batch']['value']);
        self::assertSame([], $r['zero_and_junk']['value']);
        self::assertFalse($r['row_missing']['ok'], '单条取行遇到不存在的 id 应拒绝');

        // 没有 edit_case 就不能用案例入口
        self::assertFalse($r['case_entry']['ok']);
        self::assertSame('missing:edit_case', $r['case_entry']['denied']);
    }

    public function testEditPermissionAloneCannotDelete(): void
    {
        $r = $this->probe(['edit_article']);
        self::assertFalse($r['delete_mode']['ok']);
        self::assertSame('missing:delete_article', $r['delete_mode']['denied']);
    }

    /** 超管也不能拿文章入口改案例：固定类型入口不做类型转换，避免 type 被写坏。 */
    public function testSuperAdminStillCannotCrossTypesThroughAFixedEntry(): void
    {
        $r = $this->probe(['*']);
        self::assertSame([1, 2], $r['own_type']['value']);
        self::assertSame([3], $r['case_entry']['value']);
        self::assertFalse($r['mixed_batch']['ok']);
        self::assertFalse($r['other_type']['ok']);
    }

    /** 回收站里的记录：批量跳过，单条给受控错误，都不该以 TypeError/500 收场（复审 R06）。 */
    public function testTrashedRowsAreSkippedInsteadOfCrashing(): void
    {
        $r = $this->probe(['edit_article', 'delete_article']);

        self::assertSame([1], $r['trashed_batch']['value'], '批量应跳过回收站里的行');
        self::assertSame([], $r['trashed_delete']['value']);
        self::assertFalse($r['trashed_row']['ok']);
        self::assertSame('admin_no_data:400', $r['trashed_row']['denied'], '单条应是受控错误而不是 TypeError');
    }

    /** 翻译创建按源记录的真实类型判权，且固定类型入口不给别的类型开口子（复审 R01）。 */
    public function testTranslationCreationIsAuthorisedByTheSourceRowType(): void
    {
        $r = $this->probe(['edit_article', 'delete_article']);

        self::assertSame('allowed', $r['tr_article']['value']);
        self::assertSame('perm_denied:403', $r['tr_case_bound']['denied'], '文章入口不得为案例建译文');
        self::assertSame('missing:edit_case', $r['tr_case_shared']['denied']);
        self::assertSame('missing:edit_page', $r['tr_page_shared']['denied']);
        self::assertSame('missing:edit_product', $r['tr_products']['denied']);
        self::assertSame('missing:*', $r['tr_channels']['denied'], '栏目翻译要超管');
        self::assertSame('missing:*', $r['tr_unknown_tbl']['denied'], '未登记的表默认收紧');

        // 超管也不能借文章入口给案例建译文
        $super = $this->probe(['*']);
        self::assertSame('perm_denied:403', $super['tr_case_bound']['denied']);
        self::assertSame('allowed', $super['tr_case_shared']['value']);

        $handler = $this->source('admin/includes/translate_action.php');
        self::assertStringContainsString(
            "requireTranslationPermission(\$table, \$src, (string) (\$langSwitcher['content_type'] ?? ''));",
            $handler
        );
        // 判权必须排在 AI 调用与写库之前
        self::assertLessThan(
            (int) strpos($handler, 'aiTranslateFields('),
            (int) strpos($handler, 'requireTranslationPermission('),
            '判权要在调用 AI 之前'
        );
        self::assertStringContainsString("\$newData['status'] = 0;", $handler, '译文默认落草稿');
        self::assertStringContainsString("'content_type' => 'article'", $this->source('admin/article_edit.php'));
    }

    /** 未登记的类型不能落库，后台列表也不再原样回显类型（复审 R02）。 */
    public function testUnknownContentTypesAreRejectedAndEscaped(): void
    {
        $r = $this->probe(['edit_article']);
        self::assertTrue($r['type_builtin']['value']);
        self::assertTrue($r['type_custom']['value'], '后台登记过的自定义模型仍可用');
        self::assertFalse($r['type_injection']['value']);
        self::assertFalse($r['type_unknown']['value']);
        self::assertSame('perm_denied:403', $r['perm_injection']['denied']);

        // delete-only 角色不能借自定义模型创建或改写内容
        $deleteOnly = $this->probe(['delete_article']);
        self::assertSame('missing:edit_article', $deleteOnly['perm_custom']['denied']);

        self::assertStringContainsString(
            "if (!isRegisteredContentType((string) \$data['type'])) {",
            $this->source('admin/content_edit.php')
        );
        self::assertStringContainsString(
            "echo e(\$contentTypes[\$item['type']] ?? \$item['type']);",
            $this->source('admin/content.php')
        );
        self::assertStringNotContainsString(
            "echo \$channelTypes[\$ch['type']] ?? \$ch['type'];",
            $this->source('admin/channel.php')
        );
    }

    public function testEveryContentEntryPointRunsTheGuard(): void
    {
        $article = $this->source('admin/article.php');
        foreach ([
            "requireContentRowOfType(\$id, 'article', 'delete');",
            "\$src = requireContentRowOfType(\$id, 'article');",
            "requireContentRowsOfType((array) (\$_POST['ids'] ?? []), 'article', 'delete');",
            "requireContentRowsOfType((array) (\$_POST['ids'] ?? []), 'article');",
        ] as $needle) {
            self::assertStringContainsString($needle, $article);
        }
        // toggle 三连（上架 / 置顶 / 推荐）合并成一个分支，共用守卫
        self::assertStringContainsString("if (\$action === 'toggle_status' || \$action === 'toggle_top' || \$action === 'toggle_recommend') {", $article);
        self::assertStringNotContainsString('UPDATE " . DB_PREFIX . "contents SET status', $article);

        $case = $this->source('admin/case.php');
        self::assertStringContainsString("requireContentRowOfType(\$id, 'case', 'delete');", $case);
        self::assertStringContainsString("requireContentRowsOfType((array) (\$_POST['ids'] ?? []), 'case', 'delete');", $case);
        self::assertStringContainsString("requireContentRowsOfType((array) (\$_POST['ids'] ?? []), 'case');", $case);
        self::assertStringContainsString("\$src = requireContentRowOfType(\$id, 'case');", $case);
        self::assertStringContainsString("requireContentRowOfType(\$id, 'case');\n            contentModel()->updateById(\$id, [\$field => \$value]);", $case);

        // 混合列表按目标行自身类型判权
        self::assertStringContainsString("if (!canEditContentRow(\$id)) {", $this->source('admin/content.php'));

        // 固定类型编辑器保存时写死 type=article，必须先确认目标行就是文章
        self::assertStringContainsString("if ((string) \$article['type'] !== 'article') {", $this->source('admin/article_edit.php'));

        // 共享编辑器允许换类型，但要同时持有新类型的编辑权
        self::assertStringContainsString('requireContentEditPerm($newType);', $this->source('admin/content_edit.php'));
    }

    private function source(string $relative): string
    {
        $path = ROOT_PATH . '/' . $relative;
        self::assertFileExists($path);
        return str_replace("\r\n", "\n", (string) file_get_contents($path));
    }
}
