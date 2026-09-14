<?php
declare(strict_types=1);

namespace Yikai\Tests\Unit;

use BloxTemplateEditPolicy;
use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxTemplateEditPolicy.php';

final class BloxTemplateEditPolicyTest extends TestCase
{
    public function testBasicTemplatesDoNotRequireAdvancedEditing(): void
    {
        foreach (['section', 'page', 'header', 'footer', 'product-detail', 'article-detail'] as $type) {
            self::assertTrue(BloxTemplateEditPolicy::allows($type, false), $type);
            self::assertTrue(BloxTemplateEditPolicy::allows($type, true), $type);
        }
    }

    public function testAdvancedTemplatesKeepTheirEditingGate(): void
    {
        foreach (['popup'] as $type) {
            self::assertFalse(BloxTemplateEditPolicy::allows($type, false), $type);
            self::assertTrue(BloxTemplateEditPolicy::allows($type, true), $type);
        }
    }

    public function testUnknownTypesNeverAcquireEditingRights(): void
    {
        foreach (['', 'unknown', 'HEADER', ' header ', 'remote:header'] as $type) {
            self::assertFalse(BloxTemplateEditPolicy::allows($type, false), $type);
            self::assertFalse(BloxTemplateEditPolicy::allows($type, true), $type);
        }
    }

    public function testDetailEntryPointsShareTheEditingPolicy(): void
    {
        foreach (['product', 'article'] as $contentType) {
            foreach (['design', 'native_preview'] as $entry) {
                $source = (string) file_get_contents(ROOT_PATH . '/admin/' . $contentType . '_' . $entry . '.php');
                self::assertStringContainsString("BloxTemplateEditPolicy::allows('" . $contentType . "-detail', bloxAdvancedFeaturesEnabled())", $source);
                self::assertStringNotContainsString('|| !bloxAdvancedFeaturesEnabled()', $source);
                self::assertStringContainsString('checkLogin();', $source);
                self::assertStringContainsString("requireBloxTemplateTypePermission('" . $contentType . "-detail');", $source);
            }
        }
        $preview = (string) file_get_contents(ROOT_PATH . '/admin/blox_preview.php');
        self::assertStringContainsString("BloxTemplateEditPolicy::allows('product-detail', bloxAdvancedFeaturesEnabled())", $preview);
        self::assertStringContainsString("BloxTemplateEditPolicy::allows('article-detail', bloxAdvancedFeaturesEnabled())", $preview);
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        self::assertStringContainsString('BloxTemplateEditPolicy::allows($templateType, $advancedBloxEnabled)', $editor);
    }

    public function testApiPreservesResourceDecisionsAndSecurityChecks(): void
    {
        $api = (string) file_get_contents(ROOT_PATH . '/admin/blox_template_api.php');
        self::assertStringNotContainsString("str_starts_with(\$key, 'remote:')", $api);
        self::assertStringNotContainsString("\$item['locked_reason'] = 'license_missing'", $api);
        self::assertStringContainsString('BloxTemplateCatalog::resolve($key, $context)', $api);
        self::assertStringContainsString('requireBloxTemplateTypePermission($context);', $api);
        self::assertStringContainsString('BloxTemplateEditPolicy::allows($type, $advancedBloxEnabled)', $api);
        $getAction = substr($api, (int) strpos($api, "if (\$action === 'get' && \$method === 'POST')"));
        self::assertStringContainsString('verifyCsrf();', $getAction);
        self::assertStringContainsString('checkLogin();', $api);
    }

    public function testLibraryUsesStoredTypesForMutationsWithoutBlockingAcquisition(): void
    {
        $page = (string) file_get_contents(ROOT_PATH . '/admin/blox_templates.php');
        self::assertStringContainsString('if (!bloxPageEditorEnabled())', $page);
        self::assertStringNotContainsString('if (!bloxAdvancedFeaturesEnabled())', $page);
        self::assertStringContainsString("requirePermission('blox_global');", $page);
        self::assertStringContainsString('verifyCsrf();', $page);
        self::assertStringContainsString("BloxTemplateEditPolicy::allows((string) (\$target['type'] ?? ''), \$advancedBloxEnabled)", $page);
        self::assertStringContainsString("['save_metadata', 'publish', 'unpublish', 'delete', 'save_conditions']", $page);
        self::assertStringContainsString('BloxTemplateEditPolicy::allows(\'popup\', $advancedBloxEnabled)', $page);
        self::assertStringContainsString('(new BloxRemoteTemplateInstaller())->importCopy(', $page);
    }
}
