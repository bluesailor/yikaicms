<?php
declare(strict_types=1);

require_once __DIR__ . '/BloxFeaturePolicy.php';

/** Editor presentation only. Never used to grant writes or render published content. */
final class BloxProfessionalUi
{
    public static function snapshot(): array
    {
        $policy = require __DIR__ . '/../../config/blox-feature-policy.php';
        $result = [];
        $account = null;
        foreach (['query_loop', 'display_conditions', 'style_presets'] as $feature) {
            $tier = (string) ($policy[$feature] ?? 'disabled');
            $allowed = BloxFeaturePolicy::allows($feature);
            if (!$allowed && $tier === 'licensed' && $account === null) {
                $account = [
                    'owned' => function_exists('license_has_module') && license_has_module('blox'),
                    'key' => function_exists('license_key') && license_key() !== '',
                    'installed' => is_file(ROOT_PATH . '/plugins/blox-pro/main.php'),
                    'active' => function_exists('isPluginAvailable') && isPluginAvailable('blox-pro'),
                    'supported' => defined('CMS_VERSION') && version_compare((string) CMS_VERSION, '1.20.0', '>='),
                ];
            }
            $state = self::state($tier, $allowed, $account ?? []);
            $route = match ($state) {
                'install', 'enable' => '/admin/plugin.php',
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

    public static function state(string $tier, bool $allowed, array $account): string
    {
        if ($allowed) return 'available';
        if ($tier !== 'licensed') return 'unavailable';
        if (empty($account['owned'])) return !empty($account['key']) ? 'activate' : 'license';
        if (empty($account['supported'])) return 'upgrade';
        if (empty($account['installed'])) return 'install';
        if (empty($account['active'])) return 'enable';
        return 'check';
    }
}
