<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/BloxProtectedFields.php';

/** 价格方案归属 yikai-builder：能力未放行时价格方案整体冻结，其它内容照常可改。 */
final class BloxProtectedPricingTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private static function document(array $pricingData, string $heading = 'Title', bool $withPricing = true): array
    {
        $elements = [['id' => 'h1', 'type' => 'heading', 'data' => ['text' => $heading]]];
        if ($withPricing) {
            $elements[] = ['id' => 'p1', 'type' => 'pricing-table', 'data' => $pricingData];
        }
        return [['id' => 's1', 'settings' => [], 'columns' => [['id' => 'c1', 'elements' => $elements]]]];
    }

    public function testDeniedPricingIsFrozenWhileOtherContentStaysEditable(): void
    {
        $plans = ['plans' => [['name' => 'Basic', 'price' => '99']], 'billing_toggle' => '0'];
        $trusted = self::document($plans);

        BloxProtectedFields::forValidation(self::document($plans, 'New title'), $trusted, ['pricing']);

        foreach ([
            'edit plan' => self::document(['plans' => [['name' => 'Basic', 'price' => '199']], 'billing_toggle' => '0']),
            'edit toggle' => self::document(['plans' => $plans['plans'], 'billing_toggle' => '1']),
            'delete pricing' => self::document($plans, 'Title', false),
        ] as $case => $sections) {
            try {
                BloxProtectedFields::forValidation($sections, $trusted, ['pricing']);
                self::fail($case . ' should be rejected');
            } catch (RuntimeException $e) {
                self::assertNotSame('', $e->getMessage(), $case);
            }
        }

        $this->expectException(RuntimeException::class);
        BloxProtectedFields::forValidation(self::document($plans), self::document($plans, 'Title', false), ['pricing']);
    }

    public function testPricingIsUntouchedWhenTheFeatureIsAllowed(): void
    {
        $trusted = self::document(['plans' => [['name' => 'A']]]);
        $edited = self::document(['plans' => [['name' => 'B']]]);
        $result = BloxProtectedFields::forValidation($edited, $trusted, ['table']);
        self::assertSame('B', $result[0]['columns'][0]['elements'][1]['data']['plans'][0]['name']);
    }
}
