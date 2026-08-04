<?php
/**
 * Contact page layout contract tests.
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/contact_parts.php';

final class ContactPageLayoutTest extends TestCase
{
    public function testDefaultLayoutUsesResponsiveCardsAndSevenFiveMainColumns(): void
    {
        $sections = contactSeedSections();

        self::assertCount(2, $sections);
        self::assertSame('contact_cards', $sections[0]['columns'][0]['elements'][0]['type']);
        self::assertSame('md', $sections[0]['settings']['padding']);
        self::assertSame('sm', $sections[1]['settings']['padding']);
        self::assertSame([7, 5], array_column($sections[1]['columns'], 'span'));
        self::assertSame('contact_form', $sections[1]['columns'][0]['elements'][0]['type']);
        self::assertSame('contact_map', $sections[1]['columns'][1]['elements'][0]['type']);
    }

    public function testBuilderContactCardsDelegateSpacingToSection(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/builder/elements/ContactCardsElement.php');

        self::assertStringContainsString(
            'renderContactCardsHtml($cards, $grid, null, null, false)',
            $source
        );
    }

    public function testEditorResetUsesCanonicalSeedAndPreservesColumnMetadata(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/page_edit_advance.php');

        self::assertStringContainsString('d.sections = CONTACT_SEED_SECTIONS.map', $source);
        self::assertStringContainsString('var column = JSON.parse(JSON.stringify(c || {}));', $source);
        self::assertStringContainsString('contactElementManage(type)', $source);
        self::assertStringContainsString("require_once ROOT_PATH . '/includes/HtmlCache.php';", $source);
    }

    public function testContactFrontTemplateUsesCompactHeroAndUsefulFallback(): void
    {
        $pageSource = (string) file_get_contents(ROOT_PATH . '/contact.php');
        $partsSource = (string) file_get_contents(ROOT_PATH . '/includes/contact_parts.php');
        $heroSource = (string) file_get_contents(ROOT_PATH . '/themes/default/partials/contact-hero.php');

        self::assertStringContainsString("theme_path('partials/contact-hero.php')", $pageSource);
        self::assertStringContainsString("'tel:' . \$phoneTarget", $partsSource);
        self::assertStringContainsString("'mailto:' . \$cardValue", $partsSource);
        self::assertStringContainsString("__('contact_visit_title')", $partsSource);
        self::assertStringContainsString('border-l-4 border-primary', $heroSource);
        self::assertStringNotContainsString('bg-gradient', $heroSource);
    }

    public function testStaticHtmlInvalidationAlsoRemovesFilesWhenGenerationIsDisabled(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/StaticHtml.php');

        self::assertStringContainsString('if (StaticHtml::$mute) return;', $source);
        self::assertStringNotContainsString('StaticHtml::$mute || !StaticHtml::enabled()', $source);
    }
}
