<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * 2.0 新手第二批：控制台「开始建站」、单一首页入口、链接选择器、单击插入元素。
 */
final class BeginnerOnboardingContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once ROOT_PATH . '/includes/builder/BloxLinkCatalog.php';
    }

    private function source(string $path): string
    {
        return (string) file_get_contents(ROOT_PATH . '/' . $path);
    }

    public function testLanguagePrefixIsSwappedOnlyForInternalLinks(): void
    {
        self::assertSame('/en/about.html', BloxLinkCatalog::swapLangPrefix('/about.html', '', '/en'));
        self::assertSame('/about.html', BloxLinkCatalog::swapLangPrefix('/ja/about.html', '/ja', ''));
        self::assertSame('/en/', BloxLinkCatalog::swapLangPrefix('/ja', '/ja', '/en'));
        // 以 /ja 开头但不是语言段的地址不能被截断
        self::assertSame('/en/japan.html', BloxLinkCatalog::swapLangPrefix('/japan.html', '/ja', '/en'));
        self::assertSame('https://example.com/x', BloxLinkCatalog::swapLangPrefix('https://example.com/x', '', '/en'));
        self::assertSame('//cdn.example.com/x', BloxLinkCatalog::swapLangPrefix('//cdn.example.com/x', '', '/en'));
        self::assertSame('/a?b=1&c=2', BloxLinkCatalog::swapLangPrefix('/a?b=1&amp;c=2', '', ''));
    }

    /** 引导卡只给全新安装：默认关闭，安装器写开启；旧站升级不打扰 */
    public function testStartCardOnlyAppearsOnFreshInstalls(): void
    {
        self::assertStringContainsString("'onboarding_start_dismissed' => ['value' => '1'", $this->source('config/defaults.php'));
        self::assertStringContainsString("'onboarding_start_dismissed', '0'", $this->source('install/index.php'));
        $dashboard = $this->source('admin/index.php');
        self::assertStringContainsString("config('onboarding_start_dismissed', '1') === '0'", $dashboard);
        self::assertStringContainsString("post('action') === 'dismiss_onboarding_start'", $dashboard);
        self::assertStringContainsString('if (!isset($onbStartSteps[$step]))', $dashboard, '步骤键必须白名单校验');
        self::assertStringContainsString("'home'    => ['ti-layout-dashboard', SiteSetup::homeEditUrl()]", $dashboard);
    }

    /** 新手只有一个首页入口：建站向导主按钮与控制台引导都走 SiteSetup::homeEditUrl() */
    public function testSetupWizardUsesTheSingleHomepageEntry(): void
    {
        $setup = $this->source('admin/site_setup.php');
        self::assertStringContainsString('$__homeEditUrl = SiteSetup::homeEditUrl();', $setup);
        self::assertStringContainsString('data-testid="setup-edit-home" href="<?= e($__homeEditUrl) ?>"', $setup);
        self::assertStringContainsString("hasPermission('blox_home') ? '/admin/blox_editor.php?home=1' : '/admin/setting_home.php'", $this->source('includes/SiteSetup.php'));
    }

    /** 已有内容的站也能导入整站模板：先提醒备份、必须勾选确认，服务端凭勾选放行 */
    public function testExistingSitesCanImportAfterExplicitBackupConfirmation(): void
    {
        $local = $this->source('admin/site_templates.php');
        $market = $this->source('admin/site_template_market.php');
        foreach ([$local, $market] as $page) {
            self::assertStringContainsString("require ROOT_PATH . '/admin/includes/site_template_replace_notice.php'", $page);
            self::assertStringContainsString('name="replace_existing" value="1" required', $page);
        }
        self::assertStringContainsString("getAdminId(), post('replace_existing') === '1')", $local);
        self::assertStringContainsString('$service->prepare($temporary, getAdminId(), $replaceExisting)', $market);
        self::assertStringContainsString('href="/admin/database.php"', $this->source('admin/includes/site_template_replace_notice.php'));
        $service = $this->source('includes/SiteTemplateService.php');
        self::assertStringContainsString("if (!\$fresh && !\$replaceExisting) throw new RuntimeException('st_not_fresh');", $service);
        self::assertStringContainsString("\$journal['cleared'][\$table]", $service, '清空的依附表必须进日志，否则无法撤销');
    }

    /** 元素设置面板减负：PRO 只挂标题、空条件只留添加入口、元素名不被挤掉、去掉「实验」 */
    public function testElementPanelStaysReadableForBeginners(): void
    {
        $pro = $this->source('admin/blox_editor/partials/professional-features.php');
        self::assertSame(2, substr_count($pro, "require __DIR__ . '/pro-badge.php'"), 'PRO 角标只在标题与未开通分组上各出现一次');
        self::assertStringContainsString('<span><?= e(__($label)) ?></span><i class="ti ti-chevron-right"', $pro);

        $conditions = $this->source('plugins/yikai-builder/editor/conditions-panel.php');
        self::assertLessThan(
            strpos($conditions, 'data-testid="blox-element-condition-diagnosis"'),
            strpos($conditions, 'data-testid="blox-condition-add-group"'),
            '预览检查放在条件列表之后'
        );
        self::assertStringContainsString('x-show="conditionGroups().length > 0" class="rounded border border-gray-200 p-3 space-y-2" data-testid="blox-element-condition-diagnosis"', $conditions);
        self::assertStringContainsString('x-text="conditionText.emptyHint"', $conditions);

        $workspace = $this->source('admin/blox_editor/partials/workspace.php');
        self::assertStringContainsString('<span class="sr-only"><?= e(__(\'blox_edit_section_background\')) ?></span>', $workspace);
        self::assertStringNotContainsString("label_experimental", $this->source('admin/blox_editor/partials/header.php'));
    }

    public function testUrlControlsOfferTheLinkPicker(): void
    {
        $workspace = $this->source('admin/blox_editor/partials/workspace.php');
        self::assertStringContainsString("<template x-if=\"ctrl.type === 'url'\">", $workspace);
        self::assertSame(2, substr_count($workspace, "require __DIR__ . '/link-picker.php'"));
        self::assertStringContainsString("require __DIR__ . '/blox_editor/partials/link-picker-methods.php'", $this->source('admin/blox_editor.php'));
        self::assertContains('includes/builder/BloxLinkCatalog.php', (require ROOT_PATH . '/config/release-runtime.php')['required_files']);
    }
}
