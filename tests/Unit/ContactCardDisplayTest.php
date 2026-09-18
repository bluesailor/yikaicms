<?php
declare(strict_types=1);

use Yikai\Tests\TestCase;

final class ContactCardDisplayTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/contact_parts.php';
        require_once ROOT_PATH . '/includes/builder/bootstrap.php';
    }

    public function testDefaultLayoutAndInvalidDisplayValuesPreserveContactData(): void
    {
        $cards = [
            ['icon' => 'phone', 'label' => '<Phone>', 'value' => '400-888-8888'],
            ['icon' => 'email', 'label' => 'Email', 'value' => 'contact@example.com'],
            ['icon' => 'location', 'label' => 'Address', 'value' => "Floor 1\nEast wing"],
        ];
        $original = renderContactCardsHtml($cards);
        self::assertStringContainsString('md:block md:text-center', $original);
        self::assertStringContainsString(' mb-12', $original);
        self::assertStringContainsString('&lt;Phone&gt;', $original);
        self::assertStringContainsString('href="tel:4008888888"', $original);
        self::assertStringContainsString('href="mailto:contact@example.com"', $original);
        self::assertStringContainsString('Floor 1<br />', $original);
        self::assertSame($original, renderContactCardsHtml($cards, null, null, null, true, [
            'card_layout' => [], 'card_align' => '" onclick="bad', 'icon_surface' => 'bad', 'card_gap' => '100vh',
        ]));
        $hidden = renderContactCardsHtml($cards, null, null, null, false, ['card_layout' => 'list', 'show_icons' => false]);
        self::assertStringNotContainsString('<svg', $hidden);
        self::assertStringNotContainsString(' mb-12', $hidden);
        self::assertStringContainsString('href="tel:4008888888"', $hidden);
    }

    public function testDisplaySettingsSurviveSaveWithoutChangingSharedCards(): void
    {
        $previous = $GLOBALS['_test_config'] ?? [];
        $GLOBALS['_test_config']['contact_cards'] = json_encode([
            ['icon' => 'phone', 'label' => 'Phone', 'value' => '400-888-8888'],
            ['icon' => 'email', 'label' => 'Email', 'value' => 'contact@example.com'],
        ], JSON_THROW_ON_ERROR);
        try {
            $data = ['cols' => 'auto', 'card_layout' => 'list', 'card_align' => 'right',
                'icon_surface' => 'square', 'show_icons' => true, 'card_gap' => 'sm'];
            $json = json_encode([['columns' => [['elements' => [['type' => 'contact_cards', 'data' => $data]]]]]], JSON_THROW_ON_ERROR);
            $result = BloxDocumentPipeline::process($json, 'page');
            $saved = json_decode($result['json'], true, 512, JSON_THROW_ON_ERROR)['sections'][0]['columns'][0]['elements'][0]['data'];
            foreach ($data as $key => $value) self::assertSame($key === 'show_icons' ? '1' : $value, $saved[$key]);
            $element = new ContactCardsElement();
            $html = $element->render($saved);
            foreach (['yk-contact-layout-list', 'yk-contact-align-right', 'yk-contact-icons-square', 'yk-contact-gap-sm', 'md:grid-cols-1', 'contact@example.com'] as $expected) {
                self::assertStringContainsString($expected, $html);
            }
            self::assertStringContainsString('md:grid-cols-3', $element->render(array_replace($saved, ['cols' => '3'])));
            self::assertStringContainsString('md:grid-cols-1', $element->render(['cols' => '1', 'card_layout' => 'side']));
            self::assertCount(2, contactCardsData());
        } finally {
            $GLOBALS['_test_config'] = $previous;
        }
    }
}
