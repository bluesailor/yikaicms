<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxAreaConditionsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testSummaryUsesEntityNamesAndMakesFallbackAndExclusionsExplicit(): void
    {
        $entities = [
            'channel' => [['id' => 7, 'label' => '产品中心', 'search' => '产品 product 7']],
            'page' => [['id' => 15, 'label' => '联系我们', 'search' => '联系 contact 15']],
        ];
        $conditions = [
            ['main' => 'channel', 'ids' => [7], 'langs' => ['en'], 'exclude' => false],
            ['main' => 'page', 'ids' => [15], 'langs' => [], 'exclude' => true],
        ];

        self::assertStringContainsString('产品中心', BloxAreaConditions::summary($conditions, $entities));
        self::assertStringContainsString('联系我们', BloxAreaConditions::summary($conditions, $entities));
        self::assertStringContainsString('[en]', BloxAreaConditions::summary($conditions, $entities));
        self::assertSame('blox_cond_summary_default', BloxAreaConditions::summary(null, $entities));
        self::assertSame('blox_cond_summary_invalid', BloxAreaConditions::summary('{bad', $entities));
    }

    public function testConflictDetectionReusesResolverSpecificityAndTieBreakRules(): void
    {
        $current = [
            'id' => 20,
            'type' => 'header',
            'conditions' => [['main' => 'any', 'ids' => [], 'exclude' => false]],
        ];
        $home = [
            'id' => 10,
            'name' => '首页页头',
            'conditions' => [['main' => 'home', 'ids' => [], 'exclude' => false]],
        ];
        $sameScopeOlder = [
            'id' => 9,
            'name' => '旧全站页头',
            'conditions' => [['main' => 'any', 'ids' => [], 'exclude' => false]],
        ];

        $conflicts = BloxAreaConditions::conflicts($current, [$home, $sameScopeOlder]);
        self::assertSame('other', $conflicts[0]['outcome']);
        self::assertSame('current', $conflicts[1]['outcome']);
    }

    public function testCrossScopesAreReportedAsMixedAndExclusionCanRemoveOverlap(): void
    {
        $current = [
            'id' => 20,
            'type' => 'footer',
            'conditions' => [
                ['main' => 'any', 'ids' => [], 'exclude' => false],
                ['main' => 'page', 'ids' => [15], 'exclude' => false],
            ],
        ];
        $other = [
            'id' => 10,
            'name' => '定向页尾',
            'conditions' => [
                ['main' => 'home', 'ids' => [], 'exclude' => false],
                ['main' => 'channel', 'ids' => [], 'exclude' => false],
            ],
        ];
        self::assertSame('mixed', BloxAreaConditions::conflicts($current, [$other])[0]['outcome']);

        $current['conditions'][] = ['main' => 'home', 'ids' => [], 'exclude' => true];
        $other['conditions'] = [['main' => 'home', 'ids' => [], 'exclude' => false]];
        self::assertSame([], BloxAreaConditions::conflicts($current, [$other]));
    }

    public function testSaveParserRejectsMalformedAndEmptyPageConditions(): void
    {
        self::assertSame([], BloxAreaConditions::parseForSave('[]'));
        self::assertSame(
            [['main' => 'channel', 'ids' => [7], 'langs' => [], 'exclude' => false]],
            BloxAreaConditions::parseForSave('[{"main":"channel","ids":[7,7],"exclude":false}]')
        );
        self::assertSame(
            [['main' => 'page', 'ids' => [15], 'langs' => ['en'], 'exclude' => false]],
            BloxAreaConditions::parseForSave(
                '[{"main":"page","ids":[15],"langs":["en"],"exclude":false}]',
                ['channel' => [], 'page' => [['id' => 15, 'label' => 'Contact']]]
            )
        );

        foreach (['{bad', '[{"main":"unknown"}]', '[{"main":"page","ids":[]}]'] as $invalid) {
            try {
                BloxAreaConditions::parseForSave($invalid);
                self::fail('Invalid condition payload should be rejected: ' . $invalid);
            } catch (RuntimeException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }

        $this->expectException(RuntimeException::class);
        BloxAreaConditions::parseForSave(
            '[{"main":"page","ids":[99],"exclude":false}]',
            ['channel' => [], 'page' => [['id' => 15, 'label' => 'Contact']]]
        );
    }

    public function testDifferentLanguageAssignmentsDoNotConflict(): void
    {
        $english = [
            'id' => 10,
            'type' => 'header',
            'conditions' => [['main' => 'any', 'ids' => [], 'langs' => ['en'], 'exclude' => false]],
        ];
        $japanese = [
            'id' => 11,
            'name' => 'Japanese header',
            'conditions' => [['main' => 'any', 'ids' => [], 'langs' => ['ja'], 'exclude' => false]],
        ];

        self::assertSame([], BloxAreaConditions::conflicts($english, [$japanese]));
    }
}
