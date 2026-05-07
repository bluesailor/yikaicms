<?php
/**
 * Yikai CMS - HTML 内容增强流水线
 *
 * 基于 vendored WP_HTML_Tag_Processor 对富文本做安全的属性级改写。
 * 默认注册 4 个 filter 到 content_render 钩子，可通过 remove_filter 关闭。
 */

declare(strict_types=1);

require_once __DIR__ . '/html-api/loader.php';

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
        $p = new WP_HTML_Tag_Processor($html);
        while ($p->next_tag('IMG')) {
            if ($p->get_attribute('loading') === null) {
                $p->set_attribute('loading', 'lazy');
            }
            if ($p->get_attribute('decoding') === null) {
                $p->set_attribute('decoding', 'async');
            }
        }
        return $p->get_updated_html();
    }

    /**
     * <img> 缺 alt 时，用 title 兜底；都没有则置为空字符串（满足 a11y 最低要求）。
     */
    public static function imgAltFallback(string $html): string
    {
        if ($html === '' || stripos($html, '<img') === false) {
            return $html;
        }
        $p = new WP_HTML_Tag_Processor($html);
        while ($p->next_tag('IMG')) {
            $alt = $p->get_attribute('alt');
            if ($alt === null) {
                $title = $p->get_attribute('title');
                $p->set_attribute('alt', is_string($title) ? $title : '');
            }
        }
        return $p->get_updated_html();
    }

    /**
     * 站外 <a> 自动加 rel="noopener noreferrer" + target="_blank"。
     * 通过当前 host 判断，与 site_url 配置对比。
     */
    public static function secureExternalLinks(string $html): string
    {
        if ($html === '' || stripos($html, '<a') === false) {
            return $html;
        }
        $siteHost = parse_url((string)config('site_url', ''), PHP_URL_HOST) ?: '';
        $p = new WP_HTML_Tag_Processor($html);
        while ($p->next_tag('A')) {
            $href = $p->get_attribute('href');
            if (!is_string($href) || $href === '') continue;

            $isExternal = false;
            if (preg_match('#^https?://#i', $href)) {
                $linkHost = parse_url($href, PHP_URL_HOST) ?: '';
                if ($siteHost !== '' && strcasecmp($linkHost, $siteHost) !== 0) {
                    $isExternal = true;
                } elseif ($siteHost === '' && $linkHost !== '') {
                    $isExternal = true;
                }
            }
            if (!$isExternal) continue;

            // 合并 rel
            $rel = (string)($p->get_attribute('rel') ?? '');
            $tokens = $rel === '' ? [] : (preg_split('/\s+/', strtolower($rel)) ?: []);
            foreach (['noopener', 'noreferrer'] as $token) {
                if (!in_array($token, $tokens, true)) $tokens[] = $token;
            }
            $p->set_attribute('rel', implode(' ', array_filter($tokens)));

            if ($p->get_attribute('target') === null) {
                $p->set_attribute('target', '_blank');
            }
        }
        return $p->get_updated_html();
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
        $p = new WP_HTML_Tag_Processor($html);
        $used = [];
        // 先扫描已有 id 防止冲突
        while ($p->next_tag()) {
            $tag = $p->get_tag();
            if ($tag !== 'H2' && $tag !== 'H3') continue;
            $id = $p->get_attribute('id');
            if (is_string($id) && $id !== '') {
                $used[$id] = true;
            }
        }
        // 第二次遍历做注入；Tag_Processor 不支持 rewind，新建实例
        $p = new WP_HTML_Tag_Processor($html);
        // 因为 Tag_Processor 暂不暴露 inner-text，用简单回调读取邻接文本不可行；
        // 改用「序号兜底」方案：未取到文本时用 section-N。
        $idx = 0;
        while ($p->next_tag()) {
            $tag = $p->get_tag();
            if ($tag !== 'H2' && $tag !== 'H3') continue;
            if (is_string($p->get_attribute('id'))) continue;
            $idx++;
            $base = 'section-' . $idx;
            $candidate = $base;
            $n = 2;
            while (isset($used[$candidate])) {
                $candidate = $base . '-' . $n++;
            }
            $used[$candidate] = true;
            $p->set_attribute('id', $candidate);
        }
        return $p->get_updated_html();
    }

    /**
     * 给指定标签批量注入 class（外部调用助手，便于主题在自己的 hook 里用）。
     */
    public static function addClassToTag(string $html, string $tag, string $className): string
    {
        if ($html === '' || stripos($html, '<' . $tag) === false) {
            return $html;
        }
        $p = new WP_HTML_Tag_Processor($html);
        $upper = strtoupper($tag);
        while ($p->next_tag($upper)) {
            $p->add_class($className);
        }
        return $p->get_updated_html();
    }
}
