<?php
/**
 * 站点侧的语言包操作：把 LanguagePacks（纯逻辑、无数据库）接上本站的版本、公钥、设置与文案。
 * 语言设置页、铃铛「更新语言包」共用；安装向导在建库前直接用 LanguagePacks。
 */

declare(strict_types=1);

require_once __DIR__ . '/LanguagePacks.php';

final class LanguagePackSite
{
    /**
     * 正在使用、不能卸载的语言：前台启用的、默认的、后台的。
     * 前台启用取 enabledLanguages()：enabled_languages 为空时前台确实对外提供全部已装语言。
     *
     * @return list<string>
     */
    public static function inUse(): array
    {
        $codes = array_keys(enabledLanguages());
        $codes[] = (string) config('site_lang', 'zh-CN');
        $codes[] = (string) config('admin_lang', 'zh-CN');
        return array_values(array_unique($codes));
    }

    /** @return array{code:string, version:string} */
    public static function install(string $code): array
    {
        $result = LanguagePacks::download(ROOT_PATH, $code, CMS_VERSION, self::publicKey(), [LanguagePacks::class, 'httpFetch']);
        self::changed('lang_pack_install', $result['code'] . ' ' . $result['version']);
        return $result;
    }

    /** @return array{code:string, version:string} */
    public static function upload(string $zipBytes): array
    {
        $result = LanguagePacks::installFromZip(ROOT_PATH, $zipBytes, CMS_VERSION, self::publicKey());
        self::changed('lang_pack_upload', $result['code'] . ' ' . $result['version']);
        return $result;
    }

    public static function uninstall(string $code): void
    {
        LanguagePacks::uninstall(ROOT_PATH, $code, self::inUse());
        self::changed('lang_pack_uninstall', $code);
    }

    /**
     * 需要更新的语言包——只算正在使用的。2.1 之前的安装包带全部 18 种，存量站（和开发检出）
     * 都有一堆没用到、也没有记录的 lang/*.php；它们不影响任何页面，不值得提醒、也不必下载。
     *
     * @return list<string>
     */
    public static function outdated(): array
    {
        return array_values(array_intersect(LanguagePacks::outdated(ROOT_PATH, CMS_VERSION), self::inUse()));
    }

    /**
     * 把已装的旧版本语言包逐个换成本版本。逐个容错：一种失败不影响其余，原文件保留可用。
     *
     * @return array{updated: list<string>, failed: array<string,string>}
     */
    public static function updateOutdated(): array
    {
        $updated = [];
        $failed = [];
        foreach (self::outdated() as $code) {
            try {
                LanguagePacks::download(ROOT_PATH, $code, CMS_VERSION, self::publicKey(), [LanguagePacks::class, 'httpFetch']);
                $updated[] = $code;
            } catch (LanguagePackException $e) {
                $failed[$code] = $e->reason();
            }
        }
        if ($updated !== []) {
            self::changed('lang_pack_update', implode(',', $updated));
        }
        return ['updated' => $updated, 'failed' => $failed];
    }

    public static function message(LanguagePackException $e): string
    {
        $key = 'lpack_err_' . $e->reason();
        $text = __($key);
        return $text !== $key ? $text : __('lpack_err_bad_zip');
    }

    private static function publicKey(): string
    {
        require_once dirname(__DIR__) . '/License.php';
        return license_pubkey();
    }

    private static function changed(string $action, string $detail): void
    {
        if (function_exists('adminLog')) {
            adminLog('setting', $action, $detail);
        }
        if (function_exists('do_action')) {
            do_action('data_changed');   // 前台整页缓存里带着语言切换器与 hreflang
        }
    }
}
