<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * normalizeSlugInput() 的行为契约（2026-09-21 客户站中文 URL 事故）。
 *
 * 落库别名只允许 a-z0-9-：中文进 URL 会被百分号编码成
 * /%E5%95%86%E4%B8%9A%E4%BF%9D%E9%99%A9.html，不可读且对 SEO 不利。
 */
final class NormalizeSlugInputTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Slug.php 是零依赖纯函数文件（只 require Pinyin），可直接加载；
        // functions.php 不行——bootstrap 已镜像其中若干函数，重复定义会致命。
        require_once ROOT_PATH . '/includes/Slug.php';
    }

    public function testChineseInputBecomesPinyinInsteadOfBeingStripped(): void
    {
        // 事故输入：旧实现把中文删光 → 退化成无意义的 item-<时间戳>
        self::assertSame('shang-ye-bao-xian', normalizeSlugInput('商业保险'));
        self::assertSame('guan-yu-wo-men', normalizeSlugInput('关于我们'));
        // 中英混排也要留下可读信息
        self::assertStringContainsString('xin-wen', normalizeSlugInput('新闻news'));
    }

    public function testAsciiInputKeepsTheLegacyWhitelistBehaviour(): void
    {
        self::assertSame('about-us', normalizeSlugInput('about-us'));
        self::assertSame('about-us', normalizeSlugInput('  About-Us  '), '大小写与首尾空白归一');
        self::assertSame('news', normalizeSlugInput('-news-'), '首尾连字符剥掉');
        self::assertSame('aboutus', normalizeSlugInput('about us'), '既有行为：ASCII 空格被删而非转连字符');
        self::assertSame('abc123', normalizeSlugInput('a/b?c#123'), 'URL 保留字一律剔除');
    }

    public function testEmptyAndUnconvertibleInputYieldsEmptyStringForCallersToFallBack(): void
    {
        self::assertSame('', normalizeSlugInput(''));
        self::assertSame('', normalizeSlugInput('   '));
        self::assertSame('', normalizeSlugInput('---'));
        self::assertSame('', normalizeSlugInput('///'));
    }

    public function testOutputIsAlwaysUrlSafe(): void
    {
        foreach (['商业保险', '产品 A/B', 'Company News', '2024年度报告', '关于我们-2024'] as $input) {
            $slug = normalizeSlugInput($input);
            if ($slug === '') {
                continue;
            }
            self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug,
                "净化结果必须是 URL 安全的别名：{$input} => {$slug}");
            self::assertSame($slug, rawurlencode($slug), '别名不得需要百分号编码');
        }
    }
}
