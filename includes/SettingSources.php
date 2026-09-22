<?php
declare(strict_types=1);

/** 只报告优先级，不改覆盖文件，也不把凭据或任意配置正文显示在后台。 */
final class SettingSources
{
    public static function inspect(string $key, array $stored, array $file, array $runtime): array
    {
        $source = array_key_exists($key, $runtime) ? 'runtime'
            : (array_key_exists($key, $file) ? 'file' : 'database');
        $value = match ($source) {
            'runtime' => $runtime[$key],
            'file' => $file[$key],
            default => $stored[$key] ?? '',
        };
        return ['source' => $source, 'locked' => $source !== 'database',
            'effective' => self::display($key, $value), 'stored' => self::display($key, $stored[$key] ?? '')];
    }

    private static function display(string $key, mixed $value): string
    {
        // 仅展示无凭据的短标识；URL、JSON、邮件参数等一律遮蔽。
        if (!preg_match('/^(?:site_name(?:_[a-zA-Z-]+)?|current_theme|motion_intensity|url_mode|primary_color|secondary_color)$/D', $key)
            || !is_scalar($value)) return '••••';
        return mb_substr((string) $value, 0, 160);
    }
}
