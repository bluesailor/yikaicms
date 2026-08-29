<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/AbstractElement.php';
require_once ROOT_PATH . '/includes/builder/BloxDesignSystem.php';

final class BloxDesignSystemTest extends TestCase
{
    public function testElementVisibilityMetadataIsNormalized(): void
    {
        self::assertSame(
            ['_hide_on' => ['m', 'd']],
            BloxDesignSystem::normalizeElementData(['_hide_on' => ['m', 'bogus', 'd', 'm']])
        );
        self::assertSame([], BloxDesignSystem::normalizeElementData(['_hide_on' => 'bogus']));
    }

    /** @var array<string,mixed> */
    private array $previousConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConfig = $GLOBALS['_test_config'] ?? [];
        $GLOBALS['_test_config'] = [];
    }

    protected function tearDown(): void
    {
        $GLOBALS['_test_config'] = $this->previousConfig;
        parent::tearDown();
    }

    public function testSystemColorsKeepStableIdsAndCannotBeShadowedByStoredTokens(): void
    {
        $raw = json_encode([
            'revision' => 7,
            'tokens' => [
                ['id' => 'primary', 'name' => 'Fake', 'value' => '#000000'],
                ['id' => 'c_brand', 'name' => 'Brand', 'value' => '#abcdef'],
            ],
        ], JSON_THROW_ON_ERROR);

        $state = BloxDesignSystem::fromRaw($raw, '#123456', '#654321');
        $tokens = array_column($state['tokens'], null, 'id');

        self::assertSame(7, $state['revision']);
        self::assertSame('#123456', $tokens['primary']['value']);
        self::assertTrue($tokens['primary']['system']);
        self::assertTrue($tokens['primary']['locked']);
        self::assertSame('#abcdef', $tokens['c_brand']['value']);
    }

    public function testArchivedTokenRemainsInCssButIsMarkedAsArchived(): void
    {
        $GLOBALS['_test_config'] = [
            'primary_color' => '#3b82f6',
            'secondary_color' => '#1d4ed8',
            BloxDesignSystem::SETTING_KEY => json_encode([
                'revision' => 2,
                'tokens' => [[
                    'id' => 'c_legacy', 'name' => 'Legacy', 'category' => 'brand',
                    'value' => '#aabbcc', 'status' => 'archived', 'version' => 3,
                ]],
                'styles' => [],
            ], JSON_THROW_ON_ERROR),
        ];

        $state = BloxDesignSystem::snapshot();
        $legacy = array_values(array_filter($state['tokens'], static fn(array $item): bool => $item['id'] === 'c_legacy'))[0];

        self::assertSame('archived', $legacy['status']);
        self::assertStringContainsString('--yk-color-c_legacy:#aabbcc;', BloxDesignSystem::styleTag());
    }

    public function testNamedStyleProducesOnlyWhitelistedDeclarations(): void
    {
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode([
            'tokens' => [],
            'styles' => [[
                'id' => 's_card', 'name' => 'Card', 'category' => 'component',
                'color' => 'var(--yk-color-text)',
                'background' => '#ffffff',
                'border_color' => '#e5e7eb',
                'radius' => 'md',
            ]],
        ], JSON_THROW_ON_ERROR);

        self::assertSame(
            'color:var(--yk-color-text)!important;background-color:#ffffff!important;'
            . 'border-color:#e5e7eb!important;border-style:solid!important;'
            . 'border-width:1px!important;border-radius:0.5rem!important;',
            BloxDesignSystem::styleDeclarations('s_card')
        );
        self::assertNull(BloxDesignSystem::normalizeStyleSnapshot([
            'color' => '#fff;background:url(javascript:alert(1))',
        ]));
    }

    public function testMissingPresetUsesSanitizedDocumentSnapshot(): void
    {
        $GLOBALS['_test_config'][BloxDesignSystem::SETTING_KEY] = json_encode([
            'tokens' => [], 'styles' => [],
        ], JSON_THROW_ON_ERROR);

        self::assertSame(
            'color:#112233!important;border-radius:0.25rem!important;',
            BloxDesignSystem::styleDeclarations('s_missing', [
                'color' => '#112233', 'radius' => 'sm',
            ])
        );
    }

    public function testFreeSaveCannotSmuggleNamedStyleReference(): void
    {
        $sections = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'heading',
                    'data' => ['_global_style' => 's_card'],
                ]],
            ]],
        ]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_global_style_license_required');
        BloxDesignSystem::assertSectionsAllowed($sections, false);
    }

    public function testMalformedNestedReferenceIsRejectedEvenWithAdvancedAccess(): void
    {
        $sections = [[
            'columns' => [[
                'elements' => [[
                    'type' => 'container',
                    'data' => ['children' => [[
                        'type' => 'heading',
                        'data' => ['_global_style' => 'bad);color:red'],
                    ]]],
                ]],
            ]],
        ]];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blox_design_invalid');
        BloxDesignSystem::assertSectionsAllowed($sections, true);
    }
}
