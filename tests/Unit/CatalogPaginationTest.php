<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/includes/catalog_pagination.php';

final class CatalogPaginationTest extends TestCase
{
    public function testTypesAndLegacyValues(): void
    {
        $before = $GLOBALS['yikai_config_runtime_overrides'] ?? [];
        try {
            $GLOBALS['yikai_config_runtime_overrides'] = [];
            foreach (['product', 'article', 'case', 'download', 'job'] as $type) {
                $key = 'catalog_' . $type . '_page_size';
                $GLOBALS['yikai_config_runtime_overrides'][$key] = '';
                self::assertSame(12, catalogPageSize($type));
                self::assertSame(10, catalogPageSize($type, 10));
                $GLOBALS['yikai_config_runtime_overrides'][$key] = '8';
                self::assertSame(8, catalogPageSize($type));
                self::assertSame(8, catalogPageSize($type, 10));
                $GLOBALS['yikai_config_runtime_overrides'][$key] = '101';
                self::assertSame(12, catalogPageSize($type));
            }
            $GLOBALS['yikai_config_runtime_overrides']['catalog_article_page_size'] = '16';
            self::assertSame(16, catalogPageSize('list'));
        } finally {
            $GLOBALS['yikai_config_runtime_overrides'] = $before;
        }
    }

    public function testInputValidation(): void
    {
        foreach (['', '1', '8', '100'] as $value) self::assertTrue(validCatalogPageSize($value));
        foreach (['0', '101', '-1', '1.5', '8x', ' 8', '01', [], null, true] as $value) {
            self::assertFalse(validCatalogPageSize($value));
        }
    }

    public function testChannelOverridePrecedesTypeAndIsIsolated(): void
    {
        $before = $GLOBALS['yikai_config_runtime_overrides'] ?? [];
        try {
            $GLOBALS['yikai_config_runtime_overrides'] = [
                'catalog_case_page_size' => '16',
                'catalog_channel_42_page_size' => '8',
            ];
            self::assertSame(8, catalogPageSize('case', 12, 42));
            self::assertSame(16, catalogPageSize('case', 12, 43));
            $GLOBALS['yikai_config_runtime_overrides']['catalog_channel_42_page_size'] = '';
            self::assertSame(16, catalogPageSize('case', 12, 42));
            $GLOBALS['yikai_config_runtime_overrides']['catalog_channel_42_page_size'] = '101';
            self::assertSame(16, catalogPageSize('case', 12, 42));
        } finally {
            $GLOBALS['yikai_config_runtime_overrides'] = $before;
        }
    }
}
