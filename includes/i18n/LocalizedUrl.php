<?php

declare(strict_types=1);

/**
 * 统一的多语言网址（2.0.5，路线图 §5.3「LocalizedUrlResolver」第一步：内容与产品详情）。
 *
 * 详情页拿到要渲染的数据行后调 enter()：
 *   - 记下「本页是哪个条目」，hreflang 与语言切换都按翻译组里真实存在、已发布的版本给网址，
 *     没有译文的语言不出现在 hreflang 里，切换器把它带到该语言首页（此前一律拼前缀，指向 404 或假译文）；
 *   - TranslationPolicy::STRICT：请求的语言没有这一条的译文、模型回落到了原文行时，302 到原文自己的网址，
 *     不再把中文正文挂在 /en/ 下冒充英文页（302：以后补了译文，同一个网址就能用）。
 *
 * 网址按目标语言显式生成，不依赖当前请求的语言上下文：伪静态（登记网址优先、否则默认形态换前缀）、
 * 动态网址（?yk_route=…&lang=xx）、语言域名都覆盖；繁体（zh-TW）作为简体数据的渲染视图时跟着简体那一行出。
 * 登记网址进来的页面（product_routes.php 设的 yk_route_entity）仍按登记表给 hreflang，与这里一致。
 *
 * 第二步（2.0.5）：栏目（列表页、单页）也走这里——此前 /en/about.html 没有英文栏目时按 findBySlugLang 回落
 * 原文行、以 200 冒充英文页。分页第 2 页起仍按路径给 hreflang（别的语言的第 N 页未必是对应页）。
 * 站点地图的各条网址也用 urlFor 按行自己的语言生成（动态网址、语言域名此前会给错）。
 *
 * 2.1 收尾：相册（album）、产品分类（product_category）、文章标签（content_tag）也登记；插件实体用 registerKind()。
 * 标签没有跨语言关联，只有它自己——hreflang 因此不再按路径指向别的语言里并不存在的同名标签页。
 */
final class LocalizedUrl
{
    /** @var array{kind:string,row:array<string,mixed>,page:int}|null */
    private static ?array $entity = null;

    /** @var array<string,array{siblings:callable,url:callable}> 插件登记的实体类型 */
    private static array $kinds = [];

    /**
     * 插件实体接入多语言网址：$siblings($row) 返回同一翻译组里已发布的行（含自己，每行要有 lang），
     * $url($row, $lang) 返回该行在该语言下的站内路径（空串 = 没有地址）。详情页拿到行后照常 enter($kind, $row)。
     *
     * @psalm-suppress PossiblyUnusedMethod 公开 API，供插件调用
     */
    public static function registerKind(string $kind, callable $siblings, callable $url): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $kind) !== 1
            || in_array($kind, ['content', 'product', 'channel', 'album', 'product_category', 'content_tag'], true)) {
            throw new InvalidArgumentException('Invalid or reserved LocalizedUrl kind: ' . $kind);
        }
        self::$kinds[$kind] = ['siblings' => $siblings, 'url' => $url];
    }

    /**
     * 详情 / 栏目页登记当前条目；必要时按严格策略 302 到条目自己的语言版本（预览不跳）。
     * $page：列表分页页码，第 2 页起 hreflang 与语言切换仍按路径。 @param array<string,mixed> $row
     */
    public static function enter(string $kind, array $row, bool $preview = false, int $page = 1): void
    {
        self::$entity = ['kind' => $kind, 'row' => $row, 'page' => max(1, $page)];
        if ($preview || headers_sent()) return;
        $rowLang = (string) ($row['lang'] ?? '');
        if ($rowLang === '' || $rowLang === siteLang()) return;
        // 条目的语言没在本站启用（如默认语言是繁体或阿拉伯语的新站，演示数据是简体行）：没有可去的地址，照常显示
        if (function_exists('enabledLanguages') && !array_key_exists($rowLang, enabledLanguages())) return;
        // 请求的语言没有这一条：去它自己的语言版本
        $target = self::urlFor($kind, $row, $rowLang);
        if ($target === '') return;
        header('Cache-Control: no-store');
        // 根相对地址的子目录前缀由 BasePath::prefixLocationHeader 统一补
        header('Location: ' . $target, true, 302);
        exit;
    }

    /**
     * @return array{kind:string,row:array<string,mixed>,page:int}|null
     * @psalm-suppress PossiblyUnusedMethod 公开 API（插件可取本页条目），测试也用
     */
    public static function current(): ?array
    {
        return self::$entity;
    }

    /** @psalm-suppress PossiblyUnusedMethod 测试用 */
    public static function reset(): void
    {
        self::$entity = null;
    }

    /**
     * 本条目在各启用语言下的网址（只含真实存在、已发布的版本；繁体视图跟简体行）。
     * @param array<string,mixed> $row
     * @return array<string,string> 语言 → 站内路径（语言域名时为完整地址）
     */
    public static function alternates(string $kind, array $row): array
    {
        $enabled = array_keys(enabledLanguages());
        $out = [];
        foreach (self::siblings($kind, $row) as $sibling) {
            $lang = (string) ($sibling['lang'] ?? '');
            if (!in_array($lang, $enabled, true) || isset($out[$lang])) continue;
            $url = self::urlFor($kind, $sibling, $lang);
            if ($url !== '') $out[$lang] = $url;
        }
        // 繁体是简体数据的视图（站点默认语言不是繁体时）：有简体版本就有繁体版本
        $siteDefault = (string) config('site_lang', 'zh-CN');
        if (in_array('zh-TW', $enabled, true) && $siteDefault !== 'zh-TW' && !isset($out['zh-TW'])) {
            foreach (self::siblings($kind, $row) as $sibling) {
                if (($sibling['lang'] ?? '') === 'zh-CN') {
                    $url = self::urlFor($kind, $sibling, 'zh-TW');
                    if ($url !== '') $out['zh-TW'] = $url;
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * 某数据行在指定语言下的网址（$lang 通常就是行自己的语言；繁体视图时为 zh-TW）。
     * @param array<string,mixed> $row
     */
    public static function urlFor(string $kind, array $row, string $lang): string
    {
        $default = (string) config('site_lang', 'zh-CN');
        if (isset(self::$kinds[$kind])) {
            return (string) call_user_func(self::$kinds[$kind]['url'], $row, $lang);
        }
        if (in_array($kind, ['album', 'product_category', 'content_tag'], true)) {
            return self::extraKindUrl($kind, $row, $lang, $default);
        }
        if ($kind === 'channel' && ($row['type'] ?? '') === 'link') return '';
        if (isDynamicUrlMode()) {
            $url = match ($kind) { 'content' => contentUrl($row), 'product' => productUrl($row), 'channel' => channelUrl($row), default => '' };
            return $url === '' ? '' : self::withLangQuery($url, $lang === $default ? '' : $lang);
        }
        $registered = productRouteModel()->pathFor($kind, (int) ($row['id'] ?? 0));
        if ($registered !== '') {
            // 登记网址本身带着该语言的前缀（非默认语言），繁体视图在简体网址前补 /zh-TW
            $path = $lang === (string) ($row['lang'] ?? '') ? $registered : self::prefix($lang, $default) . self::stripPrefix($registered);
        } else {
            $path = match ($kind) {
                'content' => contentDefaultPrettyUrl($row), 'product' => self::productDefaultPrettyUrl($row),
                'channel' => channelDefaultPrettyUrl($row), default => '',
            };
            if ($path === '') return '';
            $path = self::prefix($lang, $default) . self::stripPrefix($path);
        }
        if (class_exists('LanguageDomains') && LanguageDomains::active()) {
            return LanguageDomains::url($lang, self::stripPrefix($path));
        }
        return $path;
    }

    /**
     * 有登记条目时的 hreflang 标签；本页没登记条目时返回 null，由调用方走按路径的老规则。
     * 少于两个语言版本时不输出（单语言条目没有可指向的替代页）。
     */
    public static function hreflangTags(): ?string
    {
        if (self::$entity === null || self::$entity['page'] > 1) return null;
        $alternates = self::alternates(self::$entity['kind'], self::$entity['row']);
        if (count($alternates) < 2) return '';
        $default = (string) config('site_lang', 'zh-CN');
        $out = '';
        foreach ($alternates as $lang => $url) {
            $out .= '<link rel="alternate" hreflang="' . htmlspecialchars(LanguageRegistry::hreflang($lang), ENT_QUOTES)
                . '" href="' . htmlspecialchars(self::absolute($url), ENT_QUOTES) . '">' . "\n";
        }
        if (isset($alternates[$default])) {
            $out .= '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars(self::absolute($alternates[$default]), ENT_QUOTES) . '">' . "\n";
        }
        return $out;
    }

    /** 语言切换目标：本页有登记条目时给该语言版本，没有译文去该语言首页；本页没登记条目返回 null。 */
    public static function switchTarget(string $lang): ?string
    {
        if (self::$entity === null || self::$entity['page'] > 1) return null;
        $alternates = self::alternates(self::$entity['kind'], self::$entity['row']);
        return $alternates[$lang] ?? langUrl('/', $lang);
    }

    /**
     * 详情 / 单页的 canonical（完整地址）。伪静态下照旧用页面传进来的美化地址；动态网址模式下改用本条目的动态地址
     * （2.0.5 网址回归：此前动态模式也给 /news/article/x.html，「无需 Rewrite」的服务器上 canonical 指向 404）。
     * @param array<string,mixed> $row
     */
    public static function canonical(string $kind, array $row, string $prettyPath): string
    {
        $url = isDynamicUrlMode() ? self::urlFor($kind, $row, displayLang()) : '';
        return self::absolute($url !== '' ? $url : $prettyPath);
    }

    /**
     * 编辑填写的站内链接按当前语言输出（2026-10-10：日语首页的 Banner / CTA 按钮指向中文页 /about.html）。
     * 根相对、未带语言前缀的页面地址才处理：/{栏目别名}.html 换成该栏目在当前语言的译本地址，
     * 其余页面地址补当前语言前缀；默认语言、外链、锚点、已带前缀的地址和静态文件原样返回。
     * 数据里存不带前缀的地址，默认语言换成日语的站点也照样对。
     */
    public static function siteLink(string $url): string
    {
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) return $url;
        $lang = displayLang();
        if ($lang === (string) config('site_lang', 'zh-CN')) return $url;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        if (self::stripPrefix($path) !== $path) return $url;
        if ($path !== '/' && preg_match('#\.html$|/[^./]*$#D', $path) !== 1) return $url;
        $suffix = substr($url, strlen($path));
        if (preg_match('#^/([A-Za-z0-9_-]+)\.html$#D', $path, $m) === 1) {
            $target = self::channelLinkFor($m[1], $lang);
            if ($target !== '') return $target . $suffix;
        }
        return langUrl($url, $lang);
    }

    /** 别名为 $slug 的栏目在 $lang 的译本地址；一页里多个按钮共用一次栏目查询（首页查询预算）。 */
    private static function channelLinkFor(string $slug, string $lang): string
    {
        static $channels = null;
        if ($channels === null) {
            $channels = channelModel()->query('SELECT * FROM ' . channelModel()->tableName() . ' WHERE status = 1 ORDER BY id');
        }
        $source = null;
        foreach ($channels as $row) {
            if (($row['slug'] ?? '') === $slug) {
                $source = $row;
                break;
            }
        }
        if ($source === null) return '';
        $group = (int) (($source['translation_group_id'] ?? 0) ?: ($source['id'] ?? 0));
        // 繁体是简体数据的视图：找简体那一行，按繁体出地址
        $rowLang = $lang === 'zh-TW' && (string) config('site_lang', 'zh-CN') !== 'zh-TW' ? 'zh-CN' : $lang;
        foreach ($channels as $row) {
            $rowGroup = (int) (($row['translation_group_id'] ?? 0) ?: ($row['id'] ?? 0));
            if ($rowGroup === $group && ($row['lang'] ?? '') === $rowLang) {
                return self::urlFor('channel', $row, $lang);
            }
        }
        return '';
    }

    // ── 内部 ───────────────────────────────────────────────────────────────

    /** @param array<string,mixed> $row @return list<array<string,mixed>> 同一翻译组里已发布的行（含自己） */
    private static function siblings(string $kind, array $row): array
    {
        $id = (int) ($row['id'] ?? 0);
        $group = (int) (($row['translation_group_id'] ?? 0) ?: $id);
        if ($group <= 0) return [$row];
        static $cache = [];
        $key = $kind . ':' . $group;
        if (isset($cache[$key])) return $cache[$key];
        if ($kind === 'content') {
            $ids = db()->fetchAll('SELECT id FROM ' . DB_PREFIX . 'contents WHERE (translation_group_id = ? OR id = ?) AND type = ? AND status = 1 AND deleted_at IS NULL ORDER BY id',
                [$group, $group, (string) ($row['type'] ?? 'article')]);
            $rows = array_values(array_filter(array_map(static fn (array $r): ?array => contentModel()->getPublished((int) $r['id']), $ids)));
        } elseif ($kind === 'product') {
            $ids = db()->fetchAll('SELECT id FROM ' . DB_PREFIX . 'products WHERE (translation_group_id = ? OR id = ?) AND status = 1 AND deleted_at IS NULL ORDER BY id', [$group, $group]);
            $rows = array_values(array_filter(array_map(static fn (array $r): ?array => productModel()->getPublished((int) $r['id']), $ids)));
        } elseif ($kind === 'channel') {
            $rows = channelModel()->query('SELECT * FROM ' . channelModel()->tableName() . ' WHERE (translation_group_id = ? OR id = ?) AND status = 1 ORDER BY id',
                [$group, $group]);
        } elseif ($kind === 'album') {
            $rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'albums WHERE (translation_group_id = ? OR id = ?) AND status = 1 AND deleted_at IS NULL ORDER BY id', [$group, $group]);
        } elseif ($kind === 'product_category') {
            $rows = db()->fetchAll('SELECT * FROM ' . DB_PREFIX . 'product_categories WHERE (translation_group_id = ? OR id = ?) AND status = 1 ORDER BY id', [$group, $group]);
        } elseif (isset(self::$kinds[$kind])) {
            $rows = array_values(array_filter((array) call_user_func(self::$kinds[$kind]['siblings'], $row), 'is_array'));
        } else {
            $rows = [$row];
        }
        return $cache[$key] = $rows === [] ? [$row] : $rows;
    }

    /**
     * 相册 / 产品分类 / 文章标签：登记网址优先（带自己的语言前缀），否则默认形态换前缀；动态网址换 lang 参数。
     * 相册与标签只有登记网址或 ?id= 入口。 @param array<string,mixed> $row
     */
    private static function extraKindUrl(string $kind, array $row, string $lang, string $default): string
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) return '';
        $entry = match ($kind) { 'album' => '/album.php?id=' . $id, 'content_tag' => '/tag.php?id=' . $id, default => '' };
        if (isDynamicUrlMode()) {
            $url = $kind === 'product_category' ? productCategoryUrl($row) : $entry;
            return self::withLangQuery($url, $lang === $default ? '' : $lang);
        }
        $registered = productRouteModel()->pathFor($kind === 'product_category' ? 'category' : $kind, $id);
        if ($registered !== '') {
            $path = $lang === (string) ($row['lang'] ?? '') ? $registered : self::prefix($lang, $default) . self::stripPrefix($registered);
        } elseif ($kind === 'product_category') {
            $slug = rawurlencode((string) ($row['slug'] ?? ''));
            $path = self::prefix($lang, $default) . ($slug !== '' ? '/product/' . $slug . '.html' : '/product.html?cat=' . $id);
        } else {
            $path = self::prefix($lang, $default) . $entry;
        }
        if (class_exists('LanguageDomains') && LanguageDomains::active()) {
            return LanguageDomains::url($lang, self::stripPrefix($path));
        }
        return $path;
    }

    /** 产品默认美化地址（不看登记网址；与 productPrettyUrl 的默认分支一致） @param array<string,mixed> $product */
    private static function productDefaultPrettyUrl(array $product): string
    {
        $slug = rawurlencode((string) ($product['slug'] ?? ''));
        $categorySlug = rawurlencode((string) ($product['category_slug'] ?? ''));
        if ($slug !== '' && $categorySlug !== '') return '/product/' . $categorySlug . '/' . $slug . '.html';
        // 缺分类别名时的其它默认形态交给 productPrettyUrl（id=0 让它不去查登记网址）
        return productPrettyUrl(['id' => 0] + $product);
    }

    private static function prefix(string $lang, string $default): string
    {
        return $lang === $default ? '' : '/' . $lang;
    }

    public static function stripPrefix(string $path): string
    {
        $stripped = (string) (preg_replace('#^/(' . LanguageRegistry::urlPrefixPattern() . ')(?=/|$)#', '', $path) ?? $path);
        return $stripped === '' ? '/' : $stripped;
    }

    /** 动态网址：换掉 lang 参数（空 = 默认语言，不带参数） */
    public static function withLangQuery(string $url, string $lang): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) return $url;
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['lang']);
        if ($lang !== '') $query['lang'] = $lang;
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return (string) ($parts['path'] ?? '/') . ($encoded !== '' ? '?' . $encoded : '');
    }

    private static function absolute(string $url): string
    {
        return preg_match('#^https?://#i', $url) === 1 ? $url : siteBaseUrl() . $url;
    }
}
