<?php
/**
 * 编辑器源码展开器本身的契约。
 *
 * 这个 helper 失灵时症状很有迷惑性：按方法名取代码的测试会报「方法不存在」，
 * 读起来像编辑器回归，实际是读取器没展开 partial。2026-09-22 就发生过两次——
 * 一次是白名单漏登记，一次是入口文件用 CRLF、正则里的 `$` 永远匹配不上，
 * 展开静默地什么都没做。所以这里直接验展开这件事。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EditorSourceExpansionTest extends TestCase
{
    public function testMethodPartialsAreExpandedAndLayoutPartialsAreNot(): void
    {
        $source = bloxEditorSourceForTest();

        // 方法 partial 的 include 必须消失，内容必须进来
        $this->assertStringNotContainsString(
            "/blox_editor/partials/condition-methods.php'",
            $source,
            '方法 partial 的 include 应当已被展开'
        );
        $this->assertStringContainsString('conditionEnsure(', $source, '展开后应当能按名字取到方法');

        // 页面结构 partial 保持不展开：按方法名切片的测试若读到模板标记，
        // `foo()` 会先命中 `:style="foo()"`，切出来的是标记而不是方法体。
        $this->assertStringContainsString(
            "/blox_editor/partials/workspace.php'",
            $source,
            '页面结构 partial 不该被展开'
        );
    }

    public function testEveryMethodPartialIsExpanded(): void
    {
        $source = bloxEditorSourceForTest();
        $this->assertSame(
            0,
            preg_match_all("~^ {12}<\?php require __DIR__ \. '/blox_editor/partials/~m", $source),
            '组件内的 partial include 一个都不该剩下'
        );
    }
}
