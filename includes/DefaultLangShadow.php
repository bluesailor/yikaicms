<?php
declare(strict_types=1);

/**
 * 默认语言后缀行（<key>_<默认语言>）的归位规则。
 *
 * 前台 configLang 后缀优先，默认语言却只该写在 base 行；残留的后缀行会遮蔽后台的修改。
 * 迁移 20260810_normalize_default_lang_shadow（修存量站）与整站模板导入（包里自带
 * 默认语言后缀行）共用这一套规则，免得两处判定漂移、导入后又让该迁移翻回「待执行」。
 */
final class DefaultLangShadow
{
    /** 保留 base（站长编辑过的内容） */
    public const KEEP_BASE = 3;

    /**
     * 本规则只对不含汉字书写系统的默认语言成立：zh-CN / zh-TW / ja 站的 CJK 启发无效，
     * 这类站也基本不生此病（中文默认站的写读路径是自洽的）。
     */
    public static function applies(string $defaultLang): bool
    {
        return $defaultLang !== '' && !in_array($defaultLang, ['zh-CN', 'zh-TW', 'ja'], true);
    }

    /**
     * 对照同名 base 行决定谁留下：
     *   1 base 不存在或为空          → 后缀值提升为 base（显示不变）
     *   2 base 含 CJK 而后缀不含     → base 是没动过的中文种子，后缀提升覆盖（显示不变）
     *   3 其余（base 是站长的编辑）  → 保留 base（站长的修改终于生效）
     */
    public static function rule(?string $base, string $suffix): int
    {
        if ($base === null || trim($base) === '') {
            return 1;
        }
        $cjk = static fn(string $s): bool => (bool) preg_match('/[\x{4e00}-\x{9fff}]/u', $s);
        return $cjk($base) && !$cjk($suffix) ? 2 : self::KEEP_BASE;
    }

    /**
     * 把一组设置里的默认语言后缀行并进 base（整站模板包导入前用）。
     *
     * @param array<string,string> $settings
     * @param callable(string):bool $baseAllowed base 键不可写入时保持原样
     * @return array<string,string>
     */
    public static function fold(array $settings, string $defaultLang, callable $baseAllowed): array
    {
        if (!self::applies($defaultLang)) {
            return $settings;
        }
        $suffix = '_' . $defaultLang;
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if (!str_ends_with($key, $suffix) || strlen($key) === strlen($suffix)) {
                continue;
            }
            $baseKey = substr($key, 0, -strlen($suffix));
            if (!$baseAllowed($baseKey)) {
                continue;
            }
            if (self::rule($settings[$baseKey] ?? null, (string) $value) !== self::KEEP_BASE) {
                $settings[$baseKey] = (string) $value;
            }
            unset($settings[$key]);
        }
        return $settings;
    }
}
