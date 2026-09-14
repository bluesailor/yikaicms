<?php
declare(strict_types=1);

/** Authoring only. Published rendering and marketplace downloads have separate policies. */
final class BloxFeaturePolicy
{
    public static function allows(string $feature): bool
    {
        static $policy = null;
        $policy ??= require __DIR__ . '/../../config/blox-feature-policy.php';
        $tier = is_array($policy) && is_string($policy[$feature] ?? null) ? $policy[$feature] : 'disabled';
        $enabled = !function_exists('bloxAdvancedFeaturesEnabled') || bloxAdvancedFeaturesEnabled();
        // Free features do not load the Pro package or trigger licensing requests.
        // Only the normal plugin loader can provide the licensed authoring bridge.
        $module = $enabled && $tier === 'licensed'
            && function_exists('blox_pro_feature_allowed') && blox_pro_feature_allowed($feature);
        return self::decide($tier, $enabled, $module);
    }

    public static function decide(string $tier, bool $enabled, bool $module): bool
    {
        return $enabled && match ($tier) {
            'free' => true,
            'licensed' => $module,
            default => false,
        };
    }
}
