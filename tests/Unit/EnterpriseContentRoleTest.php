<?php
/** Enterprise content administrator: default grants, opt-in grants, and the entry points that must refuse it. */

declare(strict_types=1);

namespace Yikai\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/permissions.php';

final class EnterpriseContentRoleTest extends TestCase
{
    private const DEFAULT_GRANTS = ['enterprise_content', 'edit_article', 'edit_product', 'edit_case'];
    private const PAGE_AND_LAYOUT = ['*', 'edit_page', 'delete_page', 'blox_edit', 'blox_home', 'blox_global', 'blox_code'];

    private mixed $savedSession = null;

    protected function setUp(): void
    {
        $this->savedSession = $_SESSION ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->savedSession === null) {
            unset($_SESSION);
        } else {
            $_SESSION = $this->savedSession;
        }
    }

    public function testNormalizationKeepsOnlyContentEditingAndSelectedOptionalGrants(): void
    {
        $everything = array_merge(self::PAGE_AND_LAYOUT, ['delete_article', 'delete_product', 'delete_case', 'media',
            'form', 'ai_access', 'system_maintenance', 'enterprise_ai_translation', 'enterprise_case_seo']);

        self::assertSame(self::DEFAULT_GRANTS, \enterpriseContentPermissions());
        self::assertSame([...self::DEFAULT_GRANTS, 'enterprise_ai_translation', 'enterprise_case_seo'],
            \enterpriseContentPermissions($everything));
        self::assertSame([], array_intersect(\enterpriseContentPermissions($everything), self::PAGE_AND_LAYOUT));
        foreach (['delete_article', 'delete_product', 'delete_case', 'media'] as $notDefault) {
            self::assertNotContains($notDefault, \enterpriseContentPermissions($everything));
        }
    }

    public function testEachOptionalGrantIsIndependent(): void
    {
        self::assertSame(['enterprise_ai_translation', 'enterprise_case_seo'], \enterpriseOptionalPermissions());
        self::assertSame([...self::DEFAULT_GRANTS, 'enterprise_ai_translation'],
            \enterpriseContentPermissions(['enterprise_ai_translation']));
        self::assertSame([...self::DEFAULT_GRANTS, 'enterprise_case_seo'],
            \enterpriseContentPermissions(['enterprise_case_seo']));
        self::assertSame(['seo_title', 'seo_keywords', 'seo_description'], \caseSeoFields());
    }

    public function testRoleDetectionUsesTheSessionMarkerOnly(): void
    {
        $_SESSION = ['admin_permissions' => \enterpriseContentPermissions()];
        self::assertTrue(\isEnterpriseContentAdmin());
        $_SESSION = ['admin_permissions' => ['*']];
        self::assertFalse(\isEnterpriseContentAdmin());
        $_SESSION = ['admin_permissions' => ['edit_article', 'edit_product', 'edit_case', 'enterprise_case_seo']];
        self::assertFalse(\isEnterpriseContentAdmin(), 'An optional grant alone must not create the restricted role');
        $_SESSION['admin_permissions'] = 'corrupt';
        self::assertFalse(\isEnterpriseContentAdmin());
        // Non-enterprise editors keep the existing translation and SEO behaviour without any new grant.
        self::assertTrue(\canUseAdminTranslation());
        self::assertTrue(\canEditCaseSeo());
    }

    public function testOnlySuperAdminsAssignTheRoleAndEverySessionIsNarrowed(): void
    {
        $role = $this->source('admin/role.php');
        $auth = $this->source('admin/includes/auth.php');

        self::assertStringContainsString("requirePermission('*');", $role);
        self::assertStringContainsString("requirePermission('*');", $this->source('admin/user.php'));
        self::assertStringContainsString('$permissions = enterpriseContentPermissions($permissions);', $role);
        self::assertStringContainsString('array_diff($permissions, enterpriseOptionalPermissions())', $role);
        self::assertStringContainsString('function updateEnterprisePermissionState()', $role);
        self::assertSame(2, substr_count($auth, "\$_SESSION['admin_permissions'] = enterpriseContentPermissions(\$_SESSION['admin_permissions']);"),
            'Both login and per-request identity refresh must narrow the role');
    }

    public function testPageAndLayoutEditorsRequireGrantsTheRoleNeverHolds(): void
    {
        $guards = [
            'admin/blox_editor.php' => ["requirePermission('blox_home');", "requirePermission('blox_edit');", "requirePermission('edit_page');",
                'requireBloxTemplateTypePermission($templateType);'],
            'admin/blox_page_api.php' => ["requirePermission('blox_edit');", "requirePermission('edit_page');"],
            'admin/blox_home_api.php' => ["requirePermission('blox_home');"],
            'admin/blox_contact_api.php' => ["requirePermission('blox_edit');"],
            'admin/blox_design_api.php' => ["requirePermission('blox_global');"],
            'admin/blox_site_api.php' => ["requirePermission('*');"],
            'admin/blox_preview.php' => ["requirePermission('blox_edit');"],
            'admin/article_design.php' => ["requireBloxTemplateTypePermission('article-detail');"],
            'admin/product_design.php' => ["requireBloxTemplateTypePermission('product-detail');"],
            'admin/page.php' => ["requirePermission('edit_page');"],
            'admin/page_edit.php' => ["requirePermission('edit_page');"],
            'admin/setting_home.php' => ["requirePermission('*');"],
            'admin/product_import.php' => ["requirePermission('*');"],
        ];
        foreach ($guards as $file => $needles) {
            $source = $this->source($file);
            self::assertStringContainsString('checkLogin();', $source, $file);
            foreach ($needles as $needle) {
                self::assertStringContainsString($needle, $source, $file);
            }
        }
        $templateApi = $this->source('admin/blox_template_api.php');
        foreach (['list', 'save_draft', 'save_section', 'save_area_copy', 'publish', 'get', 'prepare_insert', 'confirm_insert'] as $action) {
            self::assertMatchesRegularExpression("/\\\$action === '{$action}'[^\\n]*\\n(?:(?!\\\$action ===).*\\n){0,12}?.*requireBloxTemplateTypePermission/",
                $templateApi, 'blox_template_api ' . $action);
        }
        $auth = $this->source('admin/includes/auth.php');
        self::assertMatchesRegularExpression("/function requireBloxTemplateTypePermission[\\s\\S]{0,200}requirePermission\\('blox_global'\\)[\\s\\S]{0,80}requirePermission\\('blox_edit'\\);\\s*requirePermission\\('edit_page'\\);/", $auth);
    }

    public function testFrontendToolbarAndInlineEditingStayOffForTheRole(): void
    {
        self::assertStringContainsString("if (empty(\$_SESSION['admin_id']) || isEnterpriseContentAdmin()) return;", $this->source('includes/admin_bar.php'));
        self::assertStringContainsString("if (empty(\$_SESSION['admin_id']) || isEnterpriseContentAdmin()) return;", $this->source('includes/front_edit.php'));
        self::assertStringContainsString("&& !empty(\$_SESSION['admin_id']) && !isEnterpriseContentAdmin();", $this->source('includes/builder/BlockRenderer.php'));
        self::assertStringContainsString("empty(\$_SESSION['admin_id']) || isEnterpriseContentAdmin()", $this->source('includes/builder/BloxFrontendEditTarget.php'));
    }

    public function testAiEntryPointsAndCaseSeoGuards(): void
    {
        foreach (['admin/api_ai.php', 'admin/api_ai_agent.php', 'admin/api_ai_apply.php', 'admin/api_ai_undo.php'] as $file) {
            $source = $this->source($file);
            $csrf = strpos($source, 'verifyCsrf();');
            $deny = strpos($source, 'if (isEnterpriseContentAdmin()) {');
            self::assertNotFalse($deny, $file);
            self::assertGreaterThan($csrf, $deny, $file);
        }
        $translate = $this->source('admin/includes/translate_action.php');
        self::assertStringContainsString('if (!canUseAdminTranslation()) permissionDenied();', $translate);
        self::assertStringContainsString('TranslationWrite::generate(array_diff_key($source, $seoKeys), $toLang)', $translate);
        self::assertStringContainsString('if (!canUseAdminTranslation()) permissionDenied();', $this->source('admin/includes/translation_review_action.php'));
        self::assertStringContainsString('if (!canUseAdminTranslation()) permissionDenied();', $this->source('admin/translation_tasks.php'));

        $content = $this->source('admin/content_edit.php');
        self::assertStringContainsString("if ((\$data['type'] !== 'case' && (\$content['type'] ?? '') !== 'case') || canEditCaseSeo()) {", $content);
        self::assertStringNotContainsString("'seo_title' => post('seo_title')", $content);
    }

    public function testAdminNavigationOnlyOffersWhatTheRoleCanOpen(): void
    {
        $sidebar = $this->source('admin/includes/sidebar_menu.php');
        // Two 'visible' keys in one entry silently keep only the last one (the enterprise check was lost that way).
        self::assertDoesNotMatchRegularExpression("/'visible'\\s*=>[^\\n]*\\n\\s*'visible'\\s*=>/", $sidebar);
        self::assertSame(2, substr_count($sidebar, "(!function_exists('isEnterpriseContentAdmin') || !isEnterpriseContentAdmin())"));
        self::assertStringContainsString('if (isEnterpriseContentAdmin()) permissionDenied();', $this->source('admin/translation_status.php'));

        $dashboard = $this->source('admin/index.php');
        self::assertStringContainsString("\$latestForms = hasPermission('form') ? formModel()->where([], 'id DESC', 10) : [];", $dashboard);
        self::assertStringContainsString("<?php if (hasPermission('form')): ?>", $dashboard);
        self::assertStringContainsString("if ((\$item['channel_type'] ?? '') === 'page') return hasPermission('edit_page');", $dashboard);
    }

    private function source(string $path): string
    {
        $source = file_get_contents(ROOT_PATH . '/' . $path);
        self::assertIsString($source, $path);
        return $source;
    }
}
