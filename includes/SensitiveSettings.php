<?php
/**
 * Yikai CMS - 配方导出/导入的设置项策略层
 *
 * 键是否敏感，唯一判据是 includes/permissions.php 的 isSensitiveSettingKey()——
 * 那是核心早就有的函数（AI 助手能力闸 includes/abilities/cms_admin.php 也在用它）。
 * 这里**不再写第二份正则**：两份正则必然漂移，而漂移正是这轮要修的病根
 * （旧的四项黑名单里有三项键名与真实键对不上，黑名单写 smtp_password，库里叫 smtp_pass，
 * 于是 cron_token、license_key、seo_indexnow_key 长期被原样导出——
 * cron_token 尤其致命，它就是 DemoSandbox 的站长口令）。
 *
 * 本层在那个判据之上补三件配方特有的事：
 *
 *  1. EXPORT_EXTRA —— 只在「往站外发」这个场景才算敏感的键。
 *     不并进 isSensitiveSettingKey()，是因为那个函数还管着 AI 助手能不能读写设置：
 *     把 ^smtp_ 并进去，会顺手夺走 AI 助手配置邮件服务的能力。
 *     导出比后台操作更该保守，两个场景本就该有不同的松紧。
 *
 *  2. NEVER_IMPORT —— 描述「这台站点是谁 / 这台机器怎么跑」的键。
 *     一份来自模板市场的配方若能写 encrypt_key / cron_token / demo_mode，
 *     等于让配方作者接管目标站；而全新站这些键多半是空的，
 *     RecipeService 里「已有非空值则跳过」那层保护根本挡不住。
 *
 *  3. 值的递归清洗 —— 顶层键名普通、JSON 值内部却藏着 api_key / access_token 的情况，
 *     只看键名的过滤会整块漏出去。
 */
declare(strict_types=1);

/** @psalm-suppress ParadoxicalCondition 直连访问本文件时的守卫；Psalm 按包含顺序已认定 ROOT_PATH 有定义。 */
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

require_once ROOT_PATH . '/includes/permissions.php';

final class SensitiveSettings
{
    /** 仅「导出到站外」时额外视为敏感（不影响后台/AI 助手对这些键的读写） */
    private const EXPORT_EXTRA_PATTERNS = [
        '/^license/i',        // license_state 的键名里没有 key/token，逃得过通用判据
        '/^smtp_/i',          // 主机/端口/账号也是基础设施信息，不该随配方外流
        '/(?:^|_)salt(?:_|$)/i',
        '/(?:^|_)signature(?:_|$)/i',
    ];

    /** 仅「导出到站外」时额外视为敏感的具名键 */
    private const EXPORT_EXTRA_KEYS = [
        'recipe_applied',     // 本站装过哪些配方，属于本站历史
    ];

    /** 配方永远不能写：描述本机身份与运行方式，而非配方内容 */
    private const NEVER_IMPORT = [
        'site_url',
        'demo_mode',
        'demo_reset_interval',
        'installed',
        'install_time',
        'cms_version',
        'db_version',
        'enabled_languages',
        'site_lang',
        'admin_lang',
        'official_media_api_base',
        'static_html_base_url',
        'static_html_enabled',
        'static_html_last_gen',
    ];

    /** 值内部递归清洗时命中即摘除的键名 */
    private const VALUE_KEY_PATTERN = '/(^|_)(key|secret|token|pass|passwd|password|credential|authorization|auth|appid|appkey|access_id)($|_)/i';

    /** 导出场景：键是否敏感 */
    public static function isSensitive(string $key): bool
    {
        $normalized = strtolower(trim($key));
        if ($normalized === '') {
            return false;
        }
        if (isSensitiveSettingKey($key)) {
            return true;
        }
        if (in_array($normalized, self::EXPORT_EXTRA_KEYS, true)) {
            return true;
        }
        foreach (self::EXPORT_EXTRA_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }
        return false;
    }

    /** 配方能否写这个键 */
    public static function isImportable(string $key): bool
    {
        if (self::isSensitive($key)) {
            return false;
        }
        $normalized = strtolower(trim($key));
        static $securityKeys = null;
        if ($securityKeys === null) {
            $defaults = require ROOT_PATH . '/config/defaults.php';
            $securityKeys = array_fill_keys(array_keys($defaults['security'] ?? []), true);
        }
        // Recipes describe content, never this installation's access/upload policy.
        return !isset($securityKeys[$normalized])
            && !in_array($normalized, self::NEVER_IMPORT, true);
    }

    /**
     * 清洗单个设置值：JSON 结构里递归摘除敏感键，非 JSON 原样返回。
     * 顶层键名普通、内部藏着 api_key 的插件配置就是靠这一步拦住的。
     */
    public static function sanitizeValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return $value;
        }
        try {
            $decoded = json_decode($trimmed, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $value;
        }
        if (!is_array($decoded)) {
            return $value;
        }
        $cleaned = self::stripSensitiveNodes($decoded);
        $encoded = json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $encoded === false ? $value : $encoded;
    }

    /** @param array<array-key,mixed> $node @return array<array-key,mixed> */
    private static function stripSensitiveNodes(array $node): array
    {
        $result = [];
        foreach ($node as $key => $value) {
            if (is_string($key) && preg_match(self::VALUE_KEY_PATTERN, $key) === 1) {
                continue;
            }
            $result[$key] = is_array($value) ? self::stripSensitiveNodes($value) : $value;
        }
        return $result;
    }

    /**
     * 过滤出可导出的设置。
     *
     * @param array<string,mixed> $settings
     * @param list<string> $extraExcluded 调用方额外指定的排除键；只能继续收紧，不能把 secret 加回来
     * @return array{settings:array<string,string>,excluded:list<string>}
     */
    public static function filterExportable(array $settings, array $extraExcluded = []): array
    {
        $extra = array_fill_keys(
            array_map(static fn ($k): string => strtolower(trim((string) $k)), $extraExcluded),
            true
        );
        $kept = [];
        $excluded = [];
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (!self::isImportable($key) || isset($extra[strtolower(trim($key))])) {
                $excluded[] = $key;
                continue;
            }
            $kept[$key] = self::sanitizeValue((string) $value);
        }
        sort($excluded);
        return ['settings' => $kept, 'excluded' => $excluded];
    }

    /**
     * 过滤出可导入的设置。
     *
     * @param array<string,mixed> $settings
     * @return array{settings:array<string,string>,rejected:list<string>}
     */
    public static function filterImportable(array $settings): array
    {
        $kept = [];
        $rejected = [];
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (!self::isImportable($key)) {
                $rejected[] = $key;
                continue;
            }
            $kept[$key] = self::sanitizeValue((string) $value);
        }
        sort($rejected);
        return ['settings' => $kept, 'rejected' => $rejected];
    }
}
