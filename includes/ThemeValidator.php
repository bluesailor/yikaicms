<?php
/**
 * 主题包校验器（theme.json Schema v1）
 *
 * 规范见 yikaicms-docs/theme-schema.md。
 *
 * 此前主题安装只查一件事——theme.json 里有没有 name。于是要求 CMS 1.20 的主题
 * 也照装不误，缺 layouts/header.php 的包装完切过去才发现整站白屏。
 *
 * 设计原则：
 *   - errors 拒绝安装，warnings 只提示。宁可多几条警告，不轻易拒绝——
 *     主题是第三方产物，过严会把能用的包挡在外面。
 *   - **v1 之前的包（没有 schema_version）一律宽松处理**：只校验必需目录与 name，
 *     其余降为警告。已经装好的旧主题绝不能因为新规范而失效。
 *
 * PHP 8.0+
 */

declare(strict_types=1);
require_once __DIR__ . '/TemplateCategories.php';

/** @psalm-suppress ParadoxicalCondition Direct access can load this file without the application bootstrap. */
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

final class ThemeValidator
{
    /** 当前 Schema 版本 */
    public const SCHEMA_VERSION = 1;

    /**
     * 分类词表：见 config/template-categories.php（与模板市场、官网共用）。不在表内只警告——
     * 总会有没预料到的行业；旧词表里的值（services、tech…）提示改成现在的分组。
     * @deprecated 仅为兼容外部引用保留，读 TemplateCategories::keys()
     */
    public const CATEGORIES = [
        'general', 'tech', 'hospitality', 'manufacturing', 'construction', 'recycling', 'trade', 'food', 'home',
        'service', 'health', 'auto', 'energy', 'creative',
    ];

    /** 缺了就无法渲染的文件（相对主题目录） */
    public const REQUIRED_FILES = ['layouts/header.php', 'layouts/footer.php'];

    /**
     * 校验一个主题目录。
     *
     * @param string $dir  主题目录绝对路径
     * @param string $slug 主题标识（目录名）
     * @return array{errors: list<string>, warnings: list<string>, meta: array<string,mixed>}
     */
    /** theme.json stylesheets 的单项：相对主题 assets/ 的 .css 路径，禁止回溯、绝对路径与外链。 */
    public static function stylesheetPath(string $path): bool
    {
        return strlen($path) <= 160
            && preg_match('#^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*\.css$#D', $path) === 1
            && !str_contains($path, '..');
    }

    public static function validateDir(string $dir, string $slug): array
    {
        $dir = rtrim($dir, '/\\');
        $jsonPath = $dir . '/theme.json';

        if (!is_file($jsonPath)) {
            return ['errors' => ['缺少 theme.json'], 'warnings' => [], 'meta' => []];
        }
        $raw = (string) @file_get_contents($jsonPath);
        $meta = json_decode($raw, true);
        if (!is_array($meta)) {
            return ['errors' => ['theme.json 不是合法 JSON：' . json_last_error_msg()], 'warnings' => [], 'meta' => []];
        }

        $r = self::validateMeta($meta, $slug);

        // 目录结构（只有拿得到目录时才查——ZIP 校验走 validateMeta + 调用方自查条目）
        foreach (self::REQUIRED_FILES as $f) {
            if (!is_file($dir . '/' . $f)) {
                $r['errors'][] = "缺少 {$f}（主题无法渲染）";
            }
        }
        $shot = (string) ($meta['screenshot'] ?? '');
        if ($shot !== '' && !is_file($dir . '/' . ltrim($shot, '/'))) {
            $r['warnings'][] = "screenshot 指向的文件不存在：{$shot}";
        }
        foreach (is_array($meta['stylesheets'] ?? null) ? $meta['stylesheets'] : [] as $sheet) {
            if (is_string($sheet) && self::stylesheetPath($sheet) && !is_file($dir . '/assets/' . $sheet)) {
                $r['errors'][] = "stylesheets 指向的文件不存在：assets/{$sheet}";
            }
        }

        $r['meta'] = $meta;
        return $r;
    }

    /**
     * 只校验元数据（不碰文件系统），供 ZIP 安装在解压前使用。
     *
     * @param array<string,mixed> $meta
     * @param bool $checkPlugins false：整站模板检查包时不在这里拦插件——所需插件由导入流程列出并可一键安装，
     *                          真正安装主题（ThemeInstaller）时仍按 true 严格检查
     * @return array{errors: list<string>, warnings: list<string>, meta: array<string,mixed>}
     */
    public static function validateMeta(array $meta, string $slug = '', bool $checkPlugins = true): array
    {
        $errors = [];
        $warnings = [];

        if ($slug !== '' && preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug) !== 1) {
            $errors[] = "主题标识不合法：{$slug}（只允许小写字母、数字、连字符）";
        }

        // name 任何版本都必需
        if (empty($meta['name']) || !is_string($meta['name'])) {
            $errors[] = 'theme.json 缺少 name';
        }

        $sv = isset($meta['schema_version']) ? (int) $meta['schema_version'] : 0;
        $legacy = $sv < 1;

        if ($legacy) {
            // v0 旧包：宽松。只提示，不拒绝——存量主题不能因为新规范而失效。
            $warnings[] = '未声明 schema_version，按旧版主题宽松校验（建议补到 ' . self::SCHEMA_VERSION . '）';
        } elseif ($sv > self::SCHEMA_VERSION) {
            $errors[] = "theme.json 的 schema_version 为 {$sv}，本站最高支持 " . self::SCHEMA_VERSION . '，请先升级 CMS';
        }

        // version / author：v1 必填，v0 只警告
        foreach (['version' => '版本号', 'author' => '作者'] as $k => $label) {
            if (empty($meta[$k]) || !is_string($meta[$k])) {
                $legacy ? $warnings[] = "缺少 {$k}（{$label}）" : $errors[] = "缺少 {$k}（{$label}）";
            }
        }
        if (!empty($meta['version']) && is_string($meta['version'])
            && preg_match('/^\d+\.\d+\.\d+$/', $meta['version']) !== 1) {
            $msg = "version 不是三段 SemVer：{$meta['version']}";
            $legacy ? $warnings[] = $msg : $errors[] = $msg;
        }

        // 兼容性：不满足一律拒绝，不分新旧——装上去也是坏的
        foreach ([
            ['requires_cms', defined('CMS_VERSION') ? CMS_VERSION : '0.0.0', 'CMS 版本'],
            ['requires_php', PHP_VERSION, 'PHP 版本'],
        ] as [$key, $actual, $label]) {
            $req = trim((string) ($meta[$key] ?? ''));
            if ($req === '') {
                if (!$legacy) {
                    $warnings[] = "未声明 {$key}";
                }
                continue;
            }
            if (!self::satisfies($actual, $req)) {
                $errors[] = "{$label}不满足：需要 {$req}，当前 {$actual}";
            }
        }

        // 依赖插件
        foreach ((array) ($meta['required_plugins'] ?? []) as $p) {
            $p = (string) $p;
            if ($p === '') {
                continue;
            }
            if ($checkPlugins && !self::pluginActive($p)) {
                $errors[] = "依赖的插件未安装或未启用：{$p}";
            }
        }

        // 软性建议
        if (empty($meta['category'])) {
            if (!$legacy) { $warnings[] = '未声明 category（市场筛选会归入未分类）'; }
        } elseif (!TemplateCategories::has((string) $meta['category'])) {
            $alias = TemplateCategories::aliasOf((string) $meta['category']);
            $warnings[] = $alias !== null
                ? "category「{$meta['category']}」是旧分类，请改为「{$alias}」"
                : "category「{$meta['category']}」不在词表内：" . implode(' / ', TemplateCategories::keys());
        }

        // 2.0.3 展示样式表：相对主题 assets/ 目录的 .css，前台默认页头与所有编辑画布都按它加载
        if (array_key_exists('stylesheets', $meta)) {
            $sheets = $meta['stylesheets'];
            if (!is_array($sheets) || array_values($sheets) !== $sheets || count($sheets) > 10) {
                $errors[] = 'stylesheets 必须是最多 10 项的数组';
            } else {
                foreach ($sheets as $sheet) {
                    if (!is_string($sheet) || !self::stylesheetPath($sheet)) {
                        $errors[] = 'stylesheets 含不合法路径（只允许 assets/ 下的相对 .css 路径）：' . (is_scalar($sheet) ? (string) $sheet : gettype($sheet));
                    }
                }
            }
        }

        if (isset($meta['locales'])) {
            $warnings[] = 'locales 已废弃：主题不承载演示内容，该字段无对应实现';
        }

        foreach (['name_en', 'name_ja', 'description_en', 'description_ja'] as $k) {
            if (empty($meta[$k]) && !$legacy) {
                $warnings[] = "缺少 {$k}（多语言站点会回退到中文）";
            }
        }
        // 其他语言用同样的「字段_语言代码」写法（name_ko、description_de…）；代码写错的字段不会被读取
        foreach (array_keys($meta) as $k) {
            if (is_string($k) && preg_match('/^(?:name|description)_(.+)$/D', $k, $m) === 1
                && $m[1] !== 'zh-CN' && !LanguageRegistry::has($m[1])) {
                $warnings[] = "{$k}：「{$m[1]}」不是已登记的语言代码（见 includes/i18n/LanguageRegistry.php），这个字段不会被读取";
            }
        }

        if (isset($meta['supports'])) {
            $warnings[] = 'supports 已废弃：区块覆盖现由文件系统推导（见 themeBlockCoverage）';
        }
        if (isset($meta['colors'])) {
            $warnings[] = 'colors 已移出 manifest：请改放 design-tokens.json';
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'meta' => $meta];
    }

    /**
     * 版本约束判断。支持 `>=1.14` / `>1.0` / `1.14` / `^1.14`（^ 视作 >=，不做上界）。
     * 刻意做得宽松：主题作者不是 composer 用户，写法五花八门，
     * 与其解析失败就拒绝，不如认不出时放行（返回 true）并由上层给警告。
     */
    public static function satisfies(string $actual, string $constraint): bool
    {
        $c = trim($constraint);
        if ($c === '') {
            return true;
        }
        if (preg_match('/^(>=|<=|>|<|=|\^|~)?\s*v?(\d+(?:\.\d+){0,2})$/', $c, $m) !== 1) {
            return true;   // 认不出的写法不拦
        }
        $op = $m[1] ?: '>=';
        $ver = $m[2];
        if ($op === '^' || $op === '~' || $op === '=') {
            $op = $op === '=' ? '==' : '>=';
        }
        // 约束里的版本号补齐三段再比：version_compare 认为 '1.14.0' > '1.14'
        // （多一节就算更大），于是 `>1.14` 会把 1.14.0 判为满足——
        // 但写 `>1.14` 的人要的是「严格高于 1.14」，1.14.0 就是 1.14。
        $ver .= str_repeat('.0', 2 - substr_count($ver, '.'));
        // version_compare 带第三参的签名是 bool|null（运算符非法时返回 null），
        // 这里的 $op 恒为合法值，显式转 bool 以对齐返回类型
        return (bool) version_compare($actual, $ver, $op);
    }

    /** 插件是否已安装且启用。取不到插件体系时一律放行，不因环境差异误拒。 */
    private static function pluginActive(string $slug): bool
    {
        if (function_exists('isPluginActive')) {
            return (bool) isPluginActive($slug);
        }
        if (function_exists('getActivePlugins')) {
            return in_array($slug, (array) getActivePlugins(), true);
        }
        return true;
    }
}

/**
 * 主题的首页区块覆盖情况——**扫文件系统得出，不读 manifest**。
 *
 * 原先靠 theme.json 的 supports 声明，五套内置主题里三套与实际不符
 * （见 yikaicms-docs/theme-schema.md）。文件在就是在，不会撒谎。
 *
 * 主题缺某个区块时 theme_path() 会回退到 includes/blocks/，页面不会坏，
 * 只是拿不到该主题的专属样式——所以这是展示信息，不是安装门槛。
 *
 * @return array{own: list<string>, fallback: list<string>}
 */
function themeBlockCoverage(string $slug): array
{
    $themeDir = ROOT_PATH . '/themes/' . $slug . '/blocks';

    // 全集 = 核心区块 ∪ 该主题自带的区块。
    // 不能只取核心：主题可以提供核心没有的区块（default 的 partners 就是），
    // 只扫核心会把它算漏。
    $core = [];
    foreach (glob(ROOT_PATH . '/includes/blocks/*.php') ?: [] as $f) {
        $core[] = basename($f, '.php');
    }
    $own = [];
    foreach (glob($themeDir . '/*.php') ?: [] as $f) {
        $own[] = basename($f, '.php');
    }
    sort($own);

    // 回退 = 核心有、主题没有的（页面照常渲染，只是拿不到主题专属样式）
    $fallback = array_values(array_diff($core, $own));
    sort($fallback);

    return ['own' => $own, 'fallback' => $fallback];
}
