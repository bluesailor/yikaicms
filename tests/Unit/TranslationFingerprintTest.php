<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/TranslationState.php';

/**
 * 评审 F08：指纹按解析后的 JSON 算，对象键的顺序不算改动，列表顺序仍算；
 * 换算法后 2.2.1 以前存下的旧指纹仍能比对，升级不会把已有译文全部判成过期或人改过。
 */
final class TranslationFingerprintTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function shape(string $specs): array
    {
        return ['title' => 'Pump', 'summary' => 'S', 'content' => '<p>B</p>', 'specs' => $specs];
    }

    public function testObjectKeyOrderDoesNotChangeTheFingerprintButListOrderDoes(): void
    {
        $a = TranslationState::fingerprint(self::shape('{"a":1,"b":{"y":2,"x":[3,4]}}'));
        self::assertSame($a, TranslationState::fingerprint(self::shape('{"b":{"x":[3,4],"y":2},"a":1}')), '对象键（含嵌套）换顺序不算改动');
        self::assertNotSame($a, TranslationState::fingerprint(self::shape('{"a":1,"b":{"y":2,"x":[4,3]}}')), '列表顺序算改动');
        self::assertNotSame(
            TranslationState::fingerprint(self::shape('[{"name":"压力","value":"10"},{"name":"流量","value":"5"}]')),
            TranslationState::fingerprint(self::shape('[{"name":"流量","value":"5"},{"name":"压力","value":"10"}]')),
            '参数表的行顺序是内容的一部分'
        );
        self::assertSame(
            TranslationState::fingerprint(self::shape('[{"name":"压力","value":"10"}]')),
            TranslationState::fingerprint(self::shape('[{"value":"10","name":"压力"}]'))
        );
    }

    public function testNewFingerprintFitsTheColumnAndIsDistinguishableFromTheOldFormat(): void
    {
        $fp = TranslationState::fingerprint(self::shape('{"a":1}'));
        self::assertSame(64, strlen($fp), '列宽 varchar(64)');
        self::assertStringStartsWith('v2', $fp);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', TranslationState::legacyFingerprint(self::shape('{"a":1}')), '旧格式是纯十六进制');
    }

    public function testFingerprintsStoredBeforeTheUpgradeStillMatchUnchangedContent(): void
    {
        $shape = self::shape('{"b":2,"a":1}');
        $print = TranslationState::fingerprint($shape) . '|' . TranslationState::legacyFingerprint($shape);   // 与 rowPrint() 相同的形状
        $legacyStored = TranslationState::legacyFingerprint($shape);
        self::assertTrue(TranslationState::same($legacyStored, $print), '2.2.1 存下的旧指纹：内容没变就仍然一致');
        self::assertTrue(TranslationState::same(TranslationState::fingerprint($shape), $print), '新存的指纹');
        self::assertFalse(TranslationState::same($legacyStored, TranslationState::fingerprint(self::shape('{"b":3,"a":1}')) . '|'
            . TranslationState::legacyFingerprint(self::shape('{"b":3,"a":1}'))), '内容真的变了');
        self::assertFalse(TranslationState::same('', $print));

        $link = ['state' => 'machine', 'source_fp' => $legacyStored, 'target_fp' => $legacyStored, 'proposal_json' => null];
        $status = TranslationState::status($link, $print, $print);
        self::assertFalse($status['outdated'], '升级后已有译文不会全部变成「原文已改」');
        self::assertFalse($status['edited'], '也不会全部变成「人改过」而不再自动更新');
    }
}
