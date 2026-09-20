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
        self::assertSame(['section', 'page', 'header', 'footer', 'popup', 'archive', 'search', 'error404', 'product-detail', 'article-detail'], BloxTemplateModel::TYPES); // v1.26 增 archive/search/error404
        foreach (['product-detail', 'article-detail'] as $type) {
            self::assertTrue(BloxTemplateModel::validType($type));
            self::assertFalse(BloxTemplateModel::conditionalType($type));
        }
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

    public function testDashboardSeparatesPageHomeAndGlobalBloxCapabilities(): void
    {
        $source = file_get_contents(ROOT_PATH . '/admin/site_design.php');

        self::assertIsString($source);
        self::assertStringContainsString('requireAnyBloxPermission();', $source);
        self::assertStringContainsString('$basicBloxEnabled = bloxPageEditorEnabled();', $source);
        self::assertStringContainsString("\$canEditPages = hasPermission('blox_edit') && hasPermission('edit_page');", $source);
        self::assertStringContainsString("\$canManageGlobalBlox = hasPermission('blox_global');", $source);
        $cards = (string) file_get_contents(ROOT_PATH . '/admin/includes/website_pages.php');
        self::assertStringContainsString("bloxPageEditorEnabled() && hasPermission('blox_home')", $cards);
        self::assertStringContainsString('if ($advancedBloxEnabled && $canManageGlobalBlox)', $source);
        self::assertStringContainsString('/admin/blox_editor.php?home=1', $cards);
        self::assertStringNotContainsString('site_design_section_assets', $source);
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
