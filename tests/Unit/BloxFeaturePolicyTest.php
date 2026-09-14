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
            foreach (['free', 'licensed', 'disabled', 'invalid', ''] as $tier) {
                self::assertFalse(BloxFeaturePolicy::decide($tier, false, $module));
            }
            self::assertFalse(BloxFeaturePolicy::decide('disabled', true, $module));
            self::assertFalse(BloxFeaturePolicy::decide('invalid', true, $module));
        }
    }

    public function testReleasePolicyKeepsCurrentFeaturesFreeAndUnknownFeaturesClosed(): void
    {
        $policy = require ROOT_PATH . '/config/blox-feature-policy.php';
        self::assertSame(['query_loop' => 'free', 'display_conditions' => 'free', 'style_presets' => 'free'], $policy);
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
        self::assertStringContainsString("displayConditionsEnabled: <?php echo BloxFeaturePolicy::allows('display_conditions')", $editor);
        self::assertStringContainsString("stylePresetsEnabled: <?php echo BloxFeaturePolicy::allows('style_presets')", $editor);
        $source = (string) file_get_contents(ROOT_PATH . '/includes/builder/BloxFeaturePolicy.php');
        self::assertStringContainsString('blox_pro_feature_allowed($feature)', $source);
        self::assertStringNotContainsString('plugins/blox-pro/', $source);
        $pro = (string) file_get_contents(ROOT_PATH . '/plugins/blox-pro/access.php');
        self::assertStringContainsString("license_has_module('blox')", $pro);
        self::assertStringContainsString("isPluginAvailable('blox-pro')", $pro);
        self::assertStringNotContainsString('license_valid(', $source);
        self::assertStringNotContainsString('DEBUG', $source);
    }
}
