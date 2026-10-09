<?php
/**
 * 字体预设表：每种语言 3 组，外加「自定义」。
 *
 * 铁律：**只用系统字体栈，绝不引任何字体 CDN**。
 *   - Google Fonts 在中国大陆不可用（fhzn 老站为此装了三个插件专治这个问题）；
 *   - 中日文 webfont 动辄几 MB，首屏代价远大于观感收益。
 * 例外只有随包内置字体（bundledFonts()，自托管 woff2、按文字分片）：栈里出现其族名时
 * 才输出 @font-face，不选不加载。站长自己的品牌字体走上传（uploadedFonts()）。
 *
 * 每组给 body 与 heading 两个栈：正文求易读、标题可略有性格。
 * fallback 链一律以通用族（sans-serif / serif）收尾，任何系统都不至于无字可用。
 *
 * 用法：fontPresets()[<lang>] → ['key' => ['label' => 显示名, 'body' => 栈, 'heading' => 栈]]
 * lang 未收录时回落 en 组（拉丁字母栈对任何语言都不至于出错）。
 */

declare(strict_types=1);

require_once __DIR__ . '/i18n/LanguageRegistry.php';

/**
 * @return array<string, array<string, array{label:string, body:string, heading:string}>>
 */
function fontPresets(): array
{
    // 各语言共用的系统 UI 起手式，保证 emoji 与符号有字可用
    $emoji = '"Apple Color Emoji","Segoe UI Emoji","Segoe UI Symbol"';

    return [
        'zh-CN' => [
            'system' => [
                'label'   => __('font_preset_zh_system'),
                'body'    => '-apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Helvetica Neue",Arial,sans-serif,' . $emoji,
                'heading' => '-apple-system,BlinkMacSystemFont,"PingFang SC","Hiragino Sans GB","Microsoft YaHei","Helvetica Neue",Arial,sans-serif',
            ],
            'serif' => [
                'label'   => __('font_preset_zh_serif'),
                'body'    => 'Georgia,"Songti SC","SimSun","Source Han Serif SC","Noto Serif CJK SC",serif,' . $emoji,
                'heading' => 'Georgia,"Songti SC","SimSun","Source Han Serif SC","Noto Serif CJK SC",serif',
            ],
            'rounded' => [
                'label'   => __('font_preset_zh_rounded'),
                'body'    => '"PingFang SC","Hiragino Sans GB","Microsoft YaHei UI","Microsoft YaHei",sans-serif,' . $emoji,
                'heading' => '"YouYuan","PingFang SC","Hiragino Sans GB","Microsoft YaHei UI",sans-serif',
            ],
        ],
        'en' => [
            'system' => [
                'label'   => __('font_preset_en_system'),
                'body'    => 'system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif,' . $emoji,
                'heading' => 'system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif',
            ],
            'grotesk' => [
                'label'   => __('font_preset_en_grotesk'),
                'body'    => 'Inter,"Helvetica Neue",Helvetica,Arial,"Liberation Sans",sans-serif,' . $emoji,
                // Inter 随包内置（bundledFonts），标题靠 opsz 轴自动收紧，不再需要 Inter Tight
                'heading' => 'Inter,"Helvetica Neue",Helvetica,Arial,sans-serif',
            ],
            'serif' => [
                'label'   => __('font_preset_en_serif'),
                'body'    => 'Georgia,Cambria,"Times New Roman",Times,serif,' . $emoji,
                'heading' => '"Iowan Old Style",Georgia,Cambria,"Times New Roman",serif',
            ],
        ],
        'ja' => [
            'gothic' => [
                'label'   => __('font_preset_ja_gothic'),
                'body'    => '-apple-system,BlinkMacSystemFont,"Hiragino Kaku Gothic ProN","Hiragino Sans","Yu Gothic",YuGothic,Meiryo,sans-serif,' . $emoji,
                'heading' => '-apple-system,"Hiragino Kaku Gothic ProN","Hiragino Sans","Yu Gothic",YuGothic,Meiryo,sans-serif',
            ],
            'mincho' => [
                'label'   => __('font_preset_ja_mincho'),
                'body'    => '"Hiragino Mincho ProN","Yu Mincho",YuMincho,"MS PMincho",serif,' . $emoji,
                'heading' => '"Hiragino Mincho ProN","Yu Mincho",YuMincho,"MS PMincho",serif',
            ],
            'maru' => [
                'label'   => __('font_preset_ja_maru'),
                'body'    => '"Hiragino Maru Gothic ProN","Yu Gothic",YuGothic,Meiryo,sans-serif,' . $emoji,
                'heading' => '"Hiragino Maru Gothic ProN","Yu Gothic",YuGothic,Meiryo,sans-serif',
            ],
        ],
        // 阿拉伯语：只用系统自带字体（Windows Segoe UI / Tahoma 都含阿拉伯字形，苹果是 Geeza Pro，安卓是 Noto）
        'ar' => [
            'system' => [
                'label'   => __('font_preset_ar_system'),
                'body'    => 'system-ui,"Segoe UI","Noto Sans Arabic","Geeza Pro","Arabic UI Text",Tahoma,Arial,sans-serif,' . $emoji,
                'heading' => 'system-ui,"Segoe UI","Noto Sans Arabic","Geeza Pro","Arabic UI Display",Tahoma,Arial,sans-serif',
            ],
            'naskh' => [
                'label'   => __('font_preset_ar_naskh'),
                'body'    => '"Noto Naskh Arabic","Traditional Arabic","Simplified Arabic","Times New Roman",serif,' . $emoji,
                'heading' => '"Noto Naskh Arabic","Traditional Arabic","Times New Roman",serif',
            ],
            'kufi' => [
                'label'   => __('font_preset_ar_kufi'),
                'body'    => '"Segoe UI","Noto Sans Arabic","Geeza Pro",Tahoma,sans-serif,' . $emoji,
                'heading' => '"Noto Kufi Arabic","Segoe UI","Geeza Pro",Tahoma,sans-serif',
            ],
        ],
    ];
}

/** 当前语言可用的预设组：先按语言代码，再按注册表的字体组（繁体用简体组），最后回落 en */
function fontPresetsFor(string $lang): array
{
    $all = fontPresets();
    $group = class_exists('LanguageRegistry') ? LanguageRegistry::fontGroup($lang) : 'en';
    return $all[$lang] ?? $all[$group] ?? $all['en'];
}

/**
 * 站点当前生效的字体栈。
 *
 * 取值链：设置（可按语言分设 font_body_en 等）→ 预设 → 空。
 * **返回空数组时调用方不应输出任何 CSS**——未配置的站点前台输出必须逐字节不变。
 *
 * @return array{body:string, heading:string, base_size:string}
 */
function siteFontStacks(): array
{
    $lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
    $presets = fontPresetsFor($lang);

    $presetKey = trim((string) configRawLang('font_preset', ''));
    $body = $heading = '';

    if ($presetKey === 'custom') {
        $body    = trim((string) configRawLang('font_body_custom', ''));
        $heading = trim((string) configRawLang('font_heading_custom', ''));
    } elseif ($presetKey !== '' && isset($presets[$presetKey])) {
        $body    = $presets[$presetKey]['body'];
        $heading = $presets[$presetKey]['heading'];
    }

    // 标题留空 = 跟随正文
    if ($heading === '') {
        $heading = $body;
    }

    $size = trim((string) configRawLang('font_base_size', ''));
    if ($size !== '' && !preg_match('/^\d{2,3}(px|%)$/', $size)) {
        $size = '';   // 只接受 14px / 100% 这类，防止注入进 style 块
    }

    return ['body' => $body, 'heading' => $heading, 'base_size' => $size];
}

/**
 * 自托管字体：上传的字体文件清单（uploads/fonts/*.woff2|woff|ttf|otf）。
 *
 * 为什么允许上传而不是引 CDN：品牌字体是真实需求，但 Google Fonts 在中国大陆
 * 不可用。自托管既解决可用性，也不给第三方送访客 IP。文件放 uploads/fonts/，
 * 随站点备份走，换主机不丢。
 *
 * @return array<int, array{file:string, name:string, url:string, size:int}>
 */
function uploadedFonts(): array
{
    $dir = ROOT_PATH . '/uploads/fonts';
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach ((array) glob($dir . '/*.{woff2,woff,ttf,otf}', GLOB_BRACE) as $path) {
        $file = basename((string) $path);
        $out[] = [
            'file' => $file,
            'name' => pathinfo($file, PATHINFO_FILENAME),
            'url'  => '/uploads/fonts/' . rawurlencode($file),
            'size' => (int) @filesize($path),
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
    return $out;
}

/** 文件后缀 → @font-face 的 format() 值 */
function fontFaceFormat(string $file): string
{
    return match (strtolower((string) pathinfo($file, PATHINFO_EXTENSION))) {
        'woff2' => 'woff2',
        'woff'  => 'woff',
        'otf'   => 'opentype',
        default => 'truetype',
    };
}

/**
 * 随包内置字体登记表（唯一登记处）。键是 CSS 族名：预设或自定义栈里出现该族名，
 * renderFontStyles() 才输出它的 @font-face 与首片 preload；不选不加载。
 *
 * 文件由 tools/fonts/build-bundled-fonts.py 从上游可变字体按 unicode-range 分片生成，
 * 分片范围必须与该脚本一致——单测逐条比对 assets/fonts/<dir>/manifest.json。
 * 浏览器只下载页面实际用到的分片：英文页通常只取 latin 一片。
 *
 * @return array<string, array{dir:string, weight:string, preload:string, files:array<string, array{file:string, range:string}>}>
 */
function bundledFonts(): array
{
    return [
        'Inter' => [
            'dir'     => '/assets/fonts/inter',   // rsms/inter v4.1，OFL-1.1（同目录 OFL.txt）
            'weight'  => '100 900',
            'preload' => 'latin',
            'files'   => [
                'latin'      => ['file' => 'inter-latin.woff2', 'range' => 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD'],
                'latin-ext'  => ['file' => 'inter-latin-ext.woff2', 'range' => 'U+0100-02BA,U+02BD-02C5,U+02C7-02CC,U+02CE-02D7,U+02DD-02FF,U+0304,U+0308,U+0329,U+1D00-1DBF,U+1E00-1E9F,U+1EF2-1EFF,U+2020,U+20A0-20AB,U+20AD-20C0,U+2113,U+2C60-2C7F,U+A720-A7FF'],
                'cyrillic'   => ['file' => 'inter-cyrillic.woff2', 'range' => 'U+0301,U+0400-052F,U+1C80-1C8A,U+20B4,U+2116,U+2DE0-2DFF,U+A640-A69F,U+FE2E-FE2F'],
                'greek'      => ['file' => 'inter-greek.woff2', 'range' => 'U+0370-0377,U+037A-037F,U+0384-038A,U+038C,U+038E-03A1,U+03A3-03FF'],
                'vietnamese' => ['file' => 'inter-vietnamese.woff2', 'range' => 'U+0102-0103,U+0110-0111,U+0128-0129,U+0168-0169,U+01A0-01A1,U+01AF-01B0,U+0300-0301,U+0303-0304,U+0308-0309,U+0323,U+0329,U+1EA0-1EF9,U+20AB'],
            ],
        ],
    ];
}

/**
 * 字体栈里引用到的内置字体族名（按登记表原样大小写返回，去重）。
 *
 * @return list<string>
 */
function bundledFontsInStacks(string ...$stacks): array
{
    $known = [];
    foreach (array_keys(bundledFonts()) as $family) {
        $known[strtolower($family)] = $family;
    }
    $found = [];
    foreach ($stacks as $stack) {
        foreach (explode(',', $stack) as $name) {
            $key = strtolower(trim($name, " \t\"'"));
            if (isset($known[$key])) {
                $found[$known[$key]] = true;
            }
        }
    }
    return array_keys($found);
}

/** 站内静态资源在 <style> 里的地址：出口改写只处理 HTML 属性，style 内容要在生成处补子目录前缀。 */
function fontAssetUrl(string $path): string
{
    $versioned = function_exists('assetVer') ? assetVer($path) : $path;
    return class_exists('BasePath') ? BasePath::url($versioned) : $versioned;
}

/**
 * 内置字体的分片 @font-face。后台外观页的预设预览也用它，所见即所得。
 *
 * @param list<string> $families 登记表里的族名
 */
function bundledFontFaceCss(array $families): string
{
    $registry = bundledFonts();
    $css = '';
    foreach ($families as $family) {
        if (!isset($registry[$family])) {
            continue;
        }
        $font = $registry[$family];
        foreach ($font['files'] as $item) {
            $path = $font['dir'] . '/' . $item['file'];
            if (!is_file(ROOT_PATH . $path)) {
                continue;
            }
            $css .= '@font-face{font-family:"' . $family . '";'
                . 'src:url("' . fontAssetUrl($path) . '") format("woff2");'
                . 'font-weight:' . $font['weight'] . ';font-style:normal;font-display:swap;'
                . 'unicode-range:' . $item['range'] . '}';
        }
    }
    return $css;
}

/**
 * 内置字体首片的 preload（每族一条，通常是 latin）。
 *
 * @param list<string> $families
 */
function bundledFontPreloads(array $families): string
{
    $registry = bundledFonts();
    $out = '';
    foreach ($families as $family) {
        $font = $registry[$family] ?? null;
        $item = $font['files'][$font['preload'] ?? ''] ?? null;
        if ($font === null || $item === null) {
            continue;
        }
        $path = $font['dir'] . '/' . $item['file'];
        if (!is_file(ROOT_PATH . $path)) {
            continue;
        }
        // 属性里的根相对地址由出口改写补子目录前缀，这里不能再补，否则会叠两次
        $href = function_exists('assetVer') ? assetVer($path) : $path;
        $out .= '<link rel="preload" href="' . htmlspecialchars($href, ENT_QUOTES) . '" as="font" type="font/woff2" crossorigin>' . "\n";
    }
    return $out;
}

/**
 * 前台 head 里的字体 CSS。未配置任何字体时返回 ''——
 * **调用方据此不输出 style 块，未启用的站点前台输出逐字节不变（对拍底线）。**
 */
function renderFontStyles(): string
{
    $f = siteFontStacks();

    // 内置字体：只为栈里真正引用到的族输出分片 @font-face（opsz 轴由浏览器默认的 font-optical-sizing:auto 驱动）
    $bundled = bundledFontsInStacks($f['body'], $f['heading']);
    $faces = bundledFontFaceCss($bundled);
    $preload = bundledFontPreloads($bundled);

    // 自托管字体：被选中的那个才输出 @font-face，避免把整个字体目录都预加载
    $selfHosted = trim((string) configRawLang('font_self_hosted', ''));
    if ($selfHosted !== '') {
        $file = basename($selfHosted);                     // 防目录穿越
        $path = ROOT_PATH . '/uploads/fonts/' . $file;
        if (is_file($path)) {
            $family = 'YKCustomFont';
            // 可变字体要声明字重范围，否则粗体只能靠浏览器合成
            $weight = (string) configRawLang('font_self_hosted_variable', '') === '1' ? '100 900' : 'normal';
            $faces .= '@font-face{font-family:"' . $family . '";'
                . 'src:url("' . fontAssetUrl('/uploads/fonts/' . rawurlencode($file)) . '") format("' . fontFaceFormat($file) . '");'
                . 'font-display:swap;font-weight:' . $weight . ';font-style:normal}';
            // 自托管字体排在栈首，后面仍接原有栈作兜底（字体没加载出来也不至于无字可用）
            $fallback = $f['body'] !== '' ? $f['body'] : 'system-ui,-apple-system,"Segoe UI",Roboto,sans-serif';
            $f['body'] = '"' . $family . '",' . $fallback;
            $headFallback = $f['heading'] !== '' ? $f['heading'] : $fallback;
            $f['heading'] = '"' . $family . '",' . $headFallback;
        }
    }

    if ($f['body'] === '' && $f['heading'] === '' && $f['base_size'] === '' && $faces === '') {
        return '';
    }

    $vars = '';
    if ($f['body'] !== '') {
        $vars .= '--yk-font-body:' . $f['body'] . ';';
    }
    if ($f['heading'] !== '') {
        $vars .= '--yk-font-heading:' . $f['heading'] . ';';
    }
    $css = $faces;
    if ($vars !== '') {
        $css .= ':root{' . $vars . '}';
        $css .= 'body{font-family:var(--yk-font-body,inherit)}';
        $css .= 'h1,h2,h3,h4,h5,h6,.blk-title{font-family:var(--yk-font-heading,inherit)}';
    }
    if ($f['base_size'] !== '') {
        $css .= 'html{font-size:' . $f['base_size'] . '}';
    }

    return $preload . '<style id="yk-fonts">' . $css . '</style>' . "\n";
}
