<?php
/** Blox 图标值：无前缀默认使用 Tabler，bi:<name> 使用 Bootstrap Icons。 */

declare(strict_types=1);

final class BloxIcon
{
    public const BOOTSTRAP_STYLESHEET = '/assets/bootstrap-icons/bootstrap-icons.min.css';

    /**
     * @return array{library:'tabler'|'bootstrap',name:string,value:string}
     */
    public static function parse(mixed $value, string $fallback = 'star'): array
    {
        $raw = is_string($value) ? strtolower(trim($value)) : '';
        $library = 'tabler';
        $name = $raw;

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
