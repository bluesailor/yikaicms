<?php
/** Blox 图标值：无前缀默认使用 Tabler，bi:<name> 使用 Bootstrap Icons。 */

declare(strict_types=1);

final class BloxIcon
{
    public const BOOTSTRAP_STYLESHEET = '/assets/bootstrap-icons/bootstrap-icons.min.css';

    private const MOTIONS = ['pulse', 'ring', 'slide', 'spin', 'sparkle', 'lift'];

    /**
     * @return array{library:'tabler'|'bootstrap',name:string,value:string}
     */
    public static function parse(mixed $value, string $fallback = 'star'): array
    {
        $raw = is_string($value) ? strtolower(trim($value)) : '';
        $library = 'tabler';

        if (str_starts_with($raw, 'bi:')) {
            $library = 'bootstrap';
            $name = substr($raw, 3);
        } elseif (str_starts_with($raw, 'ti:')) {
            $name = preg_replace('/[^a-z0-9-]/', '', substr($raw, 3)) ?? '';
        } elseif (str_starts_with($raw, 'tabler:')) {
            $name = preg_replace('/[^a-z0-9-]/', '', substr($raw, 7)) ?? '';
        } elseif (str_contains($raw, ':')) {
            $name = '';
        } else {
            // 兼容旧元素：过去会移除非法字符后继续使用该 Tabler 类名。
            $name = preg_replace('/[^a-z0-9-]/', '', $raw) ?? '';
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $name) !== 1) {
            $library = 'tabler';
            $name = strtolower(trim($fallback));
            if (str_starts_with($name, 'ti:')) {
                $name = substr($name, 3);
            } elseif (str_starts_with($name, 'tabler:')) {
                $name = substr($name, 7);
            }
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $name) !== 1) {
                $name = 'star';
            }
        }

        return [
            'library' => $library,
            'name' => $name,
            'value' => $library === 'bootstrap' ? 'bi:' . $name : $name,
        ];
    }

    /** @psalm-suppress PossiblyUnusedMethod 公开值协议 API（编辑器/插件/测试消费） */
    public static function normalize(mixed $value, string $fallback = 'star'): string
    {
        return self::parse($value, $fallback)['value'];
    }

    public static function classes(mixed $value, string $fallback = 'star'): string
    {
        $icon = self::parse($value, $fallback);
        return $icon['library'] === 'bootstrap'
            ? 'bi bi-' . $icon['name']
            : 'ti ti-' . $icon['name'];
    }

    public static function motionClass(mixed $value): string
    {
        $motion = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($motion, self::MOTIONS, true)
            ? ' yk-icon-motion yk-icon-motion--' . $motion
            : '';
    }

    /** @return array<string,string> */
    public static function motionOptions(): array
    {
        return [
            'none' => __('blox_icon_motion_none'),
            'pulse' => __('blox_icon_motion_pulse'),
            'ring' => __('blox_icon_motion_ring'),
            'slide' => __('blox_icon_motion_slide'),
            'spin' => __('blox_icon_motion_spin'),
            'sparkle' => __('blox_icon_motion_sparkle'),
            'lift' => __('blox_icon_motion_lift'),
        ];
    }

    /**
     * 企业站最常用的语义图标。图形来自随包的 Tabler，motion 只描述自主 CSS 动效。
     *
     * @return list<array{icon:string,motion:string,label:string}>
     * @psalm-suppress PossiblyUnusedMethod 后台编辑器入口通过 JSON 目录消费
     */
    public static function businessPresets(): array
    {
        return [
            ['icon' => 'headset', 'motion' => 'ring', 'label' => __('blox_business_icon_service')],
            ['icon' => 'shield-check', 'motion' => 'pulse', 'label' => __('blox_business_icon_trust')],
            ['icon' => 'circle-check', 'motion' => 'sparkle', 'label' => __('blox_business_icon_quality')],
            ['icon' => 'bolt', 'motion' => 'slide', 'label' => __('blox_business_icon_speed')],
            ['icon' => 'users', 'motion' => 'lift', 'label' => __('blox_business_icon_team')],
            ['icon' => 'world', 'motion' => 'spin', 'label' => __('blox_business_icon_global')],
            ['icon' => 'truck', 'motion' => 'slide', 'label' => __('blox_business_icon_delivery')],
            ['icon' => 'bulb', 'motion' => 'sparkle', 'label' => __('blox_business_icon_innovation')],
            ['icon' => 'settings', 'motion' => 'spin', 'label' => __('blox_business_icon_solution')],
            ['icon' => 'lock', 'motion' => 'pulse', 'label' => __('blox_business_icon_security')],
            ['icon' => 'heart-handshake', 'motion' => 'lift', 'label' => __('blox_business_icon_partnership')],
            ['icon' => 'trending-up', 'motion' => 'lift', 'label' => __('blox_business_icon_growth')],
        ];
    }

    public static function stylesheet(mixed $value): ?string
    {
        return self::parse($value)['library'] === 'bootstrap'
            ? self::BOOTSTRAP_STYLESHEET
            : null;
    }

    public static function isNone(mixed $value): bool
    {
        $icon = self::parse($value);
        return $icon['library'] === 'tabler' && $icon['name'] === 'none';
    }
}
