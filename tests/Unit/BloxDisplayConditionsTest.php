<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxDisplayConditionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testGroupsUseOrAndRulesInsideAGroupUseAnd(): void
    {
        $conditions = [
            ['rules' => [
                ['type' => 'login', 'operator' => 'is', 'value' => 'logged_in'],
                ['type' => 'channel', 'operator' => 'is', 'value' => 7],
            ]],
            ['rules' => [
                ['type' => 'url', 'operator' => 'starts_with', 'value' => '/campaign'],
            ]],
        ];

        self::assertTrue(BloxDisplayConditions::matches($conditions, [
            'logged_in' => true, 'channel_id' => 7, 'date' => '2026-08-15', 'url' => '/products',
        ]));
        self::assertFalse(BloxDisplayConditions::matches($conditions, [
            'logged_in' => true, 'channel_id' => 8, 'date' => '2026-08-15', 'url' => '/products',
        ]));
        self::assertTrue(BloxDisplayConditions::matches($conditions, [
            'logged_in' => false, 'channel_id' => 0, 'date' => '2026-08-15', 'url' => '/campaign/summer',
        ]));
    }

    public function testDateUrlAndNegativeChannelOperatorsAreDeterministic(): void
    {
        self::assertTrue(BloxDisplayConditions::matches([['rules' => [
            ['type' => 'date', 'operator' => 'after', 'value' => '2026-08-01'],
            ['type' => 'channel', 'operator' => 'is_not', 'value' => 9],
            ['type' => 'url', 'operator' => 'contains', 'value' => 'source=ad'],
        ]]], [
            'logged_in' => false, 'channel_id' => 7, 'date' => '2026-08-15', 'url' => '/offer?source=ad',
        ]));
    }

    public function testMalformedOrUnknownRulesFailClosed(): void
    {
        self::assertFalse(BloxDisplayConditions::matches([['rules' => []]], []));
        self::assertFalse(BloxDisplayConditions::matches([['rules' => [[
            'type' => 'role', 'operator' => 'is', 'value' => 'admin',
        ]]]], []));
        self::assertFalse(BloxDisplayConditions::matches([['rules' => [[
            'type' => 'date', 'operator' => 'on', 'value' => '2026-02-31',
        ]]]], []));
        self::assertTrue(BloxDisplayConditions::matches([], []));
    }

    public function testServerRejectsInvalidAndUnlicensedConditionDocuments(): void
    {
        $valid = $this->sections([
            ['rules' => [['type' => 'login', 'operator' => 'is', 'value' => 'logged_out']]],
        ]);
        BloxDisplayConditions::assertSectionsAllowed($valid, true);
        $this->addToAssertionCount(1);

        try {
            BloxDisplayConditions::assertSectionsAllowed($valid, false);
            self::fail('Display conditions must be license-gated on the server.');
        } catch (RuntimeException $e) {
            self::assertSame(__('blox_display_conditions_license_required'), $e->getMessage());
        }

        $this->expectException(RuntimeException::class);
        BloxDisplayConditions::assertSectionsAllowed($this->sections([
            ['rules' => [['type' => 'url', 'operator' => 'eval', 'value' => 'x']]],
        ]), true);
    }

    public function testFrontendOmitsNonMatchingNodesAndCanvasKeepsMarkers(): void
    {
        $conditions = [[
            'rules' => [['type' => 'login', 'operator' => 'is', 'value' => 'logged_in']],
        ]];
        $json = json_encode([[
            'settings' => [],
            'columns' => [['elements' => [[
                'type' => 'heading',
                'data' => ['text' => 'Members only', '_conditions' => $conditions],
            ]]]],
        ]], JSON_THROW_ON_ERROR);

        unset($_SESSION['member_id'], $_SESSION['admin_id']);
        self::assertStringNotContainsString('Members only', BlockRenderer::render($json));

        BlockRenderer::$editChannelId = 1;
        $_SESSION['admin_id'] = 1;
        try {
            $canvas = BlockRenderer::render($json);
        } finally {
            BlockRenderer::$editChannelId = 0;
            unset($_SESSION['admin_id']);
        }
        self::assertStringContainsString('Members only', $canvas);
        self::assertStringContainsString('data-yk-conditions="1/1"', $canvas);
    }

    /** @return array<int,array<string,mixed>> */
    private function sections(array $conditions): array
    {
        return [[
            'type' => 'section',
            'settings' => ['_conditions' => $conditions],
            'columns' => [['elements' => [[
                'type' => 'heading', 'data' => ['text' => 'Heading'],
            ]]]],
        ]];
    }
}
