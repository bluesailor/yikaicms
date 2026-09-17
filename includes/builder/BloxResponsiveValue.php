<?php
/** Canonical desktop/tablet/mobile value normalization shared by Blox renderers. */

declare(strict_types=1);

final class BloxResponsiveValue
{
    private const DEVICE_ALIASES = [
        'd' => 'd',
        'desktop' => 'd',
        't' => 't',
        'tablet' => 't',
        'm' => 'm',
        'mobile' => 'm',
        'w' => 'w',
        'wide' => 'w',
    ];

    /** 全站断点开关：宽屏档（≥1440）可在「全站设计 › 断点」关闭，关闭后 w 值一律按桌面处理。 */
    public const WIDE_SETTING_KEY = 'blox_widescreen_enabled';

    /**
     * 断点档位（与 BloxCssCompiler、Tailwind md:/lg:/wide:、assets/js/blox-responsive.js 一致）。
     * min/max 为含端点像素；null 表示不设限。
     */
    public const TIERS = [
        ['key' => 'm', 'device' => 'mobile', 'min' => null, 'max' => 767],
        ['key' => 't', 'device' => 'tablet', 'min' => 768, 'max' => 1023],
        ['key' => 'd', 'device' => 'desktop', 'min' => 1024, 'max' => 1439],
        ['key' => 'w', 'device' => 'wide', 'min' => 1440, 'max' => null],
    ];

    private static ?bool $wideOverride = null;

    public static function wideEnabled(): bool
    {
        if (self::$wideOverride !== null) {
            return self::$wideOverride;
        }
        return !function_exists('config') || (string) config(self::WIDE_SETTING_KEY, '1') !== '0';
    }

    /** 测试与预览用：null 恢复读取站点设置。 */
    public static function overrideWideEnabled(?bool $enabled): void
    {
        self::$wideOverride = $enabled;
    }

    /**
     * 当前生效的档位：宽屏关闭时去掉 w，桌面档不设上限。
     * @return list<array{key:string,device:string,min:?int,max:?int}>
     */
    public static function tiers(): array
    {
        if (self::wideEnabled()) {
            return self::TIERS;
        }
        $tiers = array_slice(self::TIERS, 0, 3);
        $tiers[2]['max'] = null;
        return $tiers;
    }

    /**
     * Expand a scalar or partial responsive value into the canonical {d,t,m,w} shape.
     * Tablet inherits desktop; mobile inherits tablet; widescreen (≥1440) inherits desktop.
     *
     * @param array<int|string,mixed> $allowed Map whose keys are valid values.
     * @return array{d:mixed,t:mixed,m:mixed,w:mixed}
     */
    public static function normalize(mixed $value, array $allowed, mixed $fallback): array
    {
        $fallback = self::allowed($fallback, $allowed) ? $fallback : array_key_first($allowed);
        if (!is_array($value)) {
            $scalar = self::allowed($value, $allowed) ? $value : $fallback;
            return ['d' => $scalar, 't' => $scalar, 'm' => $scalar, 'w' => $scalar];
        }

        $canonical = [];
        foreach ($value as $device => $candidate) {
            $key = self::DEVICE_ALIASES[strtolower((string) $device)] ?? null;
            if ($key !== null && self::allowed($candidate, $allowed)) {
                $canonical[$key] = $candidate;
            }
        }

        $desktop = $canonical['d'] ?? $fallback;
        $tablet = $canonical['t'] ?? $desktop;
        $mobile = $canonical['m'] ?? $tablet;
        $wide = self::wideEnabled() ? ($canonical['w'] ?? $desktop) : $desktop;
        return ['d' => $desktop, 't' => $tablet, 'm' => $mobile, 'w' => $wide];
    }

    /**
     * Preserve valid legacy scalars and canonicalize only explicitly stored tiers.
     * Missing tablet/mobile keys remain meaningful inheritance markers.
     */
    public static function normalizeStored(mixed $value, array $allowed, mixed $fallback): mixed
    {
        $fallback = self::allowed($fallback, $allowed) ? $fallback : array_key_first($allowed);
        if (!is_array($value)) {
            return self::allowed($value, $allowed) ? $value : $fallback;
        }

        $canonical = [];
        foreach ($value as $device => $candidate) {
            $key = self::DEVICE_ALIASES[strtolower((string) $device)] ?? null;
            if ($key !== null && self::allowed($candidate, $allowed)) {
                $canonical[$key] = $candidate;
            }
        }

        if ($canonical === []) {
            return $fallback;
        }
        if (array_keys($canonical) === ['d']) {
            return $canonical['d'];
        }
        return $canonical;
    }

    /** @psalm-suppress PossiblyUnusedMethod 公开值协议 API（编辑器/插件/测试消费） */
    public static function forDevice(mixed $value, string $device, array $allowed, mixed $fallback): mixed
    {
        $key = self::DEVICE_ALIASES[strtolower($device)] ?? 'd';
        return self::normalize($value, $allowed, $fallback)[$key];
    }

    /** @param array<int|string,mixed> $allowed */
    private static function allowed(mixed $value, array $allowed): bool
    {
        return is_int($value) || is_string($value)
            ? array_key_exists($value, $allowed)
            : false;
    }
}
