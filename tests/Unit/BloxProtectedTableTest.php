<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/bootstrap.php';
require_once ROOT_PATH . '/includes/builder/BloxProtectedFields.php';

/** 表格归属 yikai-builder：能力未放行时表格整体冻结，其它内容照常可改。 */
final class BloxProtectedTableTest extends TestCase
{
    /** @return list<array<string,mixed>> */
    private static function document(array $tableData, string $heading = 'Title', bool $withTable = true): array
    {
        $elements = [['id' => 'h1', 'type' => 'heading', 'data' => ['text' => $heading]]];
        if ($withTable) {
            $elements[] = ['id' => 't1', 'type' => 'table', 'data' => $tableData];
        }
        return [['id' => 's1', 'settings' => [], 'columns' => [['id' => 'c1', 'elements' => $elements]]]];
    }

    public function testDeniedTableIsFrozenWhileOtherContentStaysEditable(): void
    {
        $grid = ['grid' => ['rows' => [['A', 'B']], 'widths' => [0, 0]], 'table_style' => 'lines'];
        $trusted = self::document($grid);

        // 只改标题：允许
        BloxProtectedFields::forValidation(self::document($grid, 'New title'), $trusted, ['table']);

        foreach ([
            'edit cell' => self::document(['grid' => ['rows' => [['A', 'changed']], 'widths' => [0, 0]], 'table_style' => 'lines']),
            'edit style' => self::document(['grid' => $grid['grid'], 'table_style' => 'striped']),
            'delete table' => self::document($grid, 'Title', false),
        ] as $case => $sections) {
            try {
                BloxProtectedFields::forValidation($sections, $trusted, ['table']);
                self::fail($case . ' should be rejected');
            } catch (RuntimeException $e) {
                self::assertNotSame('', $e->getMessage(), $case);
            }
        }

        // 新增表格：拒绝
        $this->expectException(RuntimeException::class);
        BloxProtectedFields::forValidation(self::document($grid), self::document($grid, 'Title', false), ['table']);
    }

    public function testTableIsUntouchedWhenTheFeatureIsAllowed(): void
    {
        $trusted = self::document(['grid' => ['rows' => [['A']], 'widths' => [0]]]);
        $edited = self::document(['grid' => ['rows' => [['B']], 'widths' => [0]]]);
        $result = BloxProtectedFields::forValidation($edited, $trusted, ['query_loop']);
        self::assertSame('B', $result[0]['columns'][0]['elements'][1]['data']['grid']['rows'][0][0]);
    }
}
