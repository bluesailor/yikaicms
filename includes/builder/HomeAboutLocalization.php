<?php

declare(strict_types=1);

/** Language provenance for ordinary elements created from a classic About block. */
final class HomeAboutLocalization
{
    public const KEY = '_home_about_i18n';
    private const ROLES = [
        'title' => ['heading', 'text', ['override_title']],
        'body' => ['text', 'html', ['override_content']],
        'button' => ['button', 'text', ['override_button_text']],
        'image' => ['image', 'alt', ['override_title']],
        'caption' => ['text', 'html', ['override_tag_title', 'override_tag_description']],
    ];

    /** @param array<string,mixed> $section @param array<string,string> $inherited @return array<string,mixed> */
    public static function bind(array $section, array $inherited): array
    {
        return self::map($section, static function (array $element) use ($inherited): array {
            $role = substr((string) ($element['id'] ?? ''), (int) strrpos((string) ($element['id'] ?? ''), '_') + 1);
            $rule = self::ROLES[$role] ?? null;
            if ($rule === null || ($element['type'] ?? '') !== $rule[0]) {
                return $element;
            }
            $data = $element['data'];
            $fields = [];
            $field = $rule[1];
            $original = (string) ($data[$field] ?? '');
            $parts = [];
            foreach ($rule[2] as $key) {
                $value = $inherited[$key] ?? '';
                if ($value === '') {
                    continue;
                }
                if ($field === 'html') {
                    // 必须与 HomeAboutContent 生成 HTML 时用的是同一个转义函数。
                    // 用生产 e() 会在非法 UTF-8 上返回空串，needle 退化成 '><'，
                    // 命中 </h3><p 这类标签缝隙，读取期把译文插成标签外裸文本（F1）。
                    $needle = self::needle($value);
                    // 退化或多处命中都不能建立绑定：宁可不翻译，也不要错翻或改写结构。
                    if ($needle === null || substr_count($original, $needle) !== 1) {
                        continue;
                    }
                    $parts[$key] = $value;
                    continue;
                }
                if ($original === $value) {
                    $parts[$key] = $value;
                }
            }
            if ($parts !== []) {
                $fields[$field] = ['source' => $original, 'parts' => $parts];
            }
            if ($role === 'button' && ($data['url'] ?? '') === ($inherited['override_button_url'] ?? '')
                && configJsonLang('home_about_link') === '') {
                $fields['url'] = ['source' => $data['url'], 'parts' => ['override_button_url' => $data['url']]];
            }
            if ($fields !== []) {
                $element['data'][self::KEY] = ['lang' => siteLang(), 'fields' => $fields];
            }
            return $element;
        });
    }

    /** Read-time compatibility only: never save, publish or rebuild the layout. @param array<string,mixed> $section @return array<string,mixed> */
    public static function localize(array $section): array
    {
        $language = siteLang();
        $default = (string) config('site_lang', 'zh-CN');
        $target = null;
        $legacyByLanguage = [];
        return self::map($section, static function (array $element) use ($section, $language, $default, &$target, &$legacyByLanguage): array {
            $data = $element['data'];
            if (!array_key_exists(self::KEY, $data)) {
                // Old converters did not retain provenance. Only recognize their exact IDs and field values.
                if (!preg_match('/^((?:about_[a-f0-9]{12}|home_s_[0-9]+))_(title|body|button|image|caption)$/D', (string) ($element['id'] ?? ''), $match)) {
                    return $element;
                }
                $prefix = $match[1];
                $columnIds = array_column($section['columns'] ?? [], 'id');
                if (!in_array($prefix . '_text', $columnIds, true) || !in_array($prefix . '_visual', $columnIds, true)) {
                    return $element;
                }
                $role = $match[2];
                $rule = self::ROLES[$role];
                if (($element['type'] ?? '') !== $rule[0]) {
                    return $element;
                }
                $original = $data[$rule[1]] ?? null;
                if (!is_string($original)) {
                    return $element;
                }
                // 旧快照没记录写作语言。不能假定是「当前默认语言」——站点换过默认语言后
                //（如中文内容、默认改成日语），按默认语言比对永远对不上，译文就再也不生效。
                // 依次按默认语言与其它语言的原始站点值比对，完全一致的那个才是写作语言。
                $authored = null;
                foreach (array_unique([$default, 'zh-CN', 'en', 'ja', 'zh-TW']) as $candidate) {
                    $legacyByLanguage[$candidate] ??= self::legacyValues($candidate);
                    if (self::legacyFieldMatches($role, $original, $legacyByLanguage[$candidate])) {
                        $authored = $candidate;
                        break;
                    }
                }
                if ($authored === null || $authored === $language) {
                    return $element;
                }
                $legacy = $legacyByLanguage[$authored];
                $parts = array_intersect_key($legacy, array_flip($rule[2]));
                $data[self::KEY] = ['lang' => $authored, 'fields' => [$rule[1] => ['source' => $original, 'parts' => $parts]]];
                if ($role === 'button' && ($data['url'] ?? '') === $legacy['override_button_url']
                    && (string) config('home_about_link', '') === '') {
                    $data[self::KEY]['fields']['url'] = ['source' => $data['url'], 'parts' => ['override_button_url' => $data['url']]];
                }
            }
            $binding = $data[self::KEY] ?? null;
            if (!is_array($binding) || !is_string($binding['lang'] ?? null)
                || !in_array($binding['lang'], ['zh-CN', 'zh-TW', 'en', 'ja'], true) || $binding['lang'] === $language
                || !is_array($binding['fields'] ?? null)) {
                return $element;
            }
            $target ??= HomeAboutContent::resolve(channelModel()->findBySlugLang('about'));
            foreach ($binding['fields'] as $field => $entry) {
                $allowed = match ($element['type'] ?? '') {
                    'heading' => ['text'], 'text' => ['html'], 'button' => ['text', 'url'], 'image' => ['alt'], default => [],
                };
                if (!in_array($field, $allowed, true) || !is_array($entry) || !is_string($entry['source'] ?? null)
                    || ($data[$field] ?? null) !== $entry['source'] || !is_array($entry['parts'] ?? null)) {
                    continue;
                }
                // Edited fields detach automatically. A missing translation never erases the saved content.
                $replacements = [];
                $collisions = [];
                foreach ($entry['parts'] as $key => $source) {
                    $allowedKeys = match ($field) {
                        'url' => ['override_button_url'], 'alt' => ['override_title'],
                        'html' => ['override_content', 'override_tag_title', 'override_tag_description'],
                        default => ($element['type'] ?? '') === 'button' ? ['override_button_text'] : ['override_title'],
                    };
                    if (!in_array($key, $allowedKeys, true) || !is_string($source) || $source === '' || !isset($target[$key]) || $target[$key] === '') {
                        continue;
                    }
                    if ($field === 'html') {
                        $needle = self::needle((string) $source);
                        if ($needle === null || substr_count($entry['source'], $needle) !== 1) {
                            // 退化串或多处命中：跳过，不做替换。
                            continue;
                        }
                        if (isset($replacements[$needle]) && $replacements[$needle] !== '>' . self::escape($target[$key]) . '<') {
                            // 标题与描述文本相同，但译文不同：strtr 无法按位置区分，
                            // 两边都撤掉而不是让后者覆盖前者（F4）。
                            $collisions[$needle] = true;
                            continue;
                        }
                        $replacements[$needle] = '>' . self::escape($target[$key]) . '<';
                    } elseif ($entry['source'] === $source) {
                        $data[$field] = $target[$key];
                    }
                }
                if ($field === 'html') {
                    foreach (array_keys($collisions) as $needle) {
                        unset($replacements[$needle]);
                    }
                    $data[$field] = $replacements === [] ? $entry['source'] : strtr($entry['source'], $replacements);
                }
            }
            $element['data'] = $data;
            return $element;
        });
    }

    /**
     * 与 HomeAboutContent 生成 HTML 时**同一个**转义。
     * 单独留一个入口，避免两端再次漂开（v1.19.9 审计 F1 的根因就是漂开了）。
     */
    private static function escape(string $text): string
    {
        return HomeAboutContent::escape($text);
    }

    /**
     * 构造用于在生成 HTML 里定位某个继承值的匹配串。
     *
     * 转义后为空说明这个值无法在 HTML 里被可靠定位（例如含非法 UTF-8），
     * 此时返回 null —— 绝不能退化成 '><'，那会命中标签缝隙。
     */
    private static function needle(string $value): ?string
    {
        $escaped = self::escape($value);
        return $escaped === '' ? null : '>' . $escaped . '<';
    }

    /** @param array<string,mixed> $section @param callable(array<string,mixed>):array<string,mixed> $visit @return array<string,mixed> */
    private static function map(array $section, callable $visit): array
    {
        $walk = static function (array $element) use (&$walk, $visit): array {
            $element['data'] = is_array($element['data'] ?? null) ? $element['data'] : [];
            if (is_array($element['data']['children'] ?? null)) {
                foreach ($element['data']['children'] as $index => $child) {
                    if (is_array($child)) {
                        $element['data']['children'][$index] = $walk($child);
                    }
                }
            }
            return $visit($element);
        };
        foreach ($section['columns'] ?? [] as $columnIndex => $column) {
            foreach ($column['elements'] ?? [] as $elementIndex => $element) {
                if (is_array($element)) {
                    $section['columns'][$columnIndex]['elements'][$elementIndex] = $walk($element);
                }
            }
        }
        return $section;
    }

    /** Old snapshots can only be attributed while their original site values still agree. @return array<string,string> */
    private static function legacyValues(string $language): array
    {
        if (!in_array($language, ['zh-CN', 'zh-TW', 'en', 'ja'], true)) {
            return [];
        }
        $pack = require ROOT_PATH . '/lang/' . ($language === 'zh-TW' ? 'zh-CN' : $language) . '.php';
        $read = static fn(string $key, string $fallback = ''): string => (string) (config($key . '_' . $language, '') ?: config($key, $fallback));
        $site = $read('site_name');
        $channel = channelModel()->findBySlug('about');
        if ($channel !== null && !empty($channel['lang']) && $channel['lang'] !== $language) {
            $channel = channelModel()->siblingForLang((int) $channel['id'], $language);
        }
        return [
            'override_title' => $read('home_about_title') ?: ($site === '' ? $pack['home_about_title'] : str_replace(':site', $site, $pack['home_about_title_site'])),
            'override_content' => $read('home_about_content', $pack['home_about_default']),
            'override_tag_title' => $read('home_about_tag_title'),
            'override_tag_description' => $read('home_about_tag_desc'),
            'override_button_text' => $read('home_about_button') ?: $pack['home_learn_more'],
            'override_button_url' => $read('home_about_link') ?: ($channel === null ? '' : self::channelUrlInLanguage($channel, $language)),
        ];
    }

    /** channelUrl uses the request language, even when passed a translated row. @param array<string,mixed> $channel */
    private static function channelUrlInLanguage(array $channel, string $language): string
    {
        $url = channelUrl($channel);
        if (($channel['type'] ?? '') === 'link') {
            return $url;
        }
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (isset($query['yk_route']) && is_string($query['yk_route'])) {
            if ($language === (string) config('site_lang', 'zh-CN')) {
                unset($query['lang']);
            } else {
                $query['lang'] = $language;
            }
            return (string) parse_url($url, PHP_URL_PATH) . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $prefix = langPrefix();
        if ($prefix !== '' && str_starts_with($url, $prefix . '/')) {
            $url = substr($url, strlen($prefix));
        }
        return langPrefix($language) . $url;
    }

    /** @param array<string,string> $values */
    private static function legacyFieldMatches(string $role, string $original, array $values): bool
    {
        if ($values === []) {
            return false;
        }
        if ($role === 'body') {
            foreach (['', ' text-white'] as $tone) {
                if ($original === '<p class="text-lg leading-relaxed' . $tone . '">' . self::escape($values['override_content']) . '</p>') {
                    return true;
                }
            }
            return false;
        }
        if ($role === 'caption') {
            // Both shipped badge variants, including the first converter's missing paragraph margin reset.
            foreach (['', ' m-0'] as $margin) {
                $html = '<div class="bg-primary text-white rounded-lg p-6">';
                if ($values['override_tag_title'] !== '') {
                    $html .= '<h3 class="text-xl font-bold text-white m-0">' . self::escape($values['override_tag_title']) . '</h3>';
                }
                if ($values['override_tag_description'] !== '') {
                    $html .= '<p class="text-white' . $margin . '">' . self::escape($values['override_tag_description']) . '</p>';
                }
                if ($original === $html . '</div>') {
                    return true;
                }
            }
            return false;
        }
        return $original === ($values[self::ROLES[$role][2][0]] ?? null);
    }
}
