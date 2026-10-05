<?php
declare(strict_types=1);

/**
 * WordPress 正文清理（WordPress 导入用，纯函数，不碰数据库）。
 *
 * - 去掉区块编辑器注释（<!-- wp:… -->）；
 * - 页面构建器短代码（WPBakery vc_*、Divi et_pb_*、Avada fusion_* 等）去掉标签、保留里面的内容；
 *   vc_single_image / gallery 换成图片，caption 换成 figure；contact-form-7 等表单去掉并记下来；
 * - Elementor 页面正文为空时，从 _elementor_data 里取出文字、标题、图片；Betheme 的 Muffin 构建器同理（mfn-page-items）；
 * - 去掉从网页（典型是 ChatGPT）整段复制带进来的属性：data-*、tabindex 与非 WordPress 的 class（Tailwind 工具类等）；
 * - 经典编辑器的纯文本段落补 <p>（简化版 wpautop）；
 * - 指向原站的完整网址改成站内路径（语言子域名 ja.example.com/x/ → /ja/x/），图片保留 /wp-content/uploads/ 原路径。
 */
final class WordPressContent
{
    /** 直接去掉标签、保留里面内容的构建器短代码前缀 */
    private const BUILDER_PREFIXES = ['vc_', 'et_pb_', 'fusion_', 'av_', 'cs_', 'x_', 'themify_', 'mk_', 'ux_', 'row', 'col', 'section', 'tabgroup', 'tab', 'accordion', 'toggle'];
    /** 整个去掉（连同内容）的短代码，并记进报告 */
    private const DROPPED = ['contact-form-7', 'contact-form', 'wpforms', 'gravityform', 'rev_slider', 'rev_slider_vc', 'layerslider', 'smartslider3', 'wpml_language_selector_widget', 'wpml_language_switcher', 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account', 'products', 'product_category', 'recent_products', 'metaslider', 'ml-slider', 'huge_it_gallery', 'my_calendar', 'bwg', 'nggallery', 'tablepress', 'table'];

    /** @var array<int,string> 附件 id → 站内图片路径 */
    private array $attachments;
    /** @var list<string> 视为原站的主机（含 www.、语言子域名之外的变体） */
    private array $hosts;
    /** @var array<string,string> 语言子域名主机 → CMS 语言代码 */
    private array $languageHosts;
    /** @var array<string,int> 本次清理遇到的、被去掉的短代码 → 次数 */
    public array $dropped = [];
    /** @var array<string,int> 不认识、只去掉了标签的短代码 → 次数 */
    public array $unknown = [];
    /** @var array<string,string> Contact Form 7 表单 id（或 hash 前 7 位）→ 本站表单别名；导入表单后由导入器填入 */
    public array $forms = [];
    /** 上一次 clean() 的正文来源：html（原正文）、elementor、muffin（从构建器数据抽出，排版已丢，属降级迁移） */
    public string $lastSource = 'html';
    /** 上一次 clean() 去掉或没认出的短代码个数（>0 属部分迁移） */
    public int $lastIssues = 0;

    /**
     * @param array<int,string> $attachments
     * @param list<string> $hosts 原站主机名（如 www.example.com、example.com）
     * @param array<string,string> $languageHosts 语言子域名 → 语言代码（如 ja.example.com => ja）
     */
    public function __construct(array $attachments = [], array $hosts = [], array $languageHosts = [])
    {
        $this->attachments = $attachments;
        $this->hosts = array_values(array_unique(array_map('strtolower', $hosts)));
        $this->languageHosts = array_change_key_case($languageHosts, CASE_LOWER);
    }

    public function clean(string $html, string $elementorJson = '', string $muffinItems = ''): string
    {
        $issuesBefore = array_sum($this->dropped) + array_sum($this->unknown);
        $this->lastSource = 'html';
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        if (trim(strip_tags($html, '<img>')) === '' && $elementorJson !== '') {
            $html = $this->fromElementor($elementorJson);
            $this->lastSource = 'elementor';
        } elseif (trim(strip_tags($html, '<img>')) === '' && $muffinItems !== '') {
            $html = $this->fromMuffin($muffinItems);
            $this->lastSource = 'muffin';
        }
        $html = (string) preg_replace('/<!--\s*\/?wp:[^>]*?-->\n?/s', '', $html);
        // 先清属性：Tailwind 任意值类名里的方括号（[--x:1px]）会被当成短代码
        $html = self::stripPastedAttributes($html);
        $html = $this->shortcodes($html);
        // 复制网页留下的空标题、空容器（属性清掉后常剩 <h2></h2>、层层空 <div>）
        do {
            $html = (string) preg_replace('~<(h[1-6]|div|span|p)>(?:\s|&nbsp;)*</\1>~i', '', $html, -1, $removed);
        } while ($removed > 0);
        $html = self::autop($html);
        // 表单短代码单独成块，别被包进 <p>（表单不能放在段落里）
        $html = (string) preg_replace('~<p>\s*(\[form-[a-zA-Z0-9_-]+\])\s*</p>~', '$1', $html);
        $html = self::wrapTables($html);
        $html = $this->localizeUrls($html);
        $this->lastIssues = array_sum($this->dropped) + array_sum($this->unknown) - $issuesBefore;
        return trim($html);
    }

    /** WordPress 自己会加、主题要用的 class；其余类名（复制网页带进来的 Tailwind 工具类等）在新站没有样式，去掉。 */
    private const KEEP_CLASS = '/^(?:align(?:left|right|center|none|wide|full)|wp-(?:image|caption|block)[a-z0-9_-]*|size-[a-z0-9_-]+|has-[a-z0-9_-]+|is-[a-z0-9_-]+|attachment-[a-z0-9_-]+|yk-[a-z0-9_-]+|gallery[a-z0-9_-]*)$/i';

    /**
     * 去掉从网页复制带进来的属性：data-*、tabindex、contenteditable、spellcheck，以及 KEEP_CLASS 之外的类名。
     * 其余属性（href、src、alt、style、width…）原样保留。
     */
    public static function stripPastedAttributes(string $html): string
    {
        return (string) preg_replace_callback('/<([a-z][a-z0-9]*)(\s[^<>]*?)?(\/?)>/i', static function (array $m): string {
            $attrs = $m[2] ?? '';
            if (trim($attrs) === '') return $m[0];
            $attrs = (string) preg_replace('/\s(?:data-[a-z0-9_.:-]*|tabindex|contenteditable|spellcheck)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+))?/i', '', $attrs);
            $attrs = (string) preg_replace_callback('/\sclass\s*=\s*(["\'])(.*?)\1/is', static function (array $c): string {
                $keep = array_filter(preg_split('/\s+/', trim($c[2])) ?: [], static fn (string $t): bool => preg_match(self::KEEP_CLASS, $t) === 1);
                return $keep === [] ? '' : ' class="' . implode(' ', $keep) . '"';
            }, $attrs);
            return '<' . $m[1] . rtrim($attrs) . ($m[3] ?? '') . '>';
        }, $html);
    }

    /**
     * 正文里的表格包一层可横向滚动的容器（参数表常有十几列，手机上不撑破版面）。已包过的不重复包。
     */
    public static function wrapTables(string $html): string
    {
        return (string) preg_replace_callback('~(<div class="yk-table-scroll">\s*)?(<table\b.*?</table>)~is',
            static fn (array $m): string => $m[1] !== '' ? $m[0] : '<div class="yk-table-scroll">' . $m[2] . '</div>', $html);
    }

    // ── 短代码 ───────────────────────────────────────────────────────────

    private function shortcodes(string $html): string
    {
        // [caption id=".." ...]<img ...> 说明[/caption] → <figure>
        $html = (string) preg_replace_callback('/\[caption[^\]]*\](.*?)\[\/caption\]/s', static function (array $m): string {
            if (!preg_match('/^(\s*(?:<a [^>]*>)?\s*<img [^>]*>\s*(?:<\/a>)?)(.*)$/s', $m[1], $p)) return $m[1];
            $caption = trim($p[2]);
            return '<figure>' . trim($p[1]) . ($caption !== '' ? '<figcaption>' . $caption . '</figcaption>' : '') . '</figure>';
        }, $html);
        // 图片类：[vc_single_image image="12"]、[gallery ids="1,2"]
        $html = (string) preg_replace_callback('/\[vc_single_image\b([^\]]*)\]/', fn (array $m): string => $this->imagesFor(self::attr($m[1], 'image')), $html);
        $html = (string) preg_replace_callback('/\[(?:vc_gallery|gallery)\b([^\]]*)\]/', function (array $m): string {
            $ids = self::attr($m[1], 'ids') ?: self::attr($m[1], 'images');
            return $this->imagesFor($ids);
        }, $html);
        // Betheme 短代码：[image src=".."] → 图片，[button title=".." link=".."] → 链接，[divider] → 去掉
        $html = (string) preg_replace_callback('/\[image\b([^\]]*)\]/', static function (array $m): string {
            $src = self::attr($m[1], 'src');
            return $src === '' ? '' : '<p><img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="' . htmlspecialchars(self::attr($m[1], 'alt'), ENT_QUOTES) . '"></p>';
        }, $html);
        $html = (string) preg_replace_callback('/\[button\b([^\]]*)\](?:(.*?)\[\/button\])?/s', static function (array $m): string {
            $title = self::attr($m[1], 'title') ?: trim(strip_tags($m[2] ?? ''));
            $link = self::attr($m[1], 'link');
            return $title === '' ? '' : ($link === '' ? '<p>' . $title . '</p>' : '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">' . $title . '</a></p>');
        }, $html);
        $html = (string) preg_replace('/\[divider\b[^\]]*\]/', '', $html);
        // [embed]url[/embed]、[video src=".."] → 链接
        $html = (string) preg_replace_callback('/\[embed[^\]]*\](.*?)\[\/embed\]/s', static fn (array $m): string => '<p><a href="' . htmlspecialchars(trim($m[1]), ENT_QUOTES) . '">' . htmlspecialchars(trim($m[1]), ENT_QUOTES) . '</a></p>', $html);
        // Contact Form 7：导入过的表单换成本站表单短代码；没导入的照旧去掉（下面计数）
        $html = (string) preg_replace_callback('/\[contact-form-7\b([^\]]*)\]/', function (array $m): string {
            $id = self::attr($m[1], 'id');
            $slug = $this->forms[$id] ?? $this->forms[substr($id, 0, 7)] ?? null;
            return $slug === null ? $m[0] : "\n[form-" . $slug . "]\n";
        }, $html);
        // 整个去掉的（表单、幻灯片、商城列表）
        foreach (self::DROPPED as $name) {
            $q = preg_quote($name, '/');
            $html = (string) preg_replace_callback('/\[' . $q . '\b[^\]]*\](?:.*?\[\/' . $q . '\])?/s', function () use ($name): string {
                $this->dropped[$name] = ($this->dropped[$name] ?? 0) + 1;
                return '';
            }, $html);
        }
        // 其余短代码只去标签、保留内容：构建器前缀的，以及文中有成对结束标签的。
        // 单独出现、又不认识的 [xxx …] 可能是正文（如「[see figure 2]」），原样保留，记进报告让人看。
        preg_match_all('/\[\/([a-z][a-z0-9_-]*)\]/i', $html, $closers);
        $paired = array_flip(array_map('strtolower', $closers[1]));
        $ownForms = array_flip(array_map(static fn (string $slug): string => 'form-' . strtolower($slug), $this->forms));
        return (string) preg_replace_callback('/\[(\/?)([a-z][a-z0-9_-]*)((?:\s[^\]]*)?)\/?\]/i', function (array $m) use ($paired, $ownForms): string {
            $name = strtolower($m[2]);
            if (isset($ownForms[$name])) return $m[0];   // 上面换进来的本站表单短代码
            $builder = false;
            foreach (self::BUILDER_PREFIXES as $prefix) {
                if ($name === $prefix || str_starts_with($name, $prefix)) { $builder = true; break; }
            }
            if (!$builder && !isset($paired[$name])) {
                $this->unknown[$name] = ($this->unknown[$name] ?? 0) + 1;
                return $m[0];
            }
            // vc_column_text 等内容块之间补换行，免得 autop 把相邻块粘成一段
            return "\n";
        }, $html);
    }

    private static function attr(string $attrs, string $name): string
    {
        return preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*(["\'])(.*?)\1/', $attrs, $m) ? $m[2] : '';
    }

    private function imagesFor(string $ids): string
    {
        $out = '';
        foreach (preg_split('/\s*,\s*/', trim($ids)) ?: [] as $id) {
            $src = $this->attachments[(int) $id] ?? '';
            if ($src !== '') $out .= '<p><img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt=""></p>' . "\n";
        }
        return $out;
    }

    // ── Elementor ────────────────────────────────────────────────────────

    /** 只取文字、标题、图片三类部件，排版交给新站。 */
    public function fromElementor(string $json): string
    {
        $data = json_decode($json, true);
        if (!is_array($data)) return '';
        $out = '';
        $walk = function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) continue;
                $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
                switch ($node['widgetType'] ?? '') {
                    case 'text-editor':
                        $out .= (string) ($settings['editor'] ?? '') . "\n\n";
                        break;
                    case 'heading':
                        $size = (string) ($settings['header_size'] ?? 'h2');
                        $tag = in_array($size, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $size : 'h2';
                        if ($tag === 'h1') $tag = 'h2';   // 页面标题已是 h1
                        $title = trim((string) ($settings['title'] ?? ''));
                        if ($title !== '') $out .= "<{$tag}>" . $title . "</{$tag}>\n\n";
                        break;
                    case 'image':
                        $image = is_array($settings['image'] ?? null) ? $settings['image'] : [];
                        $src = $this->attachments[(int) ($image['id'] ?? 0)] ?? (string) ($image['url'] ?? '');
                        if ($src !== '') $out .= '<p><img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt=""></p>' . "\n\n";
                        break;
                }
                if (is_array($node['elements'] ?? null)) $walk($node['elements']);
            }
        };
        $walk($data);
        return $out;
    }

    // ── Betheme Muffin 构建器 ─────────────────────────────────────────────

    /**
     * mfn-page-items（base64 后的 PHP 序列化数组：区块 → 包裹 → 项目）里取文字、标题、图片，排版交给新站。
     * 反序列化不允许任何类（allowed_classes=false）：数据来自要迁移的旧库，不信任。
     */
    public function fromMuffin(string $raw): string
    {
        $raw = trim($raw);
        $data = null;
        foreach ([base64_decode($raw, true), $raw] as $candidate) {
            if (!is_string($candidate) || $candidate === '') continue;
            $decoded = @unserialize($candidate, ['allowed_classes' => false]);
            if (is_array($decoded)) { $data = $decoded; break; }
        }
        if ($data === null) return '';
        $out = '';
        $walk = function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $node) {
                if (!is_array($node)) continue;
                $type = (string) ($node['type'] ?? '');
                $attr = is_array($node['attr'] ?? null) ? $node['attr'] : (is_array($node['fields'] ?? null) ? $node['fields'] : []);
                if ($type !== '') $out .= $this->muffinItem($type, $attr);
                foreach (['wraps', 'items'] as $children) {
                    if (is_array($node[$children] ?? null)) $walk($node[$children]);
                }
            }
        };
        $walk($data);
        return $out;
    }

    /** @param array<array-key,mixed> $attr */
    private function muffinItem(string $type, array $attr): string
    {
        $text = static fn (string $key): string => is_string($attr[$key] ?? null) ? trim((string) $attr[$key]) : '';
        $image = static fn (string $src, string $alt = ''): string => $src === '' ? ''
            : '<p><img src="' . htmlspecialchars($src, ENT_QUOTES) . '" alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"></p>' . "\n\n";
        switch ($type) {
            case 'image':
                return $image($text('src'), $text('alt'));
            case 'heading':
            case 'fancy_heading':
                $title = $text('title');
                return ($title !== '' ? '<h2>' . $title . '</h2>' . "\n\n" : '') . ($text('content') !== '' ? $text('content') . "\n\n" : '');
            case 'visual':
            case 'column':
            case 'code':
                return $text('content') !== '' ? $text('content') . "\n\n" : '';
            case 'placeholder':
            case 'divider':
            case 'divider_basic':
            case 'map':
            case 'map_basic':
            case 'slider':
            case 'slider_plugin':
            case 'sliding_box':
                return '';
            default:
                // 其余部件（图文框、按钮、列表、FAQ…）：有图出图，有标题出小标题，有内容出内容
                $out = $image($text('image') ?: $text('src'));
                if ($text('title') !== '') $out .= '<h3>' . $text('title') . '</h3>' . "\n\n";
                if ($text('content') !== '') $out .= $text('content') . "\n\n";
                return $out;
        }
    }

    // ── 段落 ─────────────────────────────────────────────────────────────

    /** 简化版 wpautop：空行分段落、段内换行变 <br>；已是块级标签开头的段原样保留。 */
    public static function autop(string $html): string
    {
        $html = trim($html);
        if ($html === '') return '';
        $block = '(?:table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|iframe|video|audio|script|noscript)';
        // 块级标签前后补空行，便于按空行切段
        $html = (string) preg_replace('!(<' . $block . '[\s/>])!i', "\n\n$1", $html);
        $html = (string) preg_replace('!(</' . $block . '>)!i', "$1\n\n", $html);
        $parts = preg_split('/\n\s*\n+/', $html) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;
            if (preg_match('!^</?' . $block . '[\s/>]!i', $part)) {
                $out[] = $part;
                continue;
            }
            $out[] = '<p>' . (string) preg_replace('/\s*\n\s*/', "<br>\n", $part) . '</p>';
        }
        return implode("\n", $out);
    }

    // ── 网址 ─────────────────────────────────────────────────────────────

    /** href / src 里指向原站的完整网址改成站内路径；语言子域名加语言前缀。 */
    public function localizeUrls(string $html): string
    {
        if ($this->hosts === [] && $this->languageHosts === []) return $html;
        return (string) preg_replace_callback('/\b(href|src)\s*=\s*(["\'])(https?:)?\/\/([^\/"\'\s]+)([^"\']*)\2/i', function (array $m): string {
            $path = $this->localPath(strtolower($m[4]), $m[5]);
            return $path === null ? $m[0] : $m[1] . '=' . $m[2] . $path . $m[2];
        }, $html);
    }

    /** 原站主机下的地址 → 站内路径；别的主机返回 null。 */
    public function localPath(string $host, string $rest): ?string
    {
        $rest = $rest === '' ? '/' : $rest;
        if (in_array($host, $this->hosts, true)) return $rest;
        $lang = $this->languageHosts[$host] ?? null;
        if ($lang === null) return null;
        // 上传文件各语言共用一份，不加前缀
        return str_starts_with($rest, '/wp-content/') ? $rest : '/' . $lang . $rest;
    }
}
