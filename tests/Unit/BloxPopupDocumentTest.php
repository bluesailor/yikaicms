<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BloxPopupDocumentTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testPopupSettingsAreNormalizedAndUnknownValuesFailClosed(): void
    {
        $settings = BloxPopupDocument::normalizeSettings([
            'trigger' => 'click',
            'selector' => 'body > script',
            'frequency' => 'hours',
            'hours' => 900,
            'device' => 'watch',
            'width' => 'huge',
            'overlay_close' => false,
            'show_close' => false,
        ]);

        self::assertSame('click', $settings['trigger']);
        self::assertSame('', $settings['selector']);
        self::assertSame(720, $settings['hours']);
        self::assertSame('all', $settings['device']);
        self::assertSame('md', $settings['width']);
        self::assertFalse($settings['overlay_close']);
        self::assertFalse($settings['show_close']);
    }

    public function testPopupRoundTripKeepsSettingsAndUsesThemInRevision(): void
    {
        $document = [
            'schema' => 1,
            'settings' => ['trigger' => 'delay', 'delay' => 5, 'frequency' => 'session'],
            'sections' => [[
                'type' => 'section',
                'settings' => [],
                'columns' => [['elements' => []]],
            ]],
        ];
        $json = json_encode($document, JSON_THROW_ON_ERROR);
        $processed = BloxPopupDocument::process($json, 'popup_test');
        self::assertSame(5, $processed['settings']['delay']);
        self::assertSame('session', $processed['settings']['frequency']);

        $revision = BloxPopupDocument::fingerprint($processed['json']);
        self::assertTrue(BloxPopupDocument::revisionMatches($processed['json'], $revision));

        $changed = json_decode($processed['json'], true, 512, JSON_THROW_ON_ERROR);
        $changed['settings']['delay'] = 6;
        self::assertFalse(BloxPopupDocument::revisionMatches(json_encode($changed, JSON_THROW_ON_ERROR), $revision));
    }

    public function testPopupIsAConditionalAdministrativeTemplateType(): void
    {
        self::assertTrue(BloxTemplateModel::validType('popup'));
        self::assertTrue(BloxTemplateModel::conditionalType('popup'));
        self::assertFalse(BloxAreaDocument::isArea('popup'));
    }
}
