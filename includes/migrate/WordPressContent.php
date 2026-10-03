<?php
declare(strict_types=1);

/**
 * WordPress 正文清理（WordPress 导入用，纯函数，不碰数据库）。
 *
 * - 去掉区块编辑器注释（<!-- wp:… -->）；
 * - 页面构建器短代码（WPBakery vc_*、Divi et_pb_*、Avada fusion_* 等）去掉标签、保留里面的内容；
 *   vc_single_image / gallery 换成图片，caption 换成 figure；contact-form-7 等表单去掉并记下来；
 * - Elementor 页面正文为空时，从 _elementor_data 里取出文字、标题、图片；
 * - 经典编辑器的纯文本段落补 <p>（简化版 wpautop）；
 * - 指向原站的完整网址改成站内路径（语言子域名 ja.example.com/x/ → /ja/x/），图片保留 /wp-content/uploads/ 原路径。
 */
final class WordPressContent
{
    /** 直接去掉标签、保留里面内容的构建器短代码前缀 */
    private const BUILDER_PREFIXES = ['vc_', 'et_pb_', 'fusion_', 'av_', 'cs_', 'x_', 'themify_', 'mk_', 'ux_', 'row', 'col', 'section', 'tabgroup', 'tab', 'accordion', 'toggle'];
    /** 整个去掉（连同内容）的短代码，并记进报告 */
    private const DROPPED = ['contact-form-7', 'contact-form', 'wpforms', 'gravityform', 'rev_slider', 'rev_slider_vc', 'layerslider', 'smartslider3', 'wpml_language_selector_widget', 'wpml_language_switcher', 'woocommerce_cart', 'woocommerce_checkout', 'woocommerce_my_account', 'products', 'product_category', 'recent_products'];

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

    public function clean(string $html, string $elementorJson = ''): string
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);
        if (trim(strip_tags($html, '<img>')) === '' && $elementorJson !== '') {
            $html = $this->fromElementor($elementorJson);
        }
        $html = (string) preg_replace('/<!--\s*\/?wp:[^>]*?-->\n?/s', '', $html);
        $html = $this->shortcodes($html);
        $html = self::autop($html);
        $html = $this->localizeUrls($html);
        return trim($html);
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
        // [embed]url[/embed]、[video src=".."] → 链接
        $html = (string) preg_replace_callback('/\[embed[^\]]*\](.*?)\[\/embed\]/s', static fn (array $m): string => '<p><a href="' . htmlspecialchars(trim($m[1]), ENT_QUOTES) . '">' . htmlspecialchars(trim($m[1]), ENT_QUOTES) . '</a></p>', $html);
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
        return (string) preg_replace_callback('/\[(\/?)([a-z][a-z0-9_-]*)((?:\s[^\]]*)?)\/?\]/i', function (array $m) use ($paired): string {
            $name = strtolower($m[2]);
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
