<?php
declare(strict_types=1);

/** Authoring only. Published rendering and marketplace downloads have separate policies. */
final class BloxFeaturePolicy
{
    public static function tier(string $feature): string
    {
        static $policy = null;
        // 仅隔离的 CLI 探针会定义该常量来模拟收费策略；站点运行时始终读取官方策略文件。
        $policy ??= require (defined('YIKAI_BLOX_FEATURE_POLICY_FILE') && PHP_SAPI === 'cli'
            ? (string) constant('YIKAI_BLOX_FEATURE_POLICY_FILE')
            : __DIR__ . '/../../config/blox-feature-policy.php');
        return is_array($policy) && is_string($policy[$feature] ?? null) ? $policy[$feature] : 'disabled';
    }

    public const PROTECTED_FEATURES = ['query_loop', 'display_conditions', 'style_presets', 'table', 'pricing', 'interactions'];

    private static int $trustedWrites = 0;

    /**
     * 代码内置内容的写入（随包官方区域预置、升级迁移）：内容来自代码本身而不是作者，
     * 不按作者能力拦截——否则未授权站点升级时迁移会中断、官方预置装不上。
     * 作者保存、后台上传的模板包、远程市场模板都不走这里，照常检查。
     *
     * @template T
     * @param callable():T $write
     * @return T
     */
    public static function asTrustedWrite(callable $write): mixed
    {
        self::$trustedWrites++;
        try {
            return $write();
        } finally {
            self::$trustedWrites--;
        }
    }

    public static function inTrustedWrite(): bool
    {
        return self::$trustedWrites > 0;
    }

    /** @return list<string> 当前不可用、需要保留旧配置保护的作者能力 */
    public static function denied(): array
    {
        return array_values(array_filter(self::PROTECTED_FEATURES, static fn(string $feature): bool => !self::allows($feature)));
    }

    public static function allows(string $feature): bool
    {
        $tier = self::tier($feature);
        $enabled = !function_exists('bloxAdvancedFeaturesEnabled') || bloxAdvancedFeaturesEnabled();
        // Free features do not load the Pro package or trigger licensing requests.
        // Only the normal plugin loader can provide the licensed authoring bridge.
        $module = $enabled && match ($tier) {
            'licensed' => function_exists('blox_pro_feature_allowed') && blox_pro_feature_allowed($feature),
            // 核心里的授权能力：注册码含构建器模块即可（永久回退），不依赖 Pro 插件
            'licensed_core' => function_exists('license_owns_blox') && license_owns_blox(),
            default => false,
        };
        return self::decide($tier, $enabled, $module);
    }

    public static function decide(string $tier, bool $enabled, bool $module): bool
    {
        return $enabled && match ($tier) {
            'free' => true,
            'licensed', 'licensed_core' => $module,
            default => false,
        };
    }
}
