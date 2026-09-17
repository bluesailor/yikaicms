<?php
declare(strict_types=1);

require_once __DIR__ . '/BloxFeaturePolicy.php';

/** Editor presentation only. Never used to grant writes or render published content. */
/** @psalm-suppress UnusedClass Used by the separately analysed editor entry. */
final class BloxProfessionalUi
{
    /** 作者端面板已迁入 blox-pro 的能力；其余仍由核心编辑器提供。 */
    public const MODULE_FEATURES = ['query_loop', 'display_conditions', 'style_presets', 'table'];

    public static function moduleLoaded(string $feature): bool
    {
        return !in_array($feature, self::MODULE_FEATURES, true)
            || (function_exists('blox_pro_editor_modules') && in_array($feature, blox_pro_editor_modules(), true));
    }

    public static function snapshot(): array
    {
        $result = [];
        $account = null;
        foreach (self::MODULE_FEATURES as $feature) {
            $tier = BloxFeaturePolicy::tier($feature);
            $moduleLoaded = self::moduleLoaded($feature);
            // 编辑面板需要能力策略放行且作者端模块已加载；保存校验只看能力策略，不受此影响。
            $allowed = BloxFeaturePolicy::allows($feature) && $moduleLoaded;
            if (!$allowed && ($tier === 'licensed' || !$moduleLoaded) && $account === null) {
                $account = [
                    'owned' => function_exists('license_owns_blox') && license_owns_blox(),
                    'key' => function_exists('license_key') && license_key() !== '',
                    'installed' => is_file(ROOT_PATH . '/plugins/blox-pro/main.php'),
                    'active' => function_exists('isPluginAvailable') && isPluginAvailable('blox-pro'),
                    'supported' => defined('CMS_VERSION') && version_compare((string) CMS_VERSION, '1.20.0', '>='),
                ];
            }
            $state = self::state($tier, $allowed, $account ?? [], $moduleLoaded);
            $route = match ($state) {
                'install', 'enable', 'module_install', 'module_enable' => '/admin/plugin.php',
                'upgrade' => '/admin/upgrade_online.php',
                'activate', 'license', 'check' => '/admin/license.php',
                default => '',
            };
            $result[$feature] = [
                'allowed' => $allowed, 'visible' => in_array($tier, ['free', 'licensed'], true),
                'state' => $state, 'url' => $route,
                'message' => $allowed ? '' : __('blox_professional_' . $state),
                'action' => $route === '' ? '' : __('blox_professional_action_' . $state),
            ];
        }
        return $result;
    }

    public static function state(string $tier, bool $allowed, array $account, bool $moduleLoaded = true): string
    {
        if ($allowed) return 'available';
        // 免费能力只缺作者端模块：提示安装/启用插件，不涉及授权或购买。
        if ($tier === 'free' && !$moduleLoaded) return empty($account['installed']) ? 'module_install' : 'module_enable';
        if ($tier !== 'licensed') return 'unavailable';
        if (empty($account['owned'])) return !empty($account['key']) ? 'activate' : 'license';
        if (empty($account['supported'])) return 'upgrade';
        if (empty($account['installed'])) return 'install';
        if (empty($account['active'])) return 'enable';
        return 'check';
    }
}
