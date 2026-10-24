<?php
/**
 * Yikai CMS - HTML 内容增强流水线
 *
 * 基于自研 HtmlTagRewriter 对富文本做安全的属性级改写。
 * 默认注册 4 个 filter 到 content_render 钩子，可通过 remove_filter 关闭。
 */

declare(strict_types=1);

require_once __DIR__ . '/HtmlTagRewriter.php';

class HtmlPipeline
{
    /**
     * 注册默认增强器到 content_render filter。
     * 由 init.php 在引入后调用一次。
     */
    public static function bootstrap(): void
    {
        add_filter('content_render', [self::class, 'lazyLoadImages'], 10);
        add_filter('content_render', [self::class, 'imgAltFallback'], 11);
        add_filter('content_render', [self::class, 'secureExternalLinks'], 12);
        add_filter('content_render', [self::class, 'autoHeadingIds'], 13);
    }

    /**
     * 给所有 <img> 加 loading=lazy + decoding=async（如未指定）。
     */
    public static function lazyLoadImages(string $html): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }
        $p = new HtmlTagRewriter($html);
        while ($p->nextTag('IMG')) {
            if ($p->getAttribute('loading') === null) {
                $p->setAttribute('loading', 'lazy');
            }
            if ($p->getAttribute('decoding') === null) {
                $p->setAttribute('decoding', 'async');
            }
        }
        return $p->getUpdatedHtml();
    }

    /**
     * <img> 缺 alt 时，用 title 兜底；都没有则置为空字符串（满足 a11y 最低要求）。
     */
    public static function imgAltFallback(string $html): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }
        $p = new HtmlTagRewriter($html);
        while ($p->nextTag('IMG')) {
            $alt = $p->getAttribute('alt');
            if ($alt === null) {
                $title = $p->getAttribute('title');
                $p->setAttribute('alt', is_string($title) ? $title : '');
            }
        }
        return $p->getUpdatedHtml();
    }

    /**
     * 站外 <a> 自动加 rel="noopener noreferrer" + target="_blank"。
     * 链接的 host 不是本站任何一个地址才算站外。
     */
    public static function secureExternalLinks(string $html): string
    {
        if ($html === '' || stripos($html, '<a') === false) {
            return $html;
        }
        $siteHosts = self::siteHosts();
        $p = new HtmlTagRewriter($html);
        while ($p->nextTag('A')) {
            $href = $p->getAttribute('href');
            if (!is_string($href) || $href === '') continue;

            $isExternal = false;
            if (preg_match('#^https?://#i', $href)) {
                $linkHost = strtolower((string) (parse_url($href, PHP_URL_HOST) ?: ''));
                $isExternal = $linkHost !== '' && !in_array($linkHost, $siteHosts, true);
            }
            if (!$isExternal) continue;

            // 合并 rel
            $rel = (string)($p->getAttribute('rel') ?? '');
            $tokens = $rel === '' ? [] : (preg_split('/\s+/', strtolower($rel)) ?: []);
            foreach (['noopener', 'noreferrer'] as $token) {
                if (!in_array($token, $tokens, true)) $tokens[] = $token;
            }
            $p->setAttribute('rel', implode(' ', array_filter($tokens)));

            if ($p->getAttribute('target') === null) {
                $p->setAttribute('target', '_blank');
            }
        }
        return $p->getUpdatedHtml();
    }

    /**
     * 本站的地址：后台「站点域名」、安装时写进 config.php 的 SITE_URL、当前请求的域名。
     * 此前只认 site_url，它留空时（可以清空）站内绝对链接全被当成站外、改在新标签页打开。
     *
     * @return list<string>
     */
    private static function siteHosts(): array
    {
        $hosts = [];
        foreach ([(string) config('site_url', ''), defined('SITE_URL') ? (string) SITE_URL : ''] as $url) {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') $hosts[] = strtolower($host);
        }
        // 语言域名、备用域名：正在访问的这个域名当然是本站
        $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $requestHost = (string) preg_replace('/:\d+$/', '', $requestHost);
        if ($requestHost !== '') $hosts[] = $requestHost;   // IPv6 保留方括号，与 parse_url() 的取法一致
        return array_values(array_unique($hosts));
    }

    /**
     * 给 h2/h3 自动注入 id（slugify 文本），方便 ToC 锚点链接。
     * 已有 id 的不动。
     */
    public static function autoHeadingIds(string $html): string
    {
        if ($html === '' || (stripos($html, '<h2') === false && stripos($html, '<h3') === false)) {
            return $html;
        }
        $p = new HtmlTagRewriter($html);
        $used = [];
        // 先扫描已有 id 防止冲突
        while ($p->nextTag()) {
            $tag = $p->getTag();
            if ($tag !== 'H2' && $tag !== 'H3') continue;
            $id = $p->getAttribute('id');
            if (is_string($id) && $id !== '') {
                $used[$id] = true;
            }
        }
        // 第二次遍历做注入；Tag_Processor 不支持 rewind，新建实例
        $p = new HtmlTagRewriter($html);
        // 因为 Tag_Processor 暂不暴露 inner-text，用简单回调读取邻接文本不可行；
        // 改用「序号兜底」方案：未取到文本时用 section-N。
        $idx = 0;
        while ($p->nextTag()) {
            $tag = $p->getTag();
            if ($tag !== 'H2' && $tag !== 'H3') continue;
            if (is_string($p->getAttribute('id'))) continue;
            $idx++;
            $base = 'section-' . $idx;
            $candidate = $base;
            $n = 2;
            while (isset($used[$candidate])) {
                $candidate = $base . '-' . $n++;
            }
            $used[$candidate] = true;
            $p->setAttribute('id', $candidate);
        }
        return $p->getUpdatedHtml();
    }

    /**
     * 给指定标签批量注入 class（外部调用助手，便于主题在自己的 hook 里用）。
     */
    public static function addClassToTag(string $html, string $tag, string $className): string
    {
        if ($html === '' || stripos($html, '<' . $tag) === false) {
            return $html;
        }
        $p = new HtmlTagRewriter($html);
        $upper = strtoupper($tag);
        while ($p->nextTag($upper)) {
            $p->addClass($className);
        }
        return $p->getUpdatedHtml();
    }
}
