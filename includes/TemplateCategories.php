<?php
/**
 * 模板行业分类的读取入口（数据在 config/template-categories.php）。
 * 主题校验、整站模板市场目录准备、后台模板市场页都从这里取，不要另写列表。
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n/LanguageRegistry.php';

final class TemplateCategories
{
    /** @var array{groups:array<string,array<string,string>>,aliases:array<string,string>}|null */
    private static ?array $data = null;

    /** @return array{groups:array<string,array<string,string>>,aliases:array<string,string>} */
    private static function data(): array
    {
        if (self::$data === null) {
            $raw = require dirname(__DIR__) . '/config/template-categories.php';
            self::$data = ['groups' => (array) ($raw['groups'] ?? []), 'aliases' => (array) ($raw['aliases'] ?? [])];
        }
        return self::$data;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::data()['groups']);
    }

    public static function has(string $key): bool
    {
        return isset(self::data()['groups'][$key]);
    }

    /** 旧分类键对应的现行分组；不是旧键时返回 null。 */
    public static function aliasOf(string $key): ?string
    {
        return self::data()['aliases'][$key] ?? null;
    }

    /** 现行分组键：本身合法原样返回，旧键换成新键，未知返回空串。 */
    public static function normalize(string $key): string
    {
        $key = strtolower(trim($key));
        if (self::has($key)) return $key;
        return self::aliasOf($key) ?? '';
    }

    /** 分组在某语言下的名称（缺译按注册表回落：先英文、再中文）；未知分组返回空串。 */
    public static function label(string $key, string $lang): string
    {
        $names = self::data()['groups'][self::normalize($key)] ?? null;
        return is_array($names) ? LanguageRegistry::localizedValue($names, $lang) : '';
    }

    /**
     * 目录条目里的 category_name / category_name_<语言> 字段（无后缀的是中文）。
     * @return array<string,string>
     * @psalm-suppress PossiblyUnusedMethod 调用方是 tools/prepare-site-template-market.php（不在 Psalm projectFiles 内）
     */
    public static function catalogNameFields(string $key): array
    {
        $names = self::data()['groups'][self::normalize($key)] ?? [];
        $fields = [];
        foreach ($names as $code => $name) {
            $fields[$code === 'zh-CN' ? 'category_name' : 'category_name_' . $code] = (string) $name;
        }
        return $fields;
    }
}
