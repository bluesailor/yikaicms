<?php
/**
 * 边界样本（E10）的契约。
 *
 * 最要紧的一条：这些样本**绝不能落库**。它们是内存里拼的数组，id 恒为 0，
 * 任何把 id 当真实记录用的路径都会立刻露馅——所以这条单独测。
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxEdgeSamples;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

final class BloxEdgeSamplesTest extends TestCase
{
    /** 合成样本的 id 必须是 0：真实记录都是正数，混不了。 */
    public function testSyntheticSamplesCarryNoRecordIdentity(): void
    {
        foreach (BloxEdgeSamples::keys() as $key) {
            self::assertSame(0, BloxEdgeSamples::product($key, 'zh-CN')['id'], $key);
            self::assertSame(0, BloxEdgeSamples::article($key, 'zh-CN')['id'], $key);
        }
    }

    /** 字段形状要和真实记录一致，否则预览不出真实的崩法。 */
    public function testSamplesCarryTheSameFieldsAsRealRecords(): void
    {
        $product = BloxEdgeSamples::product(BloxEdgeSamples::EMPTY, 'zh-CN');
        foreach (['title', 'subtitle', 'summary', 'model', 'price', 'cover', 'images', 'specs', 'lang'] as $field) {
            self::assertArrayHasKey($field, $product, 'product.' . $field);
        }
        $article = BloxEdgeSamples::article(BloxEdgeSamples::EMPTY, 'zh-CN');
        foreach (['title', 'subtitle', 'summary', 'author', 'cover', 'content', 'lang', 'type'] as $field) {
            self::assertArrayHasKey($field, $article, 'article.' . $field);
        }
        self::assertSame('article', $article['type']);
    }

    /** 空样本必须真的空——否则"看空值分支"这件事根本没发生。 */
    public function testEmptySampleLeavesContentFieldsBlank(): void
    {
        $product = BloxEdgeSamples::product(BloxEdgeSamples::EMPTY, 'zh-CN');
        foreach (['subtitle', 'summary', 'model', 'cover', 'images', 'specs'] as $field) {
            self::assertSame('', $product[$field], 'product.' . $field . ' 应为空');
        }
        // 标题给一个占位文案：完全无标题的话作者会以为预览坏了
        self::assertNotSame('', $product['title']);
    }

    /** 长样本要真的长，否则测不出换行问题。 */
    public function testLongSampleActuallyOverflows(): void
    {
        $product = BloxEdgeSamples::product(BloxEdgeSamples::LONG, 'zh-CN');
        self::assertGreaterThan(60, mb_strlen((string) $product['title']));
        self::assertGreaterThan(200, mb_strlen((string) $product['summary']));

        $article = BloxEdgeSamples::article(BloxEdgeSamples::LONG, 'zh-CN');
        self::assertGreaterThan(60, mb_strlen((string) $article['title']));
    }

    /** 饱满样本的参数表要能解析，且条数足以压出版面问题。 */
    public function testRichSampleProducesParsableSpecs(): void
    {
        $product = BloxEdgeSamples::product(BloxEdgeSamples::RICH, 'zh-CN');
        $specs = json_decode((string) $product['specs'], true);
        self::assertIsArray($specs);
        self::assertGreaterThanOrEqual(10, count($specs));
        self::assertArrayHasKey('name', $specs[0]);
        self::assertArrayHasKey('value', $specs[0]);
    }

    /** 只认这三个键：其它输入一律走真实记录路径，不能被当成样本键。 */
    public function testOnlyKnownKeysAreTreatedAsEdgeSamples(): void
    {
        foreach (BloxEdgeSamples::keys() as $key) {
            self::assertTrue(BloxEdgeSamples::isEdgeKey($key));
        }
        foreach (['', '0', '12', 'EMPTY', 'empty ', 'drop', '../etc'] as $notKey) {
            self::assertFalse(BloxEdgeSamples::isEdgeKey($notKey), var_export($notKey, true));
        }
    }

    /** 语言按调用方传入：样本不该锁死在中文。 */
    public function testLanguageFollowsTheCaller(): void
    {
        self::assertSame('ja', BloxEdgeSamples::product(BloxEdgeSamples::RICH, 'ja')['lang']);
        self::assertSame('en', BloxEdgeSamples::article(BloxEdgeSamples::LONG, 'en')['lang']);
    }
}
