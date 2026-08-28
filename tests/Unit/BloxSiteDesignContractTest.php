<?php
/** Template taxonomy used by the website-design dashboard and template filters. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxTemplateModel;
use PHPUnit\Framework\TestCase;

final class BloxSiteDesignContractTest extends TestCase
{
    public function testTemplateTaxonomySeparatesReusableAndSiteAreaTypes(): void
    {
        self::assertSame(['section', 'page', 'header', 'footer', 'popup'], BloxTemplateModel::TYPES);
        self::assertFalse(BloxTemplateModel::conditionalType('section'));
        self::assertFalse(BloxTemplateModel::conditionalType('page'));
        self::assertTrue(BloxTemplateModel::conditionalType('header'));
        self::assertTrue(BloxTemplateModel::conditionalType('footer'));
        self::assertTrue(BloxTemplateModel::conditionalType('popup'));
        self::assertFalse(BloxTemplateModel::validType('all'));
    }

    public function testCustomHeaderRuntimeIsEnabledByDefault(): void
    {
        $defaults = require ROOT_PATH . '/config/defaults.php';

        self::assertSame('1', $defaults['system']['blox_custom_header_enabled']['value'] ?? null);
        self::assertSame('switch', $defaults['system']['blox_custom_header_enabled']['type'] ?? null);
        self::assertSame('1', $defaults['system']['blox_custom_footer_enabled']['value'] ?? null);
        self::assertSame('switch', $defaults['system']['blox_custom_footer_enabled']['type'] ?? null);
    }

    public function testHomepageEntryUsesBasicEditorGateWhileSiteAreasStayAdvanced(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/site_design.php');

        self::assertIsString($source);
        self::assertStringContainsString('$basicBloxEnabled = bloxPageEditorEnabled();', $source);
        self::assertStringContainsString('if ($basicBloxEnabled && $isAdministrator)', $source);
        self::assertStringContainsString('if ($advancedBloxEnabled && $isAdministrator)', $source);
        self::assertStringContainsString('/admin/blox_editor.php?home=1', $source);
        self::assertStringContainsString('/admin/blox_templates.php?type=', $source);
    }

    public function testTemplateLibraryExposesAResolvedAreaAssignmentMatrix(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/blox_templates.php');

        self::assertIsString($source);
        self::assertStringContainsString('BloxAreaAssignmentMatrix::build(', $source);
        self::assertStringContainsString('data-testid="blox-assignment-matrix"', $source);
        self::assertStringContainsString('data-testid="blox-assignment-matrix-search"', $source);
        self::assertStringContainsString('data-testid="blox-assignment-row"', $source);
        self::assertStringContainsString('data-testid="blox-assignment-template"', $source);
        self::assertStringContainsString("'home:' . \$languageCode", $source);
    }
}
