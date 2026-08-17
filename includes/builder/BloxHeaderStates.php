<?php
/** Header visual states shared by template storage, editor preview and frontend shell. */

declare(strict_types=1);

final class BloxHeaderStates
{
    public const NAMES = ['normal', 'overlay', 'stuck'];
    public const STICKY_BEHAVIORS = ['always', 'scroll-up'];
    public const STICKY_DEVICES = ['desktop', 'tablet', 'mobile'];
    private const SHADOWS = ['none', 'sm', 'md', 'lg'];

    /** @return array<string,array{background:string,text:string,border:string,shadow:string}> */
    public static function defaults(): array
    {
        return [
            'normal' => ['background' => '', 'text' => '', 'border' => '', 'shadow' => 'none'],
            'overlay' => ['background' => 'transparent', 'text' => '#ffffff', 'border' => 'rgba(255,255,255,.18)', 'shadow' => 'none'],
            'stuck' => ['background' => '#ffffff', 'text' => '#111827', 'border' => '#e5e7eb', 'shadow' => 'sm'],
        ];
    }

    /** @return array<string,array{background:string,text:string,border:string,shadow:string}> */
    public static function normalize(mixed $states): array
    {
        $input = is_array($states) ? $states : [];
        $out = [];
        foreach (self::defaults() as $name => $defaults) {
            $candidate = is_array($input[$name] ?? null) ? $input[$name] : [];
            $out[$name] = [
                'background' => self::color($candidate['background'] ?? $defaults['background'], $defaults['background']),
                'text' => self::color($candidate['text'] ?? $defaults['text'], $defaults['text']),
                'border' => self::color($candidate['border'] ?? $defaults['border'], $defaults['border']),
                'shadow' => in_array((string) ($candidate['shadow'] ?? ''), self::SHADOWS, true)
                    ? (string) $candidate['shadow']
                    : $defaults['shadow'],
            ];
        }
        return $out;
    }

    public static function shadowCss(string $shadow): string
    {
        return match ($shadow) {
            'sm' => '0 2px 12px rgba(15,23,42,.08)',
            'md' => '0 8px 24px rgba(15,23,42,.14)',
            'lg' => '0 14px 36px rgba(15,23,42,.2)',
            default => 'none',
        };
    }

    public static function normalizeStickyBehavior(mixed $behavior): string
    {
        $behavior = is_string($behavior) ? trim($behavior) : '';
        return in_array($behavior, self::STICKY_BEHAVIORS, true) ? $behavior : 'always';
    }

    /** @return list<string> */
    public static function normalizeStickyDevices(mixed $devices): array
    {
        if ($devices === null) {
            return self::STICKY_DEVICES;
        }
        if (is_string($devices)) {
            $devices = explode(',', $devices);
        }
        $devices = is_array($devices) ? $devices : [];
        $normalized = array_values(array_filter(
            self::STICKY_DEVICES,
            static fn(string $device): bool => in_array($device, $devices, true)
        ));
        return $normalized !== [] ? $normalized : self::STICKY_DEVICES;
    }

    private static function color(mixed $value, string $fallback): string
    {
        if ($value === '') {
            return '';
        }
        return AbstractElement::cssColor($value) ?? $fallback;
    }
}
