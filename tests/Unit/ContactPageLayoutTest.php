<?php
/**
 * Contact page layout contract tests.
 */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/contact_parts.php';
require_once ROOT_PATH . '/includes/builder/bootstrap.php';

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

    public function testLegacyContactContentIsPreservedAndMissingContactElementsAreCompleted(): void
    {
        $legacy = [[
            'id' => 'legacy-section',
            'columns' => [[
                'id' => 'legacy-column',
                'elements' => [[
                    'id' => 'legacy-text',
                    'type' => 'text',
                    'data' => ['html' => '<p>Existing contact introduction</p>'],
                ]],
            ]],
        ]];

        $sections = completeContactSeedSections($legacy);

        self::assertCount(3, $sections);
        self::assertSame($legacy[0], $sections[0]);
        self::assertSame('contact_cards', $sections[1]['columns'][0]['elements'][0]['type']);
        self::assertSame('contact_form', $sections[2]['columns'][0]['elements'][0]['type']);
        self::assertSame('contact_map', $sections[2]['columns'][1]['elements'][0]['type']);
    }

    public function testExistingNestedContactElementsAreNotDuplicated(): void
    {
        $sections = [[
            'id' => 'existing-section',
            'columns' => [[
                'id' => 'existing-column',
                'elements' => [[
                    'id' => 'existing-container',
                    'type' => 'container',
                    'data' => [],
                    'children' => [[
                        'id' => 'existing-cards',
                        'type' => 'contact_cards',
                        'data' => [],
                    ]],
                ]],
            ]],
        ]];

        $completed = completeContactSeedSections($sections);

        self::assertCount(2, $completed);
        self::assertSame($sections[0], $completed[0]);
        self::assertSame('contact_form', $completed[1]['columns'][0]['elements'][0]['type']);
        self::assertSame('contact_map', $completed[1]['columns'][1]['elements'][0]['type']);
    }

    public function testOnlyMissingContactElementIsAddedWithoutDuplicatingExistingOnes(): void
    {
        $seed = contactSeedSections();
        $seed[1]['columns'] = [$seed[1]['columns'][0]];

        $completed = completeContactSeedSections($seed);

        self::assertCount(3, $completed);
        self::assertSame('contact_map', $completed[2]['columns'][0]['elements'][0]['type']);
        self::assertSame(12, $completed[2]['columns'][0]['span']);
    }

    public function testBuilderContactCardsDelegateSpacingToSection(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/builder/elements/ContactCardsElement.php');

        self::assertStringContainsString(
            'renderContactCardsHtml($cards, $grid, null, null, false)',
            $source
        );
    }

    public function testContactCardNormalizerEnforcesTheSharedFourCardContract(): void
    {
        $cards = normalizeContactCards([
            ['icon' => 'phone', 'label' => ' Phone ', 'value' => ' 400-000-0000 '],
            ['icon' => 'not-allowed', 'label' => 'Email', 'value' => 'info@example.com'],
            ['icon' => 'location', 'label' => '', 'value' => 'Missing title'],
            ['icon' => 'clock', 'label' => 'Hours', 'value' => '9:00-18:00'],
            ['icon' => 'building', 'label' => 'Company', 'value' => 'Yikai'],
            ['icon' => 'globe', 'label' => 'Ignored', 'value' => 'https://example.com'],
        ]);

        self::assertCount(4, $cards);
        self::assertSame(['icon' => 'phone', 'label' => 'Phone', 'value' => '400-000-0000'], $cards[0]);
        self::assertSame('', $cards[1]['icon']);
        self::assertSame('Company', $cards[3]['label']);
    }

    public function testContactCardSettingKeyFollowsPageLanguage(): void
    {
        $GLOBALS['_test_config']['site_lang'] = 'zh-CN';
        try {
            self::assertSame('contact_cards', contactCardsSettingKey('zh-CN'));
            self::assertSame('contact_cards_en', contactCardsSettingKey('en'));
            self::assertSame('contact_cards_ja', contactCardsSettingKey('ja'));
            self::assertSame('contact_cards', contactCardsSettingKey('../invalid'));

            $GLOBALS['_test_config']['site_lang'] = 'en';
            self::assertSame('contact_cards', contactCardsSettingKey('en'));
            self::assertSame('contact_cards_ja', contactCardsSettingKey('ja'));
        } finally {
            unset($GLOBALS['_test_config']['site_lang']);
        }
    }

    public function testContactFormNormalizerKeepsOnlySafeUniqueFields(): void
    {
        $fields = normalizeContactFormFields([
            ['key' => 'name', 'label' => ' Name ', 'type' => 'text', 'required' => true],
            ['key' => 'name', 'label' => 'Duplicate', 'type' => 'email'],
            ['key' => '../bad', 'label' => 'Bad key', 'type' => 'text'],
            ['key' => 'website', 'label' => 'Website', 'type' => 'url', 'enabled' => false],
            ['key' => 'note', 'label' => 'Note', 'type' => 'unsupported', 'placeholder' => ' Tell us more '],
        ]);

        self::assertCount(2, $fields);
        self::assertSame('Name', $fields[0]['label']);
        self::assertTrue($fields[0]['required']);
        self::assertFalse($fields[1]['enabled']);
        self::assertSame('url', $fields[1]['type']);
    }

    public function testContactElementsAreInsertableOnlyInContactContext(): void
    {
        $pageMeta = \BuilderRegistry::meta('page');
        $contactMeta = \BuilderRegistry::meta('contact');

        foreach (['contact_cards', 'contact_form', 'contact_map'] as $type) {
            self::assertFalse($pageMeta[$type]['paletteVisible']);
            self::assertTrue($contactMeta[$type]['paletteVisible']);
        }
    }

    public function testBloxContactModeSeedsEmptyDocumentsAndExposesDataManagers(): void
    {
        if (!is_file(ROOT_PATH . '/admin/blox_editor.php')) {
            // 付费 Blox 源码不随公开仓库分发；无注入的 CI 矩阵跳过，注入 job 与本地全量执行。
            self::markTestSkipped('付费 Blox 源码未注入：admin/blox_editor.php');
        }
        $editorSource = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        $workspaceSource = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor/partials/workspace.php');

        self::assertStringContainsString("completeContactSeedSections($" . "bootDoc['sections'])", $editorSource);
        self::assertStringContainsString("BuilderRegistry::meta($" . "registryContext)", $editorSource);
        self::assertStringContainsString("'/admin/form_design.php'", $editorSource);
        self::assertStringContainsString('data-testid="blox-contact-source"', $workspaceSource);
        self::assertStringContainsString('data-testid="blox-contact-cards-editor"', $workspaceSource);
        self::assertStringContainsString('saveContactCards()', $workspaceSource);
        self::assertStringContainsString('data-testid="blox-contact-form-editor"', $workspaceSource);
        self::assertStringContainsString('addContactFormField()', $workspaceSource);
        self::assertStringContainsString('saveContactForm()', $workspaceSource);
        self::assertStringContainsString('contactFormVisual', $editorSource);
    }

    public function testBloxContactApiKeepsCardsInTheSharedSettingsSource(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/admin/blox_contact_api.php');

        self::assertStringContainsString('verifyCsrf();', $source);
        self::assertStringContainsString('$isContactPage', $source);
        self::assertStringContainsString('normalizeContactCards($decoded)', $source);
        self::assertStringContainsString('settingModel()->saveBatch', $source);
        self::assertStringContainsString('contactCardsSettingKey(', $source);
        self::assertStringContainsString("$" . "action === 'save_form'", $source);
        self::assertStringContainsString("requirePermission('form')", $source);
        self::assertStringContainsString('isJsonFields($currentFields)', $source);
        self::assertStringContainsString('normalizeContactFormFields($decoded)', $source);
        self::assertStringContainsString('formTemplateModel()->updateById', $source);
    }

    public function testDisabledStructuredFieldsAreExcludedFromRenderedTemplate(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/includes/functions.php');

        self::assertStringContainsString('array_key_exists(\'enabled\', $field)', $source);
        self::assertStringContainsString('empty($field[\'enabled\'])', $source);
    }

    public function testContactSubmissionUsesLocalizedFieldsAndSkipsDisabledOnes(): void
    {
        $source = (string) file_get_contents(ROOT_PATH . '/form_submit.php');

        self::assertStringContainsString("$" . "template['fields_' . $" . "fieldsLang]", $source);
        self::assertStringContainsString('array_key_exists(\'enabled\', $field)', $source);
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
        $heroSource = (string) file_get_contents(ROOT_PATH . '/includes/partials/contact-hero.php');

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
