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
    ];

    /**
     * Expand a scalar or partial responsive value into the canonical {d,t,m} shape.
     * Tablet inherits desktop; mobile inherits tablet.
     *
     * @param array<int|string,mixed> $allowed Map whose keys are valid values.
     * @return array{d:mixed,t:mixed,m:mixed}
     */
    public static function normalize(mixed $value, array $allowed, mixed $fallback): array
    {
        $fallback = self::allowed($fallback, $allowed) ? $fallback : array_key_first($allowed);
        if (!is_array($value)) {
            $scalar = self::allowed($value, $allowed) ? $value : $fallback;
            return ['d' => $scalar, 't' => $scalar, 'm' => $scalar];
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
        return ['d' => $desktop, 't' => $tablet, 'm' => $mobile];
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
