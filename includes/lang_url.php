<?php
declare(strict_types=1);

/**
 * 给站内路径加语言前缀。默认语言原样返回；非默认语言返回 /{lang}/{path}。
 *
 * 独立成文件是为了能在单测里直接加载（functions.php 全量引入会与 tests/bootstrap.php 的桩冲突）。
 * 依赖 siteLang() 与 config()，由 functions.php / 测试 bootstrap 提供；动态 URL 模式的
 * isDynamicUrlMode() / dynamicUrl() 在单测环境可能不存在，按存在性判断。
 */
function langUrl(string $url, string $lang = ''): string
{
    $lang = $lang ?: siteLang();
    $defaultLang = (string)config('site_lang', 'zh-CN');
    if (function_exists('isDynamicUrlMode') && isDynamicUrlMode()) {
        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '/') : $url;
        $query = [];
        if (is_array($parts) && isset($parts['query'])) parse_str((string) $parts['query'], $query);
        if (($query['yk_route'] ?? '') !== '') {
            if ($lang === $defaultLang) unset($query['lang']);
            else $query['lang'] = $lang;
            return $path . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        if ($path === '/' || trim($path, '/') === '' || trim($path, '/') === $defaultLang
            || in_array(trim($path, '/'), ['en', 'ja', 'zh-CN', 'zh-TW'], true)) {
            return dynamicUrl('home', [], $lang);
        }
    }
    if ($lang === $defaultLang) return $url;
    // 必须保留分隔符：/en/contact.html，而非 /encontact.html。
    // 语言首页用 /en/（与 langPrefix() . '/' 同形）；.htaccess / nginx 的前缀规则只认 ^en/，裸 /en 不命中。
    return '/' . $lang . '/' . ltrim($url, '/');
}
