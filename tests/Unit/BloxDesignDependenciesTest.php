<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxDesignDependenciesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testNestedDocumentsExposeStableTokenAndStyleDependencies(): void
    {
        $refs = BloxDesignDependencies::referencesFromSections([[
            'settings' => ['bg_color' => 'var(--yk-color-campaign)'],
            'columns' => [[
                'elements' => [[
                    'type' => 'container',
                    'data' => [
                        '_global_style' => 's_offer',
                        '_global_style_snapshot' => ['color' => 'var(--yk-color-text)'],
                        'children' => [[
                            'type' => 'heading',
                            'data' => ['color' => 'var(--yk-color-campaign)'],
                        ]],
                    ],
                ]],
            ]],
        ]]);

        self::assertSame(['campaign', 'text'], $refs['design_tokens']);
        self::assertSame(['s_offer'], $refs['design_styles']);
    }

    public function testDiagnosticsDistinguishMissingAndArchivedDependencies(): void
    {
        $diagnostic = BloxDesignDependencies::diagnose([
            'design_tokens' => ['active', 'archived', 'missing'],
            'design_styles' => ['s_active', 's_missing'],
        ], [
            'tokens' => [
                ['id' => 'active', 'status' => 'active'],
                ['id' => 'archived', 'status' => 'archived'],
            ],
            'styles' => [['id' => 's_active', 'status' => 'active']],
        ]);

        self::assertFalse($diagnostic['complete']);
        self::assertSame(['missing'], $diagnostic['missing_tokens']);
        self::assertSame(['s_missing'], $diagnostic['missing_styles']);
        self::assertSame(['archived'], $diagnostic['archived_tokens']);
    }

    public function testImporterPersistsDesignDependenciesInRequirements(): void
    {
        $json = json_encode([
            'format' => BloxTemplateImporter::FORMAT,
            'version' => BloxTemplateImporter::VERSION,
            'type' => 'section',
            'name' => 'Token section',
            'document' => [[
                'type' => 'section',
                'settings' => ['bg_color' => 'var(--yk-color-surface)'],
                'columns' => [['elements' => [[
                    'type' => 'heading',
                    'data' => ['text' => 'Offer', '_global_style' => 's_offer'],
                ]]]],
            ]],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $prepared = BloxTemplateImporter::prepare($json);
        self::assertSame(['surface'], $prepared['requirements']['design_tokens']);
        self::assertSame(['s_offer'], $prepared['requirements']['design_styles']);
    }
}
