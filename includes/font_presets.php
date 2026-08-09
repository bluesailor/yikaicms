<?php
/**
 * 字体预设表：每种语言 3 组，外加「自定义」。
 *
 * 铁律：**只用系统字体栈，绝不引任何字体 CDN**。
 *   - Google Fonts 在中国大陆不可用（fhzn 老站为此装了三个插件专治这个问题）；
 *   - 中日文 webfont 动辄几 MB，首屏代价远大于观感收益。
 * 需要品牌字体时用自托管 woff2（P1.5，另做），不在本表内。
 *
 * 每组给 body 与 heading 两个栈：正文求易读、标题可略有性格。
 * fallback 链一律以通用族（sans-serif / serif）收尾，任何系统都不至于无字可用。
 *
 * 用法：fontPresets()[<lang>] → ['key' => ['label' => 显示名, 'body' => 栈, 'heading' => 栈]]
 * lang 未收录时回落 en 组（拉丁字母栈对任何语言都不至于出错）。
 */

declare(strict_types=1);

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
                'heading' => '"Inter Tight",Inter,"Helvetica Neue",Helvetica,Arial,sans-serif',
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
    ];
}

/** 当前语言可用的预设组；未收录的语言回落 en */
function fontPresetsFor(string $lang): array
{
    $all = fontPresets();
    return $all[$lang] ?? $all['en'];
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
 * 前台 head 里的字体 CSS。未配置任何字体时返回 ''——
 * **调用方据此不输出 style 块，未启用的站点前台输出逐字节不变（对拍底线）。**
 */
function renderFontStyles(): string
{
    $f = siteFontStacks();
    $faces = '';

    // 自托管字体：被选中的那个才输出 @font-face，避免把整个字体目录都预加载
    $selfHosted = trim((string) configRawLang('font_self_hosted', ''));
    if ($selfHosted !== '') {
        $file = basename($selfHosted);                     // 防目录穿越
        $path = ROOT_PATH . '/uploads/fonts/' . $file;
        if (is_file($path)) {
            $family = 'YKCustomFont';
            $faces = '@font-face{font-family:"' . $family . '";'
                . 'src:url("/uploads/fonts/' . rawurlencode($file) . '") format("' . fontFaceFormat($file) . '");'
                . 'font-display:swap;font-weight:normal;font-style:normal}';
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

    return '<style id="yk-fonts">' . $css . '</style>' . "\n";
}
