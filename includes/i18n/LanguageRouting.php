<?php
/**
 * 语言网址前缀能否真正访问：探针、.htaccess 状态、一键修复与撤销。
 *
 * 为什么需要：升级不会覆盖站点根目录的 .htaccess（UpgradeRunner 把它列为站点自有文件），
 * 老站里仍是 `RewriteRule ^(ja|en|zh-CN|zh-TW)/(.*)$ …` 这种写死语言的规则。新开韩语、德语等
 * 前缀式语言时，/ko/xxx.html 不会被剥前缀，落到栏目别名规则上变成 404。
 *
 * 探针与服务器类型无关：浏览器请求 /<代码>/contact.html?yk_lang_route_probe=<随机数>，
 * 无论最终落到哪个入口文件，init.php 都在语言识别前应答「本次请求被认成了哪种语言」。
 * 修复只认得出历史上随包发过的那几种写法，改一行、保留其余定制；改前备份到 storage/，
 * 后台改完立刻重测，有语言变得不可访问就自动还原（某些虚拟主机的 DOCUMENT_ROOT 不可靠）。
 */

declare(strict_types=1);

require_once __DIR__ . '/LanguageRegistry.php';

final class LanguageRouting
{
    public const PROBE_PARAM = 'yk_lang_route_probe';

    /** 随包规则的标志（.htaccess 与 deploy/aliyun-vhost.htaccess 相同）。 */
    private const GENERIC_RULE = 'RewriteRule ^([a-z]{2}(?:-[A-Z]{2})?)/(.*)$';

    /**
     * 历史写法：RewriteRule ^(ja|en|zh-CN|zh-TW)/(.*)$ <目标前缀>$2?_lang=$1 [标志]
     * 目标前缀是 %{ENV:YK_BASE}（2.0 起）或 /…/（更早，RewriteBase 写死的根目录或子目录）。
     */
    private const LEGACY_RULE = '/^([ \t]*)RewriteRule[ \t]+\^\(((?:[A-Za-z]{2}(?:-[A-Za-z]{2})?\|)*[A-Za-z]{2}(?:-[A-Za-z]{2})?)\)\/\(\.\*\)\$[ \t]+(%\{ENV:YK_BASE\}|\/[A-Za-z0-9_\/.-]*?)\$2\?_lang=\$1[ \t]+(\[[A-Z,]+\])[ \t]*(?=\r?$)/m';

    /** init.php 在语言识别之前调用：回答「这次请求带来的 _lang 是什么」。 */
    public static function respondProbe(): void
    {
        $nonce = $_GET[self::PROBE_PARAM] ?? null;
        if (!is_string($nonce) || preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1) return;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        $lang = $_GET['_lang'] ?? '';
        echo json_encode(['probe' => 'yikai-lang-route', 'nonce' => $nonce, 'lang' => is_string($lang) ? $lang : '']);
        exit;
    }

    /**
     * 走前缀的语言：已启用、非默认、装了语言包、没有独立语言域名的语言（没装语言包的本来就不按前缀路由）。
     * @param list<string> $enabled
     * @return list<string>
     */
    public static function prefixLanguages(array $enabled, string $defaultLang): array
    {
        $out = [];
        foreach ($enabled as $code) {
            if (!is_string($code) || $code === $defaultLang || !LanguageRegistry::has($code)) continue;
            if (!is_file(dirname(__DIR__, 2) . '/lang/' . $code . '.php')) continue;
            if (class_exists('LanguageDomains') && LanguageDomains::hostFor($code) !== null) continue;
            $out[] = $code;
        }
        return $out;
    }

    /**
     * .htaccess 里的语言前缀规则状态。
     *   none    没有 .htaccess（nginx 等，只能靠探针判断）
     *   current 已是随包的通用规则
     *   legacy  历史写法，可一键修复；codes 是它写死的语言
     *   custom  有 .htaccess 但认不出语言规则（站长自定义过），不自动改
     *
     * @return array{state:string, codes:list<string>}
     */
    public static function htaccessStatus(string $root): array
    {
        $file = $root . '/.htaccess';
        if (!is_file($file)) return ['state' => 'none', 'codes' => []];
        $text = (string) @file_get_contents($file);
        if (str_contains($text, self::GENERIC_RULE)) return ['state' => 'current', 'codes' => []];
        if (preg_match_all(self::LEGACY_RULE, $text, $m) === 1) {
            return ['state' => 'legacy', 'codes' => explode('|', $m[2][0])];
        }
        return ['state' => 'custom', 'codes' => []];
    }

    /**
     * 历史规则没覆盖到的前缀式语言（这些语言的 /xx/ 网址在 Apache 上打不开）。
     * @param list<string> $legacyCodes
     * @param list<string> $prefixLanguages
     * @return list<string>
     */
    public static function uncovered(array $legacyCodes, array $prefixLanguages): array
    {
        return array_values(array_diff($prefixLanguages, $legacyCodes));
    }

    /** 把历史规则替换成通用规则后的全文；认不出（或多于一处）时返回 null。 */
    public static function upgradedHtaccess(string $text): ?string
    {
        if (preg_match_all(self::LEGACY_RULE, $text, $m, PREG_OFFSET_CAPTURE) !== 1) return null;
        $nl = str_contains($text, "\r\n") ? "\r\n" : "\n";
        $indent = $m[1][0][0];
        $target = $m[3][0][0];
        $flags = $m[4][0][0];
        $docRoot = $target === '%{ENV:YK_BASE}' ? '%{DOCUMENT_ROOT}%{ENV:YK_BASE}' : '%{DOCUMENT_ROOT}' . $target;
        $block = $indent . '# 通用语言前缀（YikaiCMS 后台一键更新）：装了 lang/<代码>.php 的语言才剥前缀，新增语言不用再改本文件' . $nl
            . $indent . 'RewriteCond ' . $docRoot . 'lang/$1.php -f' . $nl
            . $indent . self::GENERIC_RULE . ' ' . $target . '$2?_lang=$1 ' . $flags;
        $offset = (int) $m[0][0][1];
        return substr($text, 0, $offset) . $block . substr($text, $offset + strlen($m[0][0][0]));
    }

    /**
     * 一键修复：备份 → 原子替换 → 回读核对。备份放在 storage/（Web 不可访问）。
     * @return array{ok:bool, backup:string, error:string}
     */
    public static function fixHtaccess(string $root, string $backupDir): array
    {
        $file = $root . '/.htaccess';
        $text = is_file($file) ? @file_get_contents($file) : false;
        if (!is_string($text)) return ['ok' => false, 'backup' => '', 'error' => 'read'];
        $new = self::upgradedHtaccess($text);
        if ($new === null) return ['ok' => false, 'backup' => '', 'error' => 'unrecognized'];
        if (!is_dir($backupDir) && !@mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
            return ['ok' => false, 'backup' => '', 'error' => 'backup'];
        }
        $name = 'htaccess-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.bak';
        if (@file_put_contents($backupDir . '/' . $name, $text, LOCK_EX) !== strlen($text)) {
            return ['ok' => false, 'backup' => '', 'error' => 'backup'];
        }
        if (!self::writeAtomic($file, $new)) return ['ok' => false, 'backup' => $name, 'error' => 'write'];
        return ['ok' => true, 'backup' => $name, 'error' => ''];
    }

    /** 从备份还原（只认 fixHtaccess 产生的文件名）。 */
    public static function restoreHtaccess(string $root, string $backupDir, string $name): bool
    {
        if (preg_match('/^htaccess-\d{8}-\d{6}-[a-f0-9]{6}\.bak$/D', $name) !== 1) return false;
        $text = @file_get_contents($backupDir . '/' . $name);
        return is_string($text) && self::writeAtomic($root . '/.htaccess', $text);
    }

    private static function writeAtomic(string $file, string $content): bool
    {
        $tmp = $file . '.yk-' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content)) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $file)) {
            // Windows 上目标存在时 rename 可能失败：退回直接覆盖
            @unlink($tmp);
            if (@file_put_contents($file, $content, LOCK_EX) !== strlen($content)) return false;
        }
        clearstatcache(true, $file);
        return @file_get_contents($file) === $content;
    }
}
