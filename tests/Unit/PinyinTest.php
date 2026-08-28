<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/Pinyin.php';

/**
 * 自建拼音词库的行为契约。
 *
 * 词库由 tools/build_pinyin_dict.php 从 rime-pinyin-simp（Apache-2.0）生成，
 * 人工修正在 includes/pinyin/overrides.php。改词库或改生成器后本测试必须仍全绿。
 */
final class PinyinTest extends TestCase
{
    public function testDictionaryIsPresent(): void
    {
        Pinyin::flush();
        self::assertTrue(Pinyin::available(), '拼音词库缺失：includes/pinyin/chars.php 未随包发出？');
    }

    public function testBasicConversion(): void
    {
        self::assertSame('guan-yu-wo-men', Pinyin::slug('关于我们'));
        self::assertSame('xin-wen-zi-xun', Pinyin::slug('新闻资讯'));
        self::assertSame('chan-pin-zhong-xin', Pinyin::slug('产品中心'));
    }

    /**
     * 多音字必须靠词组表纠正——这是词库存在的理由，退化成逐字默认读音就会读错。
     * @dataProvider polyphonicCases
     */
    public function testPolyphonicCharactersUsePhraseReading(string $text, string $expected): void
    {
        self::assertSame($expected, Pinyin::slug($text), "多音字读错: {$text}");
    }

    /** @return list<array{0:string,1:string}> */
    public static function polyphonicCases(): array
    {
        return [
            ['重庆', 'chong-qing'],       // 重 chóng 而非 zhòng
            ['银行', 'yin-hang'],         // 行 háng 而非 xíng
            ['长城', 'chang-cheng'],      // 长 cháng 而非 zhǎng
            ['会计', 'kuai-ji'],          // 会 kuài 而非 huì
            ['西安', 'xi-an'],
            ['密码重置', 'mi-ma-chong-zhi'],
            ['表单模板', 'biao-dan-mu-ban'], // 模 mú（overrides.php 修正）
            ['银行行长', 'yin-hang-hang-zhang'],
        ];
    }

    public function testAsciiRunsAreKeptWhole(): void
    {
        // 旧实现会把大写整段删掉（wan-zheng-de），这里必须保留
        self::assertSame('wan-zheng-de-api', Pinyin::slug('完整的API'));
        self::assertSame('shi-yong-yu-windows', Pinyin::slug('适用于Windows'));
        self::assertSame('2026-xin-pin', Pinyin::slug('2026新品'));
    }

    public function testPunctuationAndUnknownCharactersAreDropped(): void
    {
        self::assertSame('ni-hao-shi-jie', Pinyin::slug('你好，世界！'));
        self::assertSame('', Pinyin::slug('———'));
        self::assertSame('', Pinyin::slug(''));
    }

    public function testDelimiterIsHonoured(): void
    {
        self::assertSame('guan_yu_wo_men', Pinyin::slug('关于我们', '_'));
        self::assertSame('guanyuwomen', Pinyin::slug('关于我们', ''));
    }

    public function testOutputIsAlwaysUrlSafe(): void
    {
        foreach (['重庆火锅底料', '完整的API', '你好，世界！', '2026新品', '表单模板'] as $text) {
            $slug = Pinyin::slug($text);
            self::assertMatchesRegularExpression(
                '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $slug,
                "slug 含非法字符: {$text} => {$slug}"
            );
        }
    }

    /** overrides.php 的音节数必须与字数一致，否则会串位 */
    public function testOverrideEntriesAreWellFormed(): void
    {
        $overrides = include ROOT_PATH . '/includes/pinyin/overrides.php';
        self::assertIsArray($overrides);
        foreach ($overrides as $word => $reading) {
            $syllables = explode(' ', trim((string) $reading));
            self::assertSame(
                mb_strlen((string) $word),
                count($syllables),
                "overrides.php 中 {$word} 的音节数与字数不符"
            );
            foreach ($syllables as $syllable) {
                self::assertMatchesRegularExpression('/^[a-z]+$/', $syllable, "非法音节: {$syllable}");
            }
        }
    }

    public function testGeneratedDictionariesStayInSync(): void
    {
        $chars = include ROOT_PATH . '/includes/pinyin/chars.php';
        $phrases = include ROOT_PATH . '/includes/pinyin/phrases.php';
        self::assertIsArray($chars);
        self::assertIsArray($phrases);
        self::assertGreaterThan(300, count($chars), '音节数异常，chars.php 可能被截断');
        self::assertGreaterThan(500, count($phrases), '词组表异常，phrases.php 可能被截断');
        foreach ($phrases as $word => $reading) {
            self::assertLessThanOrEqual(
                4,
                mb_strlen((string) $word),
                "词组超过最长匹配窗口，Pinyin::MAX_PHRASE 需同步调整: {$word}"
            );
        }
    }
}
