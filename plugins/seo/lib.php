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
    $channels = [];
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
    $contents = [];
    try {
        $contents = db()->fetchAll(
            'SELECT c.title, c.slug, c.channel_id, ch.slug AS channel_slug, ch.type AS channel_type
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
    $products = [];
    try {
        $products = db()->fetchAll(
            'SELECT p.title, p.slug, p.category_id, pc.slug AS category_slug
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
