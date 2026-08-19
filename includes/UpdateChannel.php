<?php

declare(strict_types=1);

/** 后台首页与两个升级入口共用的更新订阅。 */
final class UpdateChannel
{
    public const STABLE = 'stable';
    public const BETA = 'beta';

    public static function normalize(mixed $channel): string
    {
        return trim((string) $channel) === self::BETA ? self::BETA : self::STABLE;
    }

    public static function current(): string
    {
        $value = function_exists('config') ? config('update_channel', self::STABLE) : self::STABLE;
        return self::normalize($value);
    }
}
