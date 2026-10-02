<?php
/**
 * 站点时区（2.0.4，国际化第一步）。
 *
 * 此前时区写死在 config/config.php 的 Asia/Shanghai，后台改不了，海外站点一律按北京时间显示。
 * 现在存在设置 site_timezone（「设置 → 语言与时区」），读设置时由 SettingModel::getAll() 统一生效，
 * 前台、后台、接口、计划任务都按它算。没设置（老站）就保持 config.php 里的默认，行为不变。
 *
 * 时间基本都存成 Unix 时间戳，与时区无关：改时区只改变「怎么显示」和「后台填的时间按哪里理解」，
 * 不用迁移数据；定时发布照样在原定那一刻上线。
 *
 * 借鉴 WordPress：以城市为主（能处理夏令时），设置旁显示当前本地时间。
 * 不照搬它「内部固定 UTC、显示再换算」——它要防插件乱改默认时区，我们的代码都在自己手里。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class SiteTimezone
{
    public const KEY = 'site_timezone';
    /** 老站与安装时没给时区的兜底，与 config.sample.php 一致 */
    public const FALLBACK = 'Asia/Shanghai';

    private static ?string $applied = null;
    /** @var array<string,true>|null */
    private static ?array $known = null;

    /** 是否为 PHP 认得的时区标识（Asia/Shanghai、UTC 这类；不收「+08:00」偏移） */
    public static function valid(mixed $timezone): bool
    {
        if (!is_string($timezone) || $timezone === '') {
            return false;
        }
        self::$known ??= array_fill_keys(timezone_identifiers_list(), true);
        return isset(self::$known[$timezone]);
    }

    /** 读设置时调用：值合法就设为 PHP 默认时区；非法或为空则保持现状 */
    public static function apply(mixed $timezone): void
    {
        $timezone = is_string($timezone) ? trim($timezone) : '';
        if ($timezone === self::$applied || !self::valid($timezone)) {
            return;
        }
        date_default_timezone_set($timezone);
        self::$applied = $timezone;
    }

    /** 当前生效的时区 */
    public static function current(): string
    {
        return date_default_timezone_get();
    }

    /** 与 UTC 的差，如 UTC+08:00、UTC−03:30（按此刻计，含夏令时） */
    public static function offsetLabel(string $timezone, ?int $at = null): string
    {
        $seconds = (new DateTimeZone($timezone))->getOffset(new DateTimeImmutable('@' . ($at ?? time())));
        $sign = $seconds < 0 ? '−' : '+';
        $seconds = abs($seconds);
        return sprintf('UTC%s%02d:%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /** 该时区此刻的本地时间（Y-m-d H:i） */
    public static function localTime(string $timezone, ?int $at = null): string
    {
        return (new DateTimeImmutable('@' . ($at ?? time())))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i');
    }

    /**
     * 下拉选项，按大洲分组；每组按与 UTC 的差、再按名称排序。显示「上海 (UTC+08:00)」这类文字。
     *
     * @return array<string, array<string,string>> 分组名 => [时区标识 => 显示文字]
     */
    public static function groupedOptions(?int $at = null): array
    {
        $groups = [];
        $offsets = [];
        foreach (timezone_identifiers_list() as $id) {
            $region = str_contains($id, '/') ? (string) strstr($id, '/', true) : 'UTC';
            $place = str_contains($id, '/') ? substr($id, strlen($region) + 1) : $id;
            $label = str_replace(['/', '_'], [' / ', ' '], $place) . ' (' . self::offsetLabel($id, $at) . ')';
            $groups[$region][$id] = $label;
            $offsets[$id] = (new DateTimeZone($id))->getOffset(new DateTimeImmutable('@' . ($at ?? time())));
        }
        foreach ($groups as &$items) {
            uksort($items, static fn(string $a, string $b): int => [$offsets[$a], $a] <=> [$offsets[$b], $b]);
        }
        unset($items);
        // 常用地区在前，UTC 放最后
        $order = ['Asia', 'Europe', 'America', 'Africa', 'Australia', 'Pacific', 'Indian', 'Atlantic', 'Antarctica', 'Arctic', 'UTC'];
        uksort($groups, static fn(string $a, string $b): int =>
            [array_search($a, $order, true) === false ? 99 : array_search($a, $order, true), $a]
            <=> [array_search($b, $order, true) === false ? 99 : array_search($b, $order, true), $b]);
        return $groups;
    }
}
