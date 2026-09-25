<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once ROOT_PATH . '/includes/builder/BloxFeaturePolicy.php';

final class BloxFeaturePolicyTest extends TestCase
{
    public function testMixedPolicyRequiresActiveProPluginAndModuleOnlyForLicensedFeatures(): void
    {
        foreach (['free' => false, 'licensed' => true, 'service_expired' => true,
            'missing_plugin' => false, 'disabled_plugin' => false, 'old_cms' => false] as $mode => $expected) {
            $lines = [];
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT_PATH . '/tests/fixtures/blox-feature-policy-probe.php') . ' ' . escapeshellarg($mode), $lines, $exit);
            self::assertSame(0, $exit);
            $result = json_decode(implode("\n", $lines), true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($result['free']);
            self::assertSame(0, $result['freeCalls']);
            self::assertSame($expected, $result['query']);
            self::assertFalse($result['conditions']);
            self::assertFalse($result['unknown']);
            self::assertSame(in_array($mode, ['free', 'licensed', 'service_expired'], true) ? ['blox'] : [], $result['calls']);
        }
    }

    public function testFreeLicensedAndDisabledPolicies(): void
    {
        foreach ([false, true] as $module) {
            self::assertTrue(BloxFeaturePolicy::decide('free', true, $module));
            self::assertSame($module, BloxFeaturePolicy::decide('licensed', true, $module));
            self::assertSame($module, BloxFeaturePolicy::decide('licensed_core', true, $module));
            foreach (['free', 'licensed', 'licensed_core', 'disabled', 'invalid', ''] as $tier) {
                self::assertFalse(BloxFeaturePolicy::decide($tier, false, $module));
            }
            self::assertFalse(BloxFeaturePolicy::decide('disabled', true, $module));
            self::assertFalse(BloxFeaturePolicy::decide('invalid', true, $module));
        }
    }

    public function testReleasePolicyLicensesTheProFeaturesAndKeepsUnknownFeaturesClosed(): void
    {
        // v1.20.1 起五项作者端能力为 licensed；v1.23 增补 global_classes（渲染免费、创建与管理付费）。
        $policy = require ROOT_PATH . '/config/blox-feature-policy.php';
        self::assertSame(['query_loop' => 'licensed', 'display_conditions' => 'licensed', 'style_presets' => 'licensed', 'table' => 'licensed', 'pricing' => 'licensed', 'global_classes' => 'licensed', 'interactions' => 'licensed',
            // 2026-09-25 边界裁决：内容维护模式、单页外框覆盖归专业版；代码在核心，只看注册码不依赖 Pro 插件
            'maintenance_mode' => 'licensed_core', 'page_layout' => 'licensed_core'], $policy); // v1.28 增 interactions
        self::assertFalse(BloxFeaturePolicy::allows('unknown'));
    }

    public function testEditorAndWriteEndpointsUseTheSameFeatureKeys(): void
    {
        foreach ([
            'includes/builder/BloxQueryLoopPolicy.php' => 'query_loop',
            'includes/builder/BloxDisplayConditions.php' => 'display_conditions',
            'includes/builder/BloxDesignSystem.php' => 'style_presets',
            'admin/blox_design_api.php' => 'style_presets',
            'admin/blox_design.php' => 'style_presets',
        ] as $file => $feature) {
            self::assertStringContainsString("BloxFeaturePolicy::allows('" . $feature . "')", (string) file_get_contents(ROOT_PATH . '/' . $file));
        }
        $editor = (string) file_get_contents(ROOT_PATH . '/admin/blox_editor.php');
        // E03-D：条件面板迁入 yikai-builder，编辑器开关取专业快照；快照仍以同一 feature key 调用能力策略，并要求作者端模块已加载。
        self::assertStringContainsString("displayConditionsEnabled: <?php echo !empty(\$professionalFeatures['display_conditions']['allowed'])", $editor);
        $professionalUi = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxProfessionalUi.php');
        self::assertStringContainsString('BloxFeaturePolicy::allows($feature) && $moduleLoaded', $professionalUi);
        // v1.23 第六模块 global_classes（不进 PROTECTED_FEATURES）；v1.28 第七模块 interactions（进冻结）
        self::assertStringContainsString("public const MODULE_FEATURES = ['query_loop', 'display_conditions', 'style_presets', 'table', 'pricing', 'global_classes', 'interactions'];", $professionalUi);
        self::assertStringContainsString("BloxFeaturePolicy::allows('interactions')", (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxInteractions.php'));
        self::assertStringContainsString("interactionsEnabled: <?php echo !empty(\$professionalFeatures['interactions']['allowed'])", $editor);
        self::assertStringContainsString("\$advancedQueryLoopEnabled = !empty(\$professionalFeatures['query_loop']['allowed']);", $editor);
        self::assertStringContainsString("stylePresetsEnabled: <?php echo !empty(\$professionalFeatures['style_presets']['allowed'])", $editor);
        $source = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxFeaturePolicy.php');
        self::assertStringContainsString('blox_pro_feature_allowed($feature)', $source);
        self::assertStringContainsString("'licensed_core' => function_exists('license_owns_blox') && license_owns_blox()", $source);
        // 维护模式开关：未授权时 setting.php 不接受写入；单页外框覆盖：保存管线与编辑器同一个 key
        self::assertStringContainsString('if (!BloxFeaturePolicy::allows(\'maintenance_mode\')) unset($settings[\'blox_maintenance_mode\']);', (string) file_get_contents(ROOT_PATH . '/admin/setting.php'));
        self::assertStringContainsString("!BloxFeaturePolicy::allows('page_layout')", (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxDocumentPipeline.php'));
        self::assertStringContainsString("'locked' => !BloxFeaturePolicy::allows('page_layout'),", $editor);
        self::assertStringNotContainsString('plugins/yikai-builder/', $source);
        $pro = (string) file_get_contents(ROOT_PATH . '/plugins/yikai-builder/access.php');
        self::assertStringContainsString("license_has_module('blox')", $pro);
        self::assertStringContainsString("isPluginAvailable('yikai-builder')", $pro);
        self::assertStringNotContainsString('license_valid(', $source);
        self::assertStringNotContainsString('DEBUG', $source);
    }
}
