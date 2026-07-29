<?php
/**
 * SEO 工坊 - 纯函数库（无 UI，可单测）
 *
 * llms.txt：新兴约定（llmstxt.org），放在站点根 /llms.txt，用简洁 Markdown
 * 指引 AI 助手快速理解站点结构与重点内容。这里按站点配置 + 栏目树 + 近期内容生成。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/** llms.txt 在主机上的落地路径（站点根）。 */
function seo_llms_path(): string
{
    return ROOT_PATH . '/llms.txt';
}

/**
 * 生成 llms.txt 内容（Markdown 字符串，不落盘）。
 *
 * 结构：H1 站名 → 引用块站点描述 → 主要栏目（含一层子栏目）→ 最新内容 → 产品。
 * 保持精简：llms.txt 是「重点指针」，非全站清单（全站有 sitemap.xml）。
 */
function seo_build_llms_txt(int $contentLimit = 30, int $productLimit = 30): string
{
    $siteName = trim((string) configRawLang('site_name', 'Yikai CMS'));
    $siteDesc = trim((string) (configJsonLang('site_description') ?: config('site_description', '')));
    $siteUrl  = rtrim(siteBaseUrl(), '/');

    $lines = [];
    $lines[] = '# ' . ($siteName !== '' ? $siteName : 'Website');
    $lines[] = '';
    if ($siteDesc !== '') {
        $lines[] = '> ' . seo_llms_oneline($siteDesc);
        $lines[] = '';
    }

    // —— 主要栏目（导航树，含一层子栏目）——
    try {
        $channels = channelModel()->getTree();
    } catch (\Throwable $e) {
        $channels = [];
    }
    $navLines = [];
    foreach ($channels as $ch) {
        if (($ch['type'] ?? '') === 'link' || (int) ($ch['is_nav'] ?? 1) === 0) {
            continue;
        }
        $navLines[] = seo_llms_item($ch['name'] ?? '', $siteUrl . channelUrl($ch), $ch['description'] ?? '');
        foreach (($ch['children'] ?? []) as $child) {
            if (($child['type'] ?? '') === 'link') {
                continue;
            }
            $navLines[] = '  ' . seo_llms_item($child['name'] ?? '', $siteUrl . channelUrl($child), $child['description'] ?? '');
        }
    }
    if ($navLines) {
        $lines[] = '## 主要栏目';
        $lines[] = '';
        foreach ($navLines as $l) {
            $lines[] = $l;
        }
        $lines[] = '';
    }

    // —— 最新内容 ——
    try {
        $contents = db()->fetchAll(
            'SELECT c.id, c.title, c.slug, c.channel_id, ch.slug AS channel_slug, ch.type AS channel_type
             FROM ' . DB_PREFIX . 'contents c
             LEFT JOIN ' . DB_PREFIX . 'channels ch ON c.channel_id = ch.id
             WHERE c.status = 1
             ORDER BY c.publish_time DESC, c.id DESC
             LIMIT ' . (int) $contentLimit
        );
    } catch (\Throwable $e) {
        $contents = [];
    }
    if ($contents) {
        $lines[] = '## 最新内容';
        $lines[] = '';
        foreach ($contents as $c) {
            $lines[] = seo_llms_item($c['title'] ?? '', $siteUrl . contentUrl($c), '');
        }
        $lines[] = '';
    }

    // —— 产品 ——
    try {
        $products = db()->fetchAll(
            'SELECT p.id, p.title, p.slug, p.category_id, pc.slug AS category_slug
             FROM ' . DB_PREFIX . 'products p
             LEFT JOIN ' . DB_PREFIX . 'product_categories pc ON p.category_id = pc.id
             WHERE p.status = 1
             ORDER BY p.updated_at DESC, p.id DESC
             LIMIT ' . (int) $productLimit
        );
    } catch (\Throwable $e) {
        $products = [];
    }
    if ($products) {
        $lines[] = '## 产品';
        $lines[] = '';
        foreach ($products as $p) {
            $lines[] = seo_llms_item($p['title'] ?? '', $siteUrl . productUrl($p), '');
        }
        $lines[] = '';
    }

    // 允许其它插件追加/改写
    $text = rtrim(implode("\n", $lines)) . "\n";
    if (function_exists('apply_filters')) {
        $text = (string) apply_filters('seo_llms_txt', $text);
    }
    return $text;
}

/** 生成一条 Markdown 列表项：- [标题](url): 描述 */
function seo_llms_item(string $title, string $url, string $desc = ''): string
{
    $title = seo_llms_oneline($title);
    if ($title === '') {
        $title = $url;
    }
    // Markdown 链接文本里的 ] 转义，避免破坏语法
    $title = str_replace([']', "\n"], ['\\]', ' '], $title);
    $item = '- [' . $title . '](' . $url . ')';
    $desc = seo_llms_oneline($desc);
    if ($desc !== '') {
        $item .= ': ' . $desc;
    }
    return $item;
}

/** 压成单行纯文本：去 HTML、折叠空白、截断过长。 */
function seo_llms_oneline(string $s, int $max = 160): string
{
    $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)) ?? '');
    if (mb_strlen($s) > $max) {
        $s = mb_substr($s, 0, $max - 1) . '…';
    }
    return $s;
}

/**
 * 生成并写入 /llms.txt。
 * @return array{0:bool,1:string} [成功?, 消息]
 */
function seo_write_llms_txt(): array
{
    $path = seo_llms_path();
    $content = seo_build_llms_txt();
    $ok = @file_put_contents($path, $content);
    if ($ok === false) {
        return [false, '写入失败，请检查网站根目录写权限（' . $path . '）'];
    }
    return [true, '已生成 ' . strlen($content) . ' 字节'];
}

// ============================================================
// 搜索引擎主动推送（免费）：汇集站点 URL + 提交百度 / IndexNow
// ============================================================

/**
 * 汇集站点绝对 URL（首页 + 栏目 + 内容 + 产品），供主动推送。
 * @return string[]
 */
function seo_all_urls(int $limit = 1000): array
{
    $siteUrl = rtrim(siteBaseUrl(), '/');
    $urls = [$siteUrl . '/'];

    try {
        foreach (channelModel()->getTree() as $ch) {
            if (($ch['type'] ?? '') === 'link') {
                continue;
            }
            $urls[] = $siteUrl . channelUrl($ch);
            foreach (($ch['children'] ?? []) as $child) {
                if (($child['type'] ?? '') === 'link') {
                    continue;
                }
                $urls[] = $siteUrl . channelUrl($child);
            }
        }
    } catch (\Throwable $e) {
    }

    try {
        $rows = db()->fetchAll(
            'SELECT c.id, c.slug, c.channel_id, ch.slug AS channel_slug, ch.type AS channel_type
             FROM ' . DB_PREFIX . 'contents c
             LEFT JOIN ' . DB_PREFIX . 'channels ch ON c.channel_id = ch.id
             WHERE c.status = 1 ORDER BY c.publish_time DESC, c.id DESC LIMIT ' . (int) $limit
        );
        foreach ($rows as $r) {
            $urls[] = $siteUrl . contentUrl($r);
        }
    } catch (\Throwable $e) {
    }

    try {
        $rows = db()->fetchAll(
            'SELECT p.id, p.slug, p.category_id, pc.slug AS category_slug
             FROM ' . DB_PREFIX . 'products p
             LEFT JOIN ' . DB_PREFIX . 'product_categories pc ON p.category_id = pc.id
             WHERE p.status = 1 ORDER BY p.updated_at DESC, p.id DESC LIMIT ' . (int) $limit
        );
        foreach ($rows as $r) {
            $urls[] = $siteUrl . productUrl($r);
        }
    } catch (\Throwable $e) {
    }

    return array_values(array_unique(array_filter($urls)));
}

/**
 * curl POST。
 * @return array{0:int,1:string,2:string} [httpCode, 响应体, 错误信息]
 */
function seo_http_post(string $url, string $body, array $headers = []): array
{
    if (!function_exists('curl_init')) {
        return [0, '', 'PHP 未启用 curl 扩展'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, (string) $resp, $err];
}

/**
 * 提交到百度「普通收录」API。
 * @param string[] $urls
 * @return array{0:bool,1:string} [成功?, 消息]
 */
function seo_submit_baidu(string $site, string $token, array $urls): array
{
    $site = trim($site);
    $token = trim($token);
    if ($site === '' || $token === '') {
        return [false, '请先填写百度站点与 token'];
    }
    if (!$urls) {
        return [false, '没有可提交的 URL'];
    }
    $api = 'http://data.zz.baidu.com/urls?site=' . urlencode($site) . '&token=' . urlencode($token);
    [$code, $resp, $err] = seo_http_post($api, implode("\n", $urls), ['Content-Type: text/plain']);
    if ($err !== '') {
        return [false, '请求失败：' . $err];
    }
    $j = json_decode($resp, true);
    if (is_array($j) && isset($j['success'])) {
        $msg = '百度：成功推送 ' . (int) $j['success'] . ' 条';
        if (isset($j['remain'])) {
            $msg .= '，今日剩余配额 ' . (int) $j['remain'];
        }
        return [true, $msg];
    }
    $emsg = is_array($j) ? ((string) ($j['message'] ?? $resp)) : $resp;
    return [false, '百度返回异常（HTTP ' . $code . '）：' . mb_substr($emsg, 0, 120)];
}

/** IndexNow key 文件在站点根的路径。 */
function seo_indexnow_key_path(string $key): string
{
    return ROOT_PATH . '/' . preg_replace('/[^a-zA-Z0-9\-]/', '', $key) . '.txt';
}

/**
 * 提交到 IndexNow（Bing / Yandex 等共享协议）。
 * @param string[] $urls
 * @return array{0:bool,1:string} [成功?, 消息]
 */
function seo_submit_indexnow(string $host, string $key, array $urls): array
{
    $host = trim(preg_replace('#^https?://#', '', trim($host)), '/');
    $key = trim($key);
    if ($host === '' || $key === '') {
        return [false, '请先设置站点域名与 IndexNow 密钥'];
    }
    if (!$urls) {
        return [false, '没有可提交的 URL'];
    }
    // 校验 key 文件已就位（IndexNow 要求 https://host/{key}.txt 可访问且内容=key）
    $keyFile = seo_indexnow_key_path($key);
    if (!file_exists($keyFile)) {
        return [false, '请先「生成密钥文件」，IndexNow 需验证 /' . $key . '.txt'];
    }
    $payload = json_encode([
        'host'        => $host,
        'key'         => $key,
        'keyLocation' => 'https://' . $host . '/' . $key . '.txt',
        'urlList'     => array_values($urls),
    ], JSON_UNESCAPED_SLASHES);
    [$code, $resp, $err] = seo_http_post('https://api.indexnow.org/indexnow', (string) $payload, ['Content-Type: application/json; charset=utf-8']);
    if ($err !== '') {
        return [false, '请求失败：' . $err];
    }
    if ($code === 200 || $code === 202) {
        return [true, 'IndexNow：已提交 ' . count($urls) . ' 条（Bing/Yandex 等）'];
    }
    $hint = [400 => '请求格式错误', 403 => '密钥校验失败（检查 key 文件）', 422 => 'URL 与域名不匹配', 429 => '过于频繁'];
    return [false, 'IndexNow 返回 HTTP ' . $code . '：' . ($hint[(int) $code] ?? mb_substr($resp, 0, 120))];
}

/**
 * 生成 IndexNow 密钥（若无）并写入站点根 /{key}.txt。
 * @return array{0:bool,1:string,2:string} [成功?, 消息, key]
 */
function seo_ensure_indexnow_key(): array
{
    $key = trim((string) config('seo_indexnow_key', ''));
    if ($key === '' || !preg_match('/^[a-f0-9]{16,64}$/', $key)) {
        $key = bin2hex(random_bytes(16)); // 32 位 hex
        settingModel()->set('seo_indexnow_key', $key);
    }
    $ok = @file_put_contents(seo_indexnow_key_path($key), $key);
    if ($ok === false) {
        return [false, '密钥文件写入失败，请检查根目录写权限', $key];
    }
    return [true, '密钥文件已就位：/' . $key . '.txt', $key];
}
