<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxResponsiveValueTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testSharedResponsiveFixtures(): void
    {
        $fixtures = json_decode(
            (string) file_get_contents(ROOT_PATH . '/tests/fixtures/blox-responsive-values.json'),
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        $allowed = array_fill_keys(['none', 'sm', 'md', 'lg'], true);
        foreach ($fixtures as $fixture) {
            self::assertSame(
                $fixture['expected'],
                BloxResponsiveValue::normalize($fixture['value'], $allowed, $fixture['fallback']),
                $fixture['name']
            );
        }
    }

    public function testStorageKeepsLegacyScalarAndCanonicalizesResponsiveInput(): void
    {
        $allowed = array_fill_keys(['sm', 'md', 'lg'], true);
        self::assertSame('md', BloxResponsiveValue::normalizeStored('md', $allowed, 'sm'));
        self::assertSame(
            ['d' => 'lg', 't' => 'md'],
            BloxResponsiveValue::normalizeStored(['desktop' => 'lg', 'tablet' => 'md'], $allowed, 'sm')
        );
        self::assertSame(
            ['d' => 'lg', 'm' => 'sm'],
            BloxResponsiveValue::normalizeStored(['desktop' => 'lg', 'mobile' => 'sm'], $allowed, 'sm')
        );
        self::assertSame(
            'sm',
            BloxResponsiveValue::normalizeStored(['desktop' => 'bad'], $allowed, 'sm')
        );
    }

    public function testDocumentPipelinePreservesResponsiveSectionColumnAndElementValues(): void
    {
        $processed = BloxDocumentPipeline::process(json_encode([
            'schema' => 1,
            'settings' => [],
            'sections' => [[
                'settings' => [
                    'padding' => ['desktop' => 'xl', 'mobile' => 'sm'],
                    'gap' => 'md',
                ],
                'columns' => [[
                    'span' => ['d' => 8, 't' => 6],
                    'elements' => [[
                        'type' => 'spacer',
                        'data' => ['size' => ['desktop' => 'lg', 'mobile' => 'sm']],
                    ]],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR), 'responsive');

        $section = $processed['sections'][0];
        self::assertSame(['d' => 'xl', 'm' => 'sm'], $section['settings']['padding']);
        self::assertSame('md', $section['settings']['gap']);
        self::assertSame(['d' => 8, 't' => 6], $section['columns'][0]['span']);
        self::assertSame(
            ['d' => 'lg', 'm' => 'sm'],
            $section['columns'][0]['elements'][0]['data']['size']
        );
    }
}
