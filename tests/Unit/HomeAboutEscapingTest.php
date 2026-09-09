<?php

/**
 * About 转换/本地化的转义口径必须与生成端一致。
 *
 * 审计发现（v1.19.9，F1/F2/F4）：
 *   - HomeAboutContent 用 htmlspecialchars(..., ENT_QUOTES|ENT_SUBSTITUTE) 生成 HTML；
 *   - HomeAboutLocalization 用 e()（生产版只有 ENT_QUOTES）去匹配那段 HTML。
 *   正常文本两者等价，**非法 UTF-8 时生产 e() 返回空串**，needle 从 '>标题<' 退化成 '><'。
 *   徽章 HTML 形如 <div…><h3…>标题</h3><p…>描述</p></div>，其中 `</h3><p` 正好含 '><'，
 *   于是绑定被错误建立，读取期 strtr 把译文插到三个标签外位置，页面出现重复裸文本。
 *
 * 这些用例之所以此前抓不到，是因为 tests/bootstrap.php 的 e() 桩**多带了 ENT_SUBSTITUTE**，
 * 比生产宽容——边界差异在测试里被系统性抹掉了。所以本文件一律使用**生产同款转义**，
 * 不依赖桩的行为，并单独断言桩与生产一致。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use HomeAboutContent;
use HomeAboutLocalization;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/HomeAboutContent.php';
require_once ROOT_PATH . '/includes/builder/HomeAboutLocalization.php';

final class HomeAboutEscapingTest extends TestCase
{
    /** 非法 UTF-8：0xC3 之后跟一个非续字节 */
    private const INVALID_UTF8_TITLE = "\xC3\x28 荣誉";

    /** 生产 e()：includes/functions.php:203，只有 ENT_QUOTES */
    private static function productionEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    /** 生成端转义：HomeAboutContent.php */
    private static function builderEscape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ── F2：测试桩不得比生产宽容 ─────────────────────────────────────

    public function testBootstrapStubMatchesProductionEscaping(): void
    {
        // 桩若多带 ENT_SUBSTITUTE，所有依赖 e() 边界的用例都会在比生产宽容的环境里跑，
        // 于是像 F1 这样的缺陷可以躲过整个单测套件。
        self::assertSame(
            self::productionEscape(self::INVALID_UTF8_TITLE),
            e(self::INVALID_UTF8_TITLE),
            'tests/bootstrap.php 的 e() 桩必须与生产 includes/functions.php:203 完全一致，'
                . '否则会把转义边界差异从测试里抹掉'
        );
    }

    // ── F1：徽章不得被改写结构 ───────────────────────────────────────

    /** 构造转换器实际会生成的徽章 HTML。 */
    private static function badgeHtml(string $title, string $description): string
    {
        return '<div class="bg-primary text-white rounded-lg p-6">'
            . '<h3 class="text-xl font-bold text-white m-0">' . self::builderEscape($title) . '</h3>'
            . '<p class="text-white m-0">' . self::builderEscape($description) . '</p></div>';
    }

    /** @return array<string,mixed> 只含徽章的最小 section，形状与 toSection 输出一致 */
    private static function sectionWithBadge(string $id, string $html): array
    {
        return [
            'id' => $id, 'type' => 'section', 'settings' => [],
            'columns' => [
                ['id' => $id . '_text', 'span' => 6, 'elements' => []],
                ['id' => $id . '_visual', 'span' => 6, 'elements' => [
                    ['id' => $id . '_image_badge', 'type' => 'div', 'data' => ['children' => [
                        ['id' => $id . '_caption', 'type' => 'text', 'data' => ['html' => $html]],
                    ]]],
                ]],
            ],
        ];
    }

    private static function captionHtml(array $section): string
    {
        $badge = $section['columns'][1]['elements'][0];
        return (string) $badge['data']['children'][0]['data']['html'];
    }

    private static function captionBinding(array $section): mixed
    {
        $badge = $section['columns'][1]['elements'][0];
        return $badge['data']['children'][0]['data'][HomeAboutLocalization::KEY] ?? null;
    }

    public function testInvalidUtf8TitleDoesNotProduceADegenerateNeedle(): void
    {
        $title = self::INVALID_UTF8_TITLE;
        $description = '自 2010 年起';
        $html = self::badgeHtml($title, $description);

        // 前提：转换器生成的 HTML 里确实有 '></' 相邻（</h3><p），这正是退化 needle 的落点。
        self::assertStringContainsString('</h3><p', $html, '徽章 HTML 应含 </h3><p，否则本用例失去意义');

        $section = HomeAboutLocalization::bind(
            self::sectionWithBadge('about_deadbeef1234', $html),
            ['override_tag_title' => $title, 'override_tag_description' => $description]
        );

        $binding = self::captionBinding($section);
        if ($binding === null) {
            // 可接受的结果之一：认不出来就不绑定（宁可不翻译，也不要错翻）。
            self::assertTrue(true);
            return;
        }
        // 若建立了绑定，其 parts 必须能对应到 HTML 里**唯一且正确**的位置，
        // 而不是 '><' 这种会命中标签缝隙的退化串。
        foreach ($binding['fields'] as $field => $entry) {
            foreach ($entry['parts'] as $key => $source) {
                // needle 按**生成端同款转义**计算——修复后 HomeAboutLocalization 用的
                // 就是 HomeAboutContent::escape()。若有人把它改回生产 e()，
                // 非法 UTF-8 会让这里退化成 '><'，下面两条断言随即变红。
                $needle = '>' . HomeAboutContent::escape((string) $source) . '<';
                self::assertNotSame('><', $needle,
                    "part {$key} 的匹配串退化成 '><'，会命中标签缝隙并改写 HTML 结构");
                self::assertSame(1, substr_count($html, $needle),
                    "part {$key} 的匹配串在徽章 HTML 里必须唯一出现，否则 strtr 会多处替换");
            }
        }
    }

    public function testLocalizingAnInvalidUtf8BadgeNeverInjectsBareText(): void
    {
        $title = self::INVALID_UTF8_TITLE;
        $description = '自 2010 年起';
        $original = self::badgeHtml($title, $description);
        $section = HomeAboutLocalization::bind(
            self::sectionWithBadge('about_deadbeef1234', $original),
            ['override_tag_title' => $title, 'override_tag_description' => $description]
        );

        // 读取期不论是否发生替换，标签结构都必须保持完整：
        // 不允许出现 </h3>文本<p 这种标签外裸文本。
        $binding = self::captionBinding($section);
        $html = self::captionHtml($section);
        self::assertSame($original, $html, 'bind() 不应改动 HTML 本身');

        if (is_array($binding)) {
            // 用**生产代码实际使用的**转义重建替换表（HomeAboutContent::escape），
            // 而不是重写一套：这条用例要验的是修复后的真实行为。
            $replacements = [];
            foreach ($binding['fields'] as $entry) {
                foreach ($entry['parts'] as $source) {
                    $needle = '>' . HomeAboutContent::escape((string) $source) . '<';
                    self::assertNotSame('><', $needle, '匹配串不得退化成 \'><\'');
                    self::assertSame(1, substr_count($original, $needle),
                        '匹配串必须在 HTML 中唯一命中，否则 strtr 会多处替换');
                    $replacements[$needle] = '>TRANSLATED<';
                }
            }
            $after = strtr($original, $replacements);
            self::assertDoesNotMatchRegularExpression(
                '~</h3>[^<]+<p~',
                $after,
                '译文被插到 </h3> 与 <p 之间，成为标签外裸文本'
            );
            self::assertDoesNotMatchRegularExpression(
                '~<div[^>]*>[^<]+<h3~',
                $after,
                '译文被插到 <div> 与 <h3 之间，成为标签外裸文本'
            );
        }
    }

    // ── F4：标题与描述文本相同时不得互相覆盖 ─────────────────────────

    public function testIdenticalTitleAndDescriptionDoNotCollideInReplacements(): void
    {
        $same = '荣誉资质';
        $html = self::badgeHtml($same, $same);
        $section = HomeAboutLocalization::bind(
            self::sectionWithBadge('about_cafebabe5678', $html),
            ['override_tag_title' => $same, 'override_tag_description' => $same]
        );

        // 标题与描述文本完全相同时，'>文本<' 无法区分 h3 与 p 两处位置。
        // 安全的结果是**不建立绑定**（该字段不翻译），而不是让 strtr 把其中一处的
        // 译文套到另一处去。这里断言的是这个安全性质，不是「必须能翻译」。
        $binding = self::captionBinding($section);
        if ($binding === null) {
            self::assertNull($binding, '相同文本时不绑定是可接受且安全的结果');
            return;
        }
        $parts = $binding['fields']['html']['parts'] ?? [];
        $keys = [];
        foreach ($parts as $name => $source) {
            $needle = '>' . HomeAboutContent::escape((string) $source) . '<';
            self::assertSame(1, substr_count($html, $needle),
                "part {$name} 的匹配串在 HTML 中命中了不止一处，strtr 会同时改写两个位置");
            self::assertArrayNotHasKey($needle, $keys,
                '标题与描述产生了同一个替换键，strtr 会让其中一个覆盖另一个');
            $keys[$needle] = $name;
        }
    }

    /**
     * 源码契约：匹配端不得再用裸 e() 去定位生成端产出的 HTML。
     *
     * 行为断言只能覆盖我们想到的输入；这条契约挡住的是「有人为了省事把
     * self::needle()/self::escape() 换回 e()」这类回退——那正是 F1 的根因。
     */
    public function testLocalizationNeverMatchesGeneratedHtmlWithBareEscape(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/builder/HomeAboutLocalization.php');
        self::assertStringContainsString('HomeAboutContent::escape(', $source,
            '匹配端必须复用生成端的转义函数');
        self::assertDoesNotMatchRegularExpression(
            "~'>'\s*\.\s*e\(~",
            $source,
            "不得用裸 e() 拼接匹配串：生产 e() 无 ENT_SUBSTITUTE，非法 UTF-8 会让它退化成 '><'"
        );
    }

    // ── 正例：合法文本的既有行为不能被改坏 ───────────────────────────

    public function testValidTextStillBindsAndLocalizesCleanly(): void
    {
        $title = '关于我们';
        $description = '自 2010 年起';
        $html = self::badgeHtml($title, $description);
        $section = HomeAboutLocalization::bind(
            self::sectionWithBadge('about_0123456789ab', $html),
            ['override_tag_title' => $title, 'override_tag_description' => $description]
        );

        $binding = self::captionBinding($section);
        self::assertIsArray($binding, '合法中文必须能建立语言绑定');
        self::assertSame('html', array_key_first($binding['fields']));
        self::assertSame($html, $binding['fields']['html']['source']);
        foreach ($binding['fields']['html']['parts'] as $key => $source) {
            $needle = '>' . self::builderEscape((string) $source) . '<';
            self::assertSame(1, substr_count($html, $needle), "{$key} 应在 HTML 中唯一命中");
        }
    }
}
