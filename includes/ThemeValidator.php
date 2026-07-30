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

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

final class ThemeValidator
{
    /** 当前 Schema 版本 */
    public const SCHEMA_VERSION = 1;

    /** 分类词表。不在表内只警告——总会有没预料到的行业。 */
    public const CATEGORIES = [
        'general', 'manufacturing', 'trade', 'tech', 'creative', 'services', 'retail',
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

        $r['meta'] = $meta;
        return $r;
    }

    /**
     * 只校验元数据（不碰文件系统），供 ZIP 安装在解压前使用。
     *
     * @param array<string,mixed> $meta
     * @return array{errors: list<string>, warnings: list<string>, meta: array<string,mixed>}
     */
    public static function validateMeta(array $meta, string $slug = ''): array
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
            if (!self::pluginActive($p)) {
                $errors[] = "依赖的插件未安装或未启用：{$p}";
            }
        }

        // 软性建议
        if (empty($meta['category'])) {
            if (!$legacy) { $warnings[] = '未声明 category（市场筛选会归入未分类）'; }
        } elseif (!in_array((string) $meta['category'], self::CATEGORIES, true)) {
            $warnings[] = "category「{$meta['category']}」不在词表内：" . implode(' / ', self::CATEGORIES);
        }

        if (isset($meta['locales'])) {
            $warnings[] = 'locales 已废弃：主题不承载演示内容，该字段无对应实现';
        }

        foreach (['name_en', 'name_ja', 'description_en', 'description_ja'] as $k) {
            if (empty($meta[$k]) && !$legacy) {
                $warnings[] = "缺少 {$k}（多语言站点会回退到中文）";
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
