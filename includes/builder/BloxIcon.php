<?php
/**
 * Blox 图标值：无前缀默认使用 Tabler，bi:<name> 使用 Bootstrap Icons；
 * 插件 / 主题注册的图标集（2.0.4）用自己的前缀 <prefix>:<name>，见 registerSet()。
 */

declare(strict_types=1);

final class BloxIcon
{
    public const SITE_STYLESHEET = '/assets/icons/site-icons.min.css';
    public const TABLER_STYLESHEET = '/assets/tabler/tabler-icons.min.css';
    public const BOOTSTRAP_STYLESHEET = '/assets/bootstrap-icons/bootstrap-icons.min.css';

    private const MOTIONS = ['pulse', 'ring', 'slide', 'spin', 'sparkle', 'lift'];
    private const NAME_PATTERN = '/^[a-z0-9][a-z0-9-]{0,79}$/';
    private const SET_PREFIX_PATTERN = '/^[a-z][a-z0-9]{1,15}$/';
    /** 内置前缀，注册时不可占用 */
    private const RESERVED_PREFIXES = ['ti', 'tabler', 'bi', 'none'];
    private const MAX_SET_ICONS = 5000;

    /** @var array<string,array{prefix:string,label:string,stylesheet:string,class:string,icons:list<string>}> */
    private static array $sets = [];

    /**
     * 注册图标集（插件 / 主题在加载时调用，或挂 blox_icon_sets 过滤器返回同样结构）。
     * - prefix：2–16 位小写字母数字，图标值写作 prefix:name；
     * - stylesheet：站内 /assets/、/plugins/ 或 /themes/<主题>/assets/ 下的 .css（前台只在页面用到该图标集时加载）；
     * - class：类名模板，{name} 处换成图标名，如 "lucide lucide-{name}"；
     * - icons：图标名清单（编辑器选择器用；可选）。
     * 不合法的注册静默忽略，返回是否成功。
     *
     * @param array<string,mixed> $set
     * @psalm-suppress PossiblyUnusedMethod, PossiblyUnusedReturnValue 插件 / 主题 API，调用方不随本仓库分发
     */
    public static function registerSet(string $prefix, array $set): bool
    {
        $normalized = self::normalizeSet($prefix, $set);
        if ($normalized === null) {
            return false;
        }
        self::$sets[$normalized['prefix']] = $normalized;
        return true;
    }

    /** @return array<string,array{prefix:string,label:string,stylesheet:string,class:string,icons:list<string>}> */
    public static function sets(): array
    {
        $sets = self::$sets;
        if (function_exists('apply_filters')) {
            $filtered = apply_filters('blox_icon_sets', []);
            foreach (is_array($filtered) ? $filtered : [] as $key => $set) {
                $prefix = is_array($set) && is_string($set['prefix'] ?? null) ? $set['prefix'] : (is_string($key) ? $key : '');
                $normalized = is_array($set) ? self::normalizeSet($prefix, $set) : null;
                if ($normalized !== null && !isset($sets[$normalized['prefix']])) {
                    $sets[$normalized['prefix']] = $normalized;
                }
            }
        }
        return $sets;
    }

    /** @psalm-suppress PossiblyUnusedMethod 单测隔离用 */
    public static function resetSetsForTests(): void
    {
        self::$sets = [];
    }

    /**
     * 编辑器用的图标集目录（不含内置的 Tabler / Bootstrap）。
     *
     * @return list<array{prefix:string,label:string,stylesheet:string,class:string,icons:list<string>}>
     * @psalm-suppress PossiblyUnusedMethod 后台编辑器入口通过 JSON 消费
     */
    public static function editorSets(): array
    {
        return array_values(self::sets());
    }

    /**
     * @param array<string,mixed> $set
     * @return array{prefix:string,label:string,stylesheet:string,class:string,icons:list<string>}|null
     */
    private static function normalizeSet(string $prefix, array $set): ?array
    {
        $prefix = strtolower(trim($prefix));
        if (preg_match(self::SET_PREFIX_PATTERN, $prefix) !== 1 || in_array($prefix, self::RESERVED_PREFIXES, true)) {
            return null;
        }
        $stylesheet = is_string($set['stylesheet'] ?? null) ? trim($set['stylesheet']) : '';
        // 与 BloxAssetCollector 的本地资源规则一致：只收站内 assets / plugins / 主题 assets 下的 .css
        if (preg_match('#^/(?:assets/|plugins/|themes/[a-zA-Z0-9_-]+/assets/)[a-zA-Z0-9_./-]+\.css$#', $stylesheet) !== 1 || str_contains($stylesheet, '..')) {
            return null;
        }
        $class = is_string($set['class'] ?? null) ? trim($set['class']) : '';
        if (!str_contains($class, '{name}') || preg_match('/^[A-Za-z0-9 _{}-]{1,80}$/', $class) !== 1) {
            return null;
        }
        $icons = [];
        foreach (is_array($set['icons'] ?? null) ? $set['icons'] : [] as $name) {
            if (is_string($name) && preg_match(self::NAME_PATTERN, strtolower($name)) === 1) {
                $icons[strtolower($name)] = true;
                if (count($icons) >= self::MAX_SET_ICONS) {
                    break;
                }
            }
        }
        $label = is_string($set['label'] ?? null) ? mb_substr(trim($set['label']), 0, 30) : '';
        return [
            'prefix' => $prefix,
            'label' => $label !== '' ? $label : $prefix,
            'stylesheet' => $stylesheet,
            'class' => preg_replace('/\s+/', ' ', $class) ?? $class,
            'icons' => array_keys($icons),
        ];
    }

    /**
     * @return array{library:string,name:string,value:string}
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
            // 注册图标集：prefix:name；未注册（如插件已停用）回落到默认图标
            [$prefix, $rest] = explode(':', $raw, 2);
            $name = '';
            if (isset(self::sets()[$prefix]) && preg_match(self::NAME_PATTERN, $rest) === 1) {
                $library = $prefix;
                $name = $rest;
            }
        } else {
            // 兼容旧元素：过去会移除非法字符后继续使用该 Tabler 类名。
            $name = preg_replace('/[^a-z0-9-]/', '', $raw) ?? '';
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
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
            'value' => match ($library) {
                'tabler' => $name,
                'bootstrap' => 'bi:' . $name,
                default => $library . ':' . $name,
            },
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
        $stylesheet = self::stylesheetFor($icon);
        // 前台固定加载常用子集；站点数据/插件选择了子集外图标时，完整字体按需进
        // BloxAssetCollector。这样旧内容不缺图，普通页面又不再承担两套完整字体。
        if ($stylesheet !== null && class_exists(BloxAssetCollector::class)) {
            BloxAssetCollector::addStyle($stylesheet);
        }
        return match ($icon['library']) {
            'tabler' => 'ti ti-' . $icon['name'],
            'bootstrap' => 'bi bi-' . $icon['name'],
            default => str_replace('{name}', $icon['name'], self::sets()[$icon['library']]['class'] ?? 'ti ti-{name}'),
        };
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
        return self::stylesheetFor(self::parse($value));
    }

    public static function isNone(mixed $value): bool
    {
        $icon = self::parse($value);
        return $icon['library'] === 'tabler' && $icon['name'] === 'none';
    }

    /** @param array{library:string,name:string,value:string} $icon */
    private static function stylesheetFor(array $icon): ?string
    {
        if ($icon['library'] === 'tabler' && $icon['name'] === 'none') {
            return null;
        }
        if (!in_array($icon['library'], ['tabler', 'bootstrap'], true)) {
            return self::sets()[$icon['library']]['stylesheet'] ?? null;
        }
        if (self::siteSubsetHas($icon['library'], $icon['name'])) {
            return null;
        }
        return $icon['library'] === 'bootstrap'
            ? self::BOOTSTRAP_STYLESHEET
            : self::TABLER_STYLESHEET;
    }

    private static function siteSubsetHas(string $library, string $name): bool
    {
        /** @var array<string,array<string,true>>|null $catalog */
        static $catalog = null;
        if ($catalog === null) {
            $catalog = ['tabler' => [], 'bootstrap' => []];
            $root = defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 2);
            $path = $root . '/assets/icons/site-icon-audit.json';
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            if (is_array($decoded) && ($decoded['schema'] ?? null) === 1) {
                foreach (['tabler', 'bootstrap'] as $provider) {
                    $names = $decoded['icons'][$provider] ?? null;
                    if (is_array($names)) {
                        foreach ($names as $iconName) {
                            if (is_string($iconName)) {
                                $catalog[$provider][$iconName] = true;
                            }
                        }
                    }
                }
            }
        }
        return isset($catalog[$library][$name]);
    }
}
