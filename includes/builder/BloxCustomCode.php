<?php
/**
 * 建站人员的「高级」配置（V2.0.0）：元素的 HTML ID / 自定义 CSS 类 / 自定义属性 / 自定义 CSS，
 * 以及页面级自定义 CSS 与全局类的自定义 CSS（后两者复用这里的 CSS 净化）。
 *
 * 数据：element.data._html_id / _css_classes / _attributes / _custom_css；文档 settings.custom_css；
 * 区块 settings._css_classes / _custom_css，区块标题与副标题 settings._title_* / _subtitle_*（同元素四项）。
 * ID、类名、属性都走白名单，谁能编辑页面谁就能改；自定义 CSS 能影响全站观感与布局，
 * 只有具备「全站设计」（blox_global）权限的人能新增或修改，保存时由服务端比对把关。
 *
 * CSS 净化不解析选择器（作者本来就被信任写样式），只挡住能越界的东西：
 * 跳出 <style> 的 `<`、@import / @charset / @namespace、expression() / behavior / -moz-binding /
 * javascript:，以及任何外部地址（去掉注释后出现 `//` 即拒绝——杜绝用属性选择器 + 外链把页面内容带出去）。
 * 转义序列先解码再检查，防止 `\75 rl(` 这类绕过；花括号必须配平，避免吞掉后续规则。
 */

declare(strict_types=1);

final class BloxCustomCode
{
    public const ROOT_PLACEHOLDER = '%root%';
    public const ELEMENT_CSS_MAX = 10000;
    public const PAGE_CSS_MAX = 20000;
    public const MAX_CLASSES = 20;
    public const MAX_ATTRIBUTES = 10;
    private const ID_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';
    /** 允许 Tailwind 这类带 : / . [ ] ! 的工具类名；yk- 前缀留给系统（全局类、内部钩子）。 */
    private const CLASS_PATTERN = '/^[A-Za-z_!-][A-Za-z0-9_:.\/\[\]!-]{0,63}$/';
    private const ATTRIBUTE_PATTERN = '/^(?:data-[a-z0-9][a-z0-9_.-]{0,39}|aria-[a-z]{2,30}|role|title|lang|dir|tabindex)$/';
    private const ATTRIBUTE_VALUE_MAX = 500;
    private const FORBIDDEN = ['@import', '@charset', '@namespace', 'expression(', 'javascript:', 'vbscript:', 'behavior:', '-moz-binding', 'src('];
    /** 区块自带的标题字段：各自有一套与元素相同的高级配置，存成 settings._<字段>_html_id 等 */
    public const SECTION_FIELDS = ['title', 'subtitle'];
    private const ADVANCED_KEYS = ['_html_id', '_css_classes', '_attributes', '_custom_css'];

    /** @var array<array-key,true> 本次请求已输出的 ID（同页重复的只保留第一个） */
    private static array $renderedIds = [];

    private static ?bool $canEditOverride = null;

    /** @psalm-suppress PossiblyUnusedMethod 测试专用 */
    public static function resetForTests(?bool $canEdit = null): void
    {
        self::$renderedIds = [];
        self::$canEditOverride = $canEdit;
    }

    /** 当前管理员能否新增/修改自定义 CSS（与全局类、设计系统同一个权限）。 */
    public static function canEditCss(): bool
    {
        if (self::$canEditOverride !== null) {
            return self::$canEditOverride;
        }
        return !function_exists('hasPermission') || hasPermission('blox_global');
    }

    // ── CSS 净化 ────────────────────────────────────────────────────

    /**
     * @return array{css:string,error:string} 合法时 error 为空；css 已去首尾空白。
     */
    public static function checkCss(mixed $value, int $max = self::ELEMENT_CSS_MAX): array
    {
        if (!is_string($value)) {
            return ['css' => '', 'error' => $value === null ? '' : 'invalid'];
        }
        $css = trim(str_replace("\r\n", "\n", $value));
        if ($css === '') {
            return ['css' => '', 'error' => ''];
        }
        if (mb_strlen($css) > $max) {
            return ['css' => $css, 'error' => 'too_long'];
        }
        if (str_contains($css, '<') || preg_match('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', $css) === 1) {
            return ['css' => $css, 'error' => 'markup'];
        }
        // 转义解码后再查：\75 rl( → url(，\2f\2f → //
        $decoded = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?/', static function (array $match): string {
            $code = hexdec($match[1]);
            $char = $code > 0 && $code < 0x110000 ? mb_chr((int) $code, 'UTF-8') : '';
            return is_string($char) ? $char : '';
        }, $css) ?? $css;
        $decoded = preg_replace('/\\\\(.)/su', '$1', $decoded) ?? $decoded;
        $plain = preg_replace('#/\*.*?\*/#s', '', $decoded) ?? $decoded;
        if (str_contains($plain, '/*')) {
            return ['css' => $css, 'error' => 'comment'];
        }
        $compact = strtolower(preg_replace('/\s+/', '', $plain) ?? $plain);
        foreach (self::FORBIDDEN as $needle) {
            if (str_contains($compact, $needle)) {
                return ['css' => $css, 'error' => 'forbidden'];
            }
        }
        if (str_contains($compact, '//')) {
            return ['css' => $css, 'error' => 'external'];
        }
        $depth = 0;
        foreach (str_split(preg_replace('/"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'/s', '""', $plain) ?? $plain) as $char) {
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth < 0) {
                    return ['css' => $css, 'error' => 'braces'];
                }
            }
        }
        if ($depth !== 0) {
            return ['css' => $css, 'error' => 'braces'];
        }
        return ['css' => $css, 'error' => ''];
    }

    /**
     * 把 %root% 换成目标选择器；只写了声明（没有花括号）时自动套到目标上。
     * 调用前必须已通过 checkCss。
     */
    public static function scope(string $css, string $selector): string
    {
        if ($css === '') {
            return '';
        }
        if (!str_contains($css, '{')) {
            return $selector . '{' . $css . '}';
        }
        return str_replace(self::ROOT_PLACEHOLDER, $selector, $css);
    }

    public static function errorMessage(string $error): string
    {
        return __('blox_custom_css_error_' . $error);
    }

    // ── 元素数据归一（文档管线调用） ──────────────────────────────────

    public static function normalizeElementData(array $data): array
    {
        if (array_key_exists('_html_id', $data)) {
            $id = is_string($data['_html_id']) ? trim($data['_html_id']) : '';
            if (preg_match(self::ID_PATTERN, $id) === 1) {
                $data['_html_id'] = $id;
            } else {
                unset($data['_html_id']);
            }
        }
        if (array_key_exists('_css_classes', $data)) {
            $classes = self::classList($data['_css_classes']);
            if ($classes === '') {
                unset($data['_css_classes']);
            } else {
                $data['_css_classes'] = $classes;
            }
        }
        if (array_key_exists('_attributes', $data)) {
            $attributes = self::attributeList($data['_attributes']);
            if ($attributes === []) {
                unset($data['_attributes']);
            } else {
                $data['_attributes'] = $attributes;
            }
        }
        if (array_key_exists('_custom_css', $data)) {
            $css = is_string($data['_custom_css']) ? trim(str_replace("\r\n", "\n", $data['_custom_css'])) : '';
            if ($css === '') {
                unset($data['_custom_css']);
            } else {
                // 非法 CSS 不在这里静默丢弃：保存校验会报出具体原因，渲染时跳过
                $data['_custom_css'] = mb_substr($css, 0, self::ELEMENT_CSS_MAX + 1);
            }
        }
        return $data;
    }

    /** 区块 settings：CSS 类与自定义 CSS（ID 用区块自己的 anchor_id，不另存）。 */
    public static function normalizeSectionSettings(array $settings): array
    {
        if (array_key_exists('_css_classes', $settings)) {
            $classes = self::classList($settings['_css_classes']);
            if ($classes === '') {
                unset($settings['_css_classes']);
            } else {
                $settings['_css_classes'] = $classes;
            }
        }
        if (array_key_exists('_custom_css', $settings)) {
            $css = is_string($settings['_custom_css']) ? trim(str_replace("\r\n", "\n", $settings['_custom_css'])) : '';
            if ($css === '') {
                unset($settings['_custom_css']);
            } else {
                $settings['_custom_css'] = mb_substr($css, 0, self::ELEMENT_CSS_MAX + 1);
            }
        }
        foreach (self::SECTION_FIELDS as $field) {
            $prefix = '_' . $field;
            $data = self::normalizeElementData(self::sectionFieldData($settings, $field));
            foreach (self::ADVANCED_KEYS as $key) {
                if (isset($data[$key])) {
                    $settings[$prefix . $key] = $data[$key];
                } else {
                    unset($settings[$prefix . $key]);
                }
            }
        }
        return $settings;
    }

    /** 区块 settings 里所有自定义 CSS 的键（区块本身 + 各标题字段），保存校验与权限比对共用。 */
    public static function sectionCssKeys(): array
    {
        $keys = ['_custom_css'];
        foreach (self::SECTION_FIELDS as $field) {
            $keys[] = '_' . $field . '_custom_css';
        }
        return $keys;
    }

    /** 把 settings._title_html_id 这类键取成元素 data 的形状（_html_id …），复用元素的归一与渲染。 */
    private static function sectionFieldData(array $settings, string $field): array
    {
        $data = [];
        foreach (self::ADVANCED_KEYS as $key) {
            if (array_key_exists('_' . $field . $key, $settings)) {
                $data[$key] = $settings['_' . $field . $key];
            }
        }
        return $data;
    }

    /** 区块标题 / 副标题的根标签：与元素一样写 ID、类、属性和作用域 CSS。 */
    public static function applyToSectionField(string $html, array $settings, string $field, string $sectionId): string
    {
        return self::applyToElement($html, self::sectionFieldData($settings, $field),
            $sectionId !== '' ? 'section:' . $sectionId . ':' . $field : '');
    }

    /**
     * 区块根标签 <section> 上追加的类（CSS 类 + 自定义 CSS 的作用域类），前导空格；CSS 交给资源收集器。
     * 区块 ID 由渲染器按 anchor_id 输出，这里不管。
     */
    public static function sectionClasses(array $settings, string $sectionId): string
    {
        $classes = is_string($settings['_css_classes'] ?? null) ? self::classList($settings['_css_classes']) : '';
        $css = self::checkCss($settings['_custom_css'] ?? null);
        if ($css['error'] === '' && $css['css'] !== '') {
            $scopeClass = 'yk-css-' . substr(hash('sha256', 'section:' . ($sectionId !== '' ? $sectionId : $css['css'])), 0, 10);
            $classes = trim($classes . ' ' . $scopeClass);
            BloxAssetCollector::addInlineCss(self::scope($css['css'], '.' . $scopeClass . '.' . $scopeClass));
        }
        return $classes === '' ? '' : ' ' . $classes;
    }

    public static function classList(mixed $value): string
    {
        $tokens = is_array($value) ? $value : preg_split('/\s+/', is_string($value) ? trim($value) : '');
        $clean = [];
        foreach (is_array($tokens) ? $tokens : [] as $token) {
            $token = is_string($token) ? trim($token) : '';
            if ($token === '' || preg_match(self::CLASS_PATTERN, $token) !== 1 || str_starts_with(strtolower($token), 'yk-')
                || in_array($token, $clean, true)) {
                continue;
            }
            $clean[] = $token;
            if (count($clean) >= self::MAX_CLASSES) {
                break;
            }
        }
        return implode(' ', $clean);
    }

    /** @return list<array{name:string,value:string}> */
    public static function attributeList(mixed $value): array
    {
        $clean = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = strtolower(trim((string) ($item['name'] ?? '')));
            // data-yk-* 是编辑器与运行时的内部钩子，不让作者伪造
            if (preg_match(self::ATTRIBUTE_PATTERN, $name) !== 1 || str_starts_with($name, 'data-yk')) {
                continue;
            }
            $attributeValue = preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) ($item['value'] ?? '')) ?? '';
            if ($name === 'tabindex' && !in_array($attributeValue, ['0', '-1'], true)) {
                continue;
            }
            $clean[$name] = ['name' => $name, 'value' => mb_substr($attributeValue, 0, self::ATTRIBUTE_VALUE_MAX)];
            if (count($clean) >= self::MAX_ATTRIBUTES) {
                break;
            }
        }
        return array_values($clean);
    }

    // ── 保存校验：非法 CSS 报出原因；无权限者不能新增/修改（有可信基线时由 BloxProtectedFields 比对） ──

    /** @param array<int,mixed> $sections */
    public static function assertSectionsAllowed(array $sections, bool $structureOnly = false): void
    {
        $canEdit = $structureOnly || self::canEditCss();
        // 区块 settings 与元素 data 用同一套判定：_custom_css 必须合法，无权限者不能新增或修改
        foreach ($sections as $section) {
            if (is_array($section) && is_array($section['settings'] ?? null)) {
                foreach (self::sectionCssKeys() as $key) {
                    self::assertCssValue($section['settings'][$key] ?? null, $canEdit);
                }
            }
        }
        self::walk($sections, static function (array $data) use ($canEdit): void {
            self::assertCssValue($data['_custom_css'] ?? null, $canEdit);
        });
    }

    private static function assertCssValue(mixed $css, bool $canEdit): void
    {
        if ($css === null || $css === '') {
            return;
        }
        $check = self::checkCss($css);
        if ($check['error'] !== '') {
            throw new RuntimeException(self::errorMessage($check['error']));
        }
        if (!$canEdit) {
            throw new RuntimeException(__('blox_custom_css_permission'));
        }
    }

    /** @param array<string,mixed> $settings 文档 settings */
    public static function assertDocumentCssAllowed(array $settings, ?array $trustedSettings, bool $structureOnly = false): void
    {
        $css = $settings['custom_css'] ?? '';
        if (!is_string($css) || $css === '') {
            return;
        }
        $check = self::checkCss($css, self::PAGE_CSS_MAX);
        if ($check['error'] !== '') {
            throw new RuntimeException(self::errorMessage($check['error']));
        }
        $unchanged = $trustedSettings !== null && ($trustedSettings['custom_css'] ?? '') === $css;
        if (!$structureOnly && !$unchanged && !self::canEditCss()) {
            throw new RuntimeException(__('blox_custom_css_permission'));
        }
    }

    /** @param array<int,mixed> $sections @param callable(array<string,mixed>):void $visit */
    private static function walk(array $sections, callable $visit): void
    {
        $walkElement = static function (array $element) use (&$walkElement, $visit): void {
            $data = is_array($element['data'] ?? null) ? $element['data'] : [];
            $visit($data);
            foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
                if (is_array($child)) {
                    $walkElement($child);
                }
            }
        };
        foreach ($sections as $section) {
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        $walkElement($element);
                    }
                }
            }
        }
    }

    // ── 渲染 ────────────────────────────────────────────────────────

    /**
     * 写到元素根标签：ID（根上已有 id 时不覆盖，同页重复的只保留第一个）、CSS 类、属性，
     * 以及自定义 CSS 的作用域类（CSS 本身交给资源收集器在样式区统一输出）。
     */
    public static function applyToElement(string $html, array $data, string $nodeId): string
    {
        $id = is_string($data['_html_id'] ?? null) ? $data['_html_id'] : '';
        $classes = is_string($data['_css_classes'] ?? null) ? self::classList($data['_css_classes']) : '';
        $attributes = self::attributeList($data['_attributes'] ?? []);
        $css = self::checkCss($data['_custom_css'] ?? null);
        $hasCss = $css['error'] === '' && $css['css'] !== '';
        if ($html === '' || ($id === '' && $classes === '' && $attributes === [] && !$hasCss)) {
            return $html;
        }
        $processor = new HtmlTagRewriter($html);
        if (!$processor->nextTag()) {
            return $html;
        }
        if ($id !== '' && preg_match(self::ID_PATTERN, $id) === 1 && !is_string($processor->getAttribute('id'))
            && !isset(self::$renderedIds[$id])) {
            $processor->setAttribute('id', $id);
            self::$renderedIds[$id] = true;
        }
        foreach ($attributes as $attribute) {
            $processor->setAttribute($attribute['name'], $attribute['value']);
        }
        $extra = $classes;
        if ($hasCss) {
            $scopeClass = 'yk-css-' . substr(hash('sha256', $nodeId !== '' ? $nodeId : $css['css']), 0, 10);
            $extra = trim($extra . ' ' . $scopeClass);
            // 双写类名把特异性抬到 0,2,0：高于全局类的基础规则，写 !important 才能压过元素的内联值
            BloxAssetCollector::addInlineCss(self::scope($css['css'], '.' . $scopeClass . '.' . $scopeClass));
        }
        if ($extra !== '') {
            $existing = $processor->getAttribute('class');
            $processor->setAttribute('class', trim((is_string($existing) ? $existing : '') . ' ' . $extra));
        }
        return $processor->getUpdatedHtml();
    }

    /** 页面级 CSS：已净化后输出；%root% 指向 body，只写声明时套到 body 上。 */
    public static function collectDocumentCss(array $settings): void
    {
        $check = self::checkCss($settings['custom_css'] ?? null, self::PAGE_CSS_MAX);
        if ($check['error'] === '' && $check['css'] !== '') {
            BloxAssetCollector::addInlineCss(self::scope($check['css'], 'body'));
        }
    }
}
