<?php

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxQueryLoopPolicy;
use DynamicSiteData;
use HeadingElement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BloxQueryLoopPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['_test_config'] = [];
    }

    public function testFreeModeKeepsThePresetDynamicList(): void
    {
        BloxQueryLoopPolicy::assertSectionsAllowed($this->sections([
            'type' => 'list-dynamic',
            'data' => ['query_source' => 'type:article', 'limit' => 6, 'item_preset' => 'card'],
        ]), false);
        $this->addToAssertionCount(1);
    }

    public function testFreeModeRejectsCustomTemplatesPaginationAndSiteBindings(): void
    {
        foreach ([
            ['type' => 'list-dynamic', 'data' => ['children' => [['type' => 'heading', 'data' => []]]]],
            ['type' => 'list-dynamic', 'data' => ['pagination_mode' => 'numbers']],
            ['type' => 'heading', 'data' => ['site_field' => 'site_name']],
        ] as $element) {
            try {
                BloxQueryLoopPolicy::assertSectionsAllowed($this->sections($element), false);
                self::fail('Advanced Query Loop data must be rejected in free mode.');
            } catch (RuntimeException $e) {
                self::assertSame(__('blox_query_loop_license_required'), $e->getMessage());
            }
        }
    }

    public function testAdvancedModePreservesAdvancedDocuments(): void
    {
        BloxQueryLoopPolicy::assertSectionsAllowed($this->sections([
            'type' => 'list-dynamic',
            'data' => [
                'pagination_mode' => 'numbers',
                'children' => [['type' => 'heading', 'data' => ['loop_field' => 'title']]],
            ],
        ]), true);
        $this->addToAssertionCount(1);
    }

    public function testSiteBindingUsesOnlyPublicWhitelistedSettings(): void
    {
        $GLOBALS['_test_config'] = [
            'site_name' => 'ACME <Global>',
            'smtp_pass' => 'secret',
        ];

        self::assertSame('ACME <Global>', DynamicSiteData::value('site_name', 'text'));
        self::assertSame('fallback', DynamicSiteData::value('smtp_pass', 'text', 'fallback'));
        self::assertSame('none', array_key_first(DynamicSiteData::fieldOptions('image')));
        self::assertSame(
            '<h2 class="text-2xl font-bold mb-4">ACME &lt;Global&gt;</h2>',
            (new HeadingElement())->render(['site_field' => 'site_name'])
        );
    }

    /** @param array<string,mixed> $element @return array<int,array<string,mixed>> */
    private function sections(array $element): array
    {
        return [[
            'type' => 'section',
            'settings' => [],
            'columns' => [['span' => 12, 'elements' => [$element]]],
        ]];
    }
}
