<?php
/** 前台语言切换器，仅显示后台已启用且物理存在的语言。 */

declare(strict_types=1);

final class LanguageSwitcherElement extends AbstractElement
{
    /**
     * 语言代码 → 随包 SVG 国旗文件名（assets/icons/flags/*.svg）。
     * 不用 emoji 国旗——Windows 各浏览器不渲染 Regional Indicator，只显示 "CN/US" 字母对。
     * en→美(us，站长可预期的通用英文旗)；未列出或缺文件的语言不显示旗。
     * @var array<string,string>
     */
    private const FLAGS = [
        'zh-CN' => 'cn', 'en' => 'us', 'ja' => 'jp',
    ];

    /** 返回内联 <img> 旗帜标签（含尾随空格），无旗则空串。 */
    private static function flagImg(string $code): string
    {
        $file = self::FLAGS[$code] ?? '';
        if ($file === '') {
            return '';
        }
        return '<img src="/assets/icons/flags/' . htmlspecialchars($file, ENT_QUOTES) . '.svg"'
            . ' alt="" aria-hidden="true" class="inline-block h-3.5 w-auto rounded-sm align-[-2px]"> ';
    }

    private static function hasFlag(string $code): bool
    {
        return isset(self::FLAGS[$code]);
    }

    public function type(): string { return 'language-switcher'; }
    public function label(): string { return __('blox_el_language_switcher'); }
    public function icon(): string { return 'language'; }
    public function category(): string { return 'dynamic'; }
    public function isDynamic(): bool { return true; }
    public function supportsBoxStyles(): bool { return false; }

    /** @param array<string,mixed> $data @return list<string> */
    public function scriptsFor(array $data): array
    {
        return ($data['layout'] ?? 'dropdown') === 'inline'
            ? []
            : ['/assets/js/blox-language-switcher.js'];
    }

    public function controls(): array
    {
        return [
            ['key' => 'layout', 'type' => 'select', 'label' => __('blox_language_layout'), 'default' => 'dropdown',
                'options' => ['dropdown' => __('blox_language_layout_dropdown'), 'inline' => __('blox_language_layout_inline')]],
            ['key' => 'display', 'type' => 'select', 'label' => __('blox_language_display'), 'default' => 'name',
                'options' => ['name' => __('blox_language_name'), 'code' => __('blox_language_code')]],
            ['key' => 'show_flag', 'type' => 'checkbox', 'label' => __('blox_language_show_flag'), 'default' => false],
            ['key' => 'tone', 'type' => 'select', 'label' => __('blox_site_tone'), 'default' => 'dark',
                'options' => ['dark' => __('blox_site_tone_dark'), 'light' => __('blox_site_tone_light')]],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $languages = function_exists('enabledLanguages') ? enabledLanguages() : [];
        if (count($languages) < 2) {
            return '';
        }
        $current = function_exists('siteLang') ? siteLang() : (string) array_key_first($languages);
        $default = function_exists('config') ? (string) config('site_lang', 'zh-CN') : 'zh-CN';
        $knownLanguages = function_exists('availableLanguages') ? array_keys(availableLanguages()) : array_keys($languages);
        $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';

        return self::renderForLanguages($languages, $current, $default, $knownLanguages, $requestUri, $data);
    }

    /**
     * @param array<string,string> $languages
     * @param list<string> $knownLanguages
     */
    public static function renderForLanguages(
        array $languages,
        string $current,
        string $default,
        array $knownLanguages,
        string $requestUri,
        array $data = []
    ): string {
        if (count($languages) < 2) {
            return '';
        }
        $light = ($data['tone'] ?? 'dark') === 'light';
        $baseClass = $light ? 'text-white/70 hover:text-white' : 'text-gray-500 hover:text-gray-900';
        $activeClass = $light ? 'bg-white/15 text-white' : 'bg-gray-100 text-gray-900';
        $display = ($data['display'] ?? 'name') === 'code' ? 'code' : 'name';
        $showFlag = !empty($data['show_flag']);
        // 国旗前缀：随包 SVG <img> 前置于文字；aria-hidden 不干扰读屏（hreflang 已表达语言）。
        $flagSpan = static function (string $code) use ($showFlag): string {
            return $showFlag ? self::flagImg($code) : '';
        };
        $items = '';
        foreach ($languages as $code => $name) {
            $label = $display === 'code' ? strtoupper((string) $code) : (string) $name;
            $active = (string) $code === $current;
            $items .= '<a href="' . htmlspecialchars(self::switchUrl($requestUri, (string) $code, $default, $knownLanguages), ENT_QUOTES) . '"'
                . ($active ? ' aria-current="page"' : '')
                . ' hreflang="' . htmlspecialchars((string) $code, ENT_QUOTES) . '"'
                . ' class="rounded px-2 py-1 text-xs font-medium transition ' . ($active ? $activeClass : $baseClass) . '">'
                . $flagSpan((string) $code) . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        }
        $navLabel = htmlspecialchars(__('blox_language_nav_label'), ENT_QUOTES);
        if (($data['layout'] ?? 'dropdown') === 'inline') {
            return '<nav aria-label="' . $navLabel . '" data-yk-language-switcher="inline" class="flex flex-wrap items-center gap-1">'
                . $items . '</nav>';
        }

        $currentName = isset($languages[$current]) ? (string) $languages[$current] : $current;
        $currentLabel = $display === 'code' ? strtoupper($current) : $currentName;
        $triggerClass = $light
            ? 'text-white/80 hover:bg-white/10 hover:text-white focus-visible:ring-white/50'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 focus-visible:ring-primary/40';
        $menuItems = '';
        foreach ($languages as $code => $name) {
            $label = $display === 'code' ? strtoupper((string) $code) : (string) $name;
            $active = (string) $code === $current;
            $menuItems .= '<a href="' . htmlspecialchars(self::switchUrl($requestUri, (string) $code, $default, $knownLanguages), ENT_QUOTES) . '"'
                . ($active ? ' aria-current="page"' : '')
                . ' hreflang="' . htmlspecialchars((string) $code, ENT_QUOTES) . '"'
                . ' class="flex items-center justify-between gap-4 px-3 py-2 text-sm transition '
                . ($active ? 'bg-gray-50 font-medium text-primary' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900') . '">'
                . '<span>' . $flagSpan((string) $code) . htmlspecialchars($label, ENT_QUOTES) . '</span>'
                . ($active ? '<i class="ti ti-check text-base" aria-hidden="true"></i>' : '')
                . '</a>';
        }

        return '<nav aria-label="' . $navLabel . '" data-yk-language-switcher="dropdown" class="relative inline-block">'
            . '<details class="group relative">'
            . '<summary data-yk-language-trigger class="flex cursor-pointer list-none select-none items-center gap-1.5 rounded-md px-2.5 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 [&::-webkit-details-marker]:hidden '
            . $triggerClass . '">'
            . ($showFlag && self::hasFlag($current)
                ? rtrim(self::flagImg($current))
                : '<i class="ti ti-world text-base" aria-hidden="true"></i>')
            . '<span>' . htmlspecialchars($currentLabel, ENT_QUOTES) . '</span>'
            . '<i class="ti ti-chevron-down text-xs transition-transform group-open:rotate-180" aria-hidden="true"></i>'
            . '</summary>'
            . '<div data-yk-language-menu class="absolute right-0 z-50 mt-2 min-w-36 overflow-hidden rounded-md border border-gray-200 bg-white py-1 shadow-lg">'
            . $menuItems . '</div>'
            . '</details></nav>';
    }

    /** @param list<string> $knownLanguages */
    public static function switchUrl(string $requestUri, string $language, string $defaultLanguage, array $knownLanguages): string
    {
        $parts = parse_url($requestUri);
        $path = is_array($parts) && isset($parts['path']) ? (string) $parts['path'] : '/';
        $keepQuery = true;
        if ($path === '' || str_starts_with($path, '/admin/')) {
            $path = '/';
            $keepQuery = false;
        }
        foreach ($knownLanguages as $code) {
            $prefix = '/' . trim((string) $code, '/');
            if ($path === $prefix) {
                $path = '/';
                break;
            }
            if (str_starts_with($path, $prefix . '/')) {
                $path = substr($path, strlen($prefix));
                break;
            }
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        $prefix = $language === $defaultLanguage ? '' : '/' . trim($language, '/');
        $query = '';
        if ($keepQuery && is_array($parts) && isset($parts['query']) && $parts['query'] !== '') {
            parse_str((string) $parts['query'], $queryParams);
            unset($queryParams['_lang']);
            $encoded = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
            $query = $encoded === '' ? '' : '?' . $encoded;
        }
        return $prefix . $path . $query;
    }
}
