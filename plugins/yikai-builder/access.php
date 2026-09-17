<?php
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition Direct requests do not load the CMS bootstrap. */
if (!defined('ROOT_PATH')) exit('Access Denied');

final class BloxProAccess
{
    public const FEATURES = ['query_loop', 'display_conditions', 'style_presets', 'table', 'pricing'];

    public static function supports(string $version): bool
    {
        $meta = json_decode((string) file_get_contents(__DIR__ . '/plugin.json'), true);
        $minimum = (string) ($meta['requires_cms'] ?? '');
        return preg_match('/^\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?$/D', $version) === 1
            && preg_match('/^\d+\.\d+\.\d+$/D', $minimum) === 1
            && version_compare($version, $minimum, '>=');
    }

    public static function status(): string
    {
        if (!defined('CMS_VERSION') || !self::supports((string) CMS_VERSION)) {
            return 'unsupported';
        }
        // Recheck availability: loading this file alone must not grant authoring rights.
        if (!function_exists('isPluginAvailable') || !isPluginAvailable('yikai-builder')) {
            return 'inactive';
        }
        // Module ownership survives service expiry; registration alone grants nothing.
        // 老专业授权（blox 模块推出前签发、已购付费模块）同样拥有 BLOX Pro，见 license_owns_blox()。
        $owned = function_exists('license_owns_blox')
            ? license_owns_blox()
            : (function_exists('license_has_module') && license_has_module('blox'));
        if (!$owned) {
            return 'unlicensed';
        }
        return 'ready';
    }

    public static function allows(string $feature): bool
    {
        return in_array($feature, self::FEATURES, true) && self::status() === 'ready';
    }
}
