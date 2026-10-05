<?php
/**
 * Blox site-wide typography, button and layout theme (E04): normalize, compile, draft and publish.
 *
 * 2.0.5 设计系统 2.0（RFC-1 决策 1，三源归一第一步）：排版角色的字号 / 字重 / 行高以排版 token
 * theme-<角色>（BloxDesignType，存在 blox_design_system）为准，本类成为它们的编辑视图：
 *   - 发布时双写 token（syncTypographyTokens）；设计系统页也能直接改这些 token；
 *   - 编译时 token 存在就引用它（--yk-type-h1-size:var(--yk-typo-theme-h1-size) 等，旧变量名保留为别名），
 *     不存在（老站还没同步过）就照旧输出原值——两种情况前台计算结果相同（金丝雀页守护）；
 *   - 字体、颜色、按钮、布局仍在本类。
 */

declare(strict_types=1);

final class BloxDesignTheme
{
    public const DRAFT_KEY = 'blox_design_theme_draft';
    public const PUBLISHED_KEY = 'blox_design_theme';
    public const ROLES = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'body', 'caption'];

    private const FAMILIES = [
        'system-sans' => 'system-ui,-apple-system,"Segoe UI","PingFang SC","Hiragino Sans","Noto Sans CJK SC","Microsoft YaHei","Yu Gothic UI",sans-serif',
        'system-serif' => '"Songti SC","Noto Serif CJK SC","Hiragino Mincho ProN","Yu Mincho",serif',
        'system-mono' => 'ui-monospace,"SFMono-Regular",Consolas,monospace',
    ];
    private const WEIGHTS = ['400', '500', '600', '700', '800'];
    private const RADIUS = ['none' => '0', 'sm' => '0.25rem', 'md' => '0.5rem', 'lg' => '0.75rem', 'full' => '9999px'];
    private const TOKEN_PATTERN = '/^[a-z][a-z0-9_-]{0,47}$/';
    // 与 Tailwind 断点一致：基础值给手机，md 起平板，lg 起桌面。
    private const TABLET_MIN = 768;
    private const DESKTOP_MIN = 1024;

    /**
     * 只保留明确设置且合法的值；空串/缺失表示继承，数值 0 是有效值。
     *
     * @return array{typography:array<string,array<string,mixed>>,buttons:array<string,mixed>,layout:array<string,mixed>}
     */
    public static function normalize(mixed $state): array
    {
        $state = is_array($state) ? $state : [];
        $typography = [];
        $rawTypography = is_array($state['typography'] ?? null) ? $state['typography'] : [];
        foreach (self::ROLES as $role) {
            $raw = is_array($rawTypography[$role] ?? null) ? $rawTypography[$role] : [];
            $item = array_filter([
                'family' => isset(self::FAMILIES[(string) ($raw['family'] ?? '')]) ? (string) $raw['family'] : null,
                'size' => self::responsive($raw['size'] ?? null, 12, 96) ?: null,
                'weight' => in_array((string) ($raw['weight'] ?? ''), self::WEIGHTS, true) ? (string) $raw['weight'] : null,
                'line_height' => self::decimal($raw['line_height'] ?? null, 1.0, 2.2),
                'color' => self::token($raw['color'] ?? null),
            ], static fn(mixed $value): bool => $value !== null);
            if ($item !== []) {
                $typography[$role] = $item;
            }
        }

        $rawButtons = is_array($state['buttons'] ?? null) ? $state['buttons'] : [];
        $buttons = array_filter([
            'size' => self::responsive($rawButtons['size'] ?? null, 12, 24) ?: null,
            'padding_x' => self::integer($rawButtons['padding_x'] ?? null, 0, 48),
            'padding_y' => self::integer($rawButtons['padding_y'] ?? null, 0, 48),
            'radius' => isset(self::RADIUS[(string) ($rawButtons['radius'] ?? '')]) ? (string) $rawButtons['radius'] : null,
        ], static fn(mixed $value): bool => $value !== null);
        $variants = [];
        $rawVariants = is_array($rawButtons['variants'] ?? null) ? $rawButtons['variants'] : [];
        foreach (['filled', 'outline', 'text'] as $name) {
            $variant = self::normalizeVariant($rawVariants[$name] ?? null);
            if ($variant !== []) {
                $variants[$name] = $variant;
            }
        }
        if ($variants !== []) {
            $buttons['variants'] = $variants;
        }

        $rawLayout = is_array($state['layout'] ?? null) ? $state['layout'] : [];
        $layout = array_filter([
            'content_max_width' => self::integer($rawLayout['content_max_width'] ?? null, 640, 1600),
            'section_spacing' => self::responsive($rawLayout['section_spacing'] ?? null, 0, 200) ?: null,
            'container_gap' => self::responsive($rawLayout['container_gap'] ?? null, 0, 96) ?: null,
        ], static fn(mixed $value): bool => $value !== null);

        return ['typography' => $typography, 'buttons' => $buttons, 'layout' => $layout];
    }

    /** 编译为 CSS 文本（不含 style 标签）。空主题返回空串，前台输出与未启用时一致。 */
    public static function compile(array $theme): string
    {
        $theme = self::normalize($theme);
        $vars = ['base' => [], 'tablet' => [], 'desktop' => []];
        $rules = [];

        $tokens = self::typographyTokens();
        foreach ($theme['typography'] as $role => $item) {
            $prefix = '--yk-type-' . $role;
            $selector = in_array($role, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
                ? $role . '.yk-type-' . $role
                : '.yk-type-' . $role;
            $token = $tokens[self::TOKEN_ROLE_PREFIX . $role] ?? null;
            $tokenVar = '--yk-typo-' . self::TOKEN_ROLE_PREFIX . $role;
            $declarations = '';
            if (isset($item['size'])) {
                if ($token !== null) {
                    // token 的字号变量自带平板 / 手机改写；旧变量名保留为它的别名
                    $vars['base'][] = $prefix . '-size:var(' . $tokenVar . '-size)';
                    $declarations .= 'font-size:var(' . $prefix . '-size);';
                } else {
                    $declarations .= self::responsiveDeclaration($vars, $rules, $selector, 'font-size', $prefix . '-size', $item['size']);
                }
            }
            if (isset($item['family'])) {
                $declarations .= 'font-family:' . self::FAMILIES[$item['family']] . ';';
            }
            if (isset($item['weight'])) {
                $declarations .= 'font-weight:' . ($token !== null && ($token['weight'] ?? '') !== '' ? 'var(' . $tokenVar . '-weight)' : $item['weight']) . ';';
            }
            if (isset($item['line_height'])) {
                $declarations .= 'line-height:' . ($token !== null && ($token['line_height'] ?? '') !== '' ? 'var(' . $tokenVar . '-lh)' : $item['line_height']) . ';';
            }
            if (isset($item['color'])) {
                $declarations .= 'color:var(--yk-color-' . $item['color'] . ');';
            }
            if ($declarations !== '') {
                $rules[] = $selector . '{' . $declarations . '}';
            }
        }

        $button = '';
        if (isset($theme['buttons']['size'])) {
            $button .= self::responsiveDeclaration($vars, $rules, 'a.yk-btn-theme', 'font-size', '--yk-btn-size', $theme['buttons']['size']);
        }
        if (isset($theme['buttons']['padding_y']) || isset($theme['buttons']['padding_x'])) {
            $button .= 'padding:' . (int) ($theme['buttons']['padding_y'] ?? 12) . 'px ' . (int) ($theme['buttons']['padding_x'] ?? 24) . 'px;';
        }
        if (isset($theme['buttons']['radius'])) {
            $button .= 'border-radius:' . self::RADIUS[$theme['buttons']['radius']] . ';';
        }
        if ($button !== '') {
            $rules[] = 'a.yk-btn-theme{' . $button . '}';
        }
        foreach (($theme['buttons']['variants'] ?? []) as $name => $preset) {
            // Only configured properties override the element's existing variant defaults.
            $selector = 'a.yk-btn-v-' . $name;
            $base = self::variantDeclarations($preset);
            if ($base !== '') {
                $rules[] = $selector . '{' . $base . '}';
            }
            if (isset($preset['hover'])) {
                $rules[] = $selector . '.yk-btn-v-hover:hover{' . self::variantDeclarations($preset['hover']) . '}';
            }
            if (isset($preset['focus_color'])) {
                $rules[] = $selector . ':focus-visible{outline:2px solid var(--yk-color-' . $preset['focus_color'] . ');outline-offset:2px}';
            }
            if (isset($preset['disabled'])) {
                // aria-disabled 是设计契约：样式先行；当前 button 元素无禁用选项，不声称行为禁用。
                $rules[] = $selector . '[aria-disabled="true"]{' . self::variantDeclarations($preset['disabled']) . 'opacity:.55;pointer-events:none}';
            }
        }

        if (isset($theme['layout']['content_max_width'])) {
            $vars['base'][] = '--yk-layout-max-width:' . $theme['layout']['content_max_width'] . 'px';
            $rules[] = 'div.yk-width-theme{max-width:var(--yk-layout-max-width);}';
        }
        if (isset($theme['layout']['section_spacing'])) {
            // A mobile-only override must not leak into wider screens; 32px is the legacy md spacing.
            $spacing = array_replace(['d' => 32], $theme['layout']['section_spacing']);
            self::addResponsive($vars, '--yk-layout-section-spacing', $spacing, 'px');
            $rules[] = 'section.yk-section-space-theme{padding-top:var(--yk-layout-section-spacing);padding-bottom:var(--yk-layout-section-spacing);}';
        }
        if (isset($theme['layout']['container_gap'])) {
            $gap = self::responsiveDeclaration($vars, $rules, 'div.yk-gap-theme', 'gap', '--yk-layout-gap', $theme['layout']['container_gap']);
            if ($gap !== '') {
                $rules[] = 'div.yk-gap-theme{' . $gap . '}';
            }
        }

        $css = '';
        if ($vars['base'] !== []) {
            $css .= ':root{' . implode(';', $vars['base']) . ';}';
        }
        if ($vars['tablet'] !== []) {
            $css .= '@media (min-width:' . self::TABLET_MIN . 'px){:root{' . implode(';', $vars['tablet']) . ';}}';
        }
        if ($vars['desktop'] !== []) {
            $css .= '@media (min-width:' . self::DESKTOP_MIN . 'px){:root{' . implode(';', $vars['desktop']) . ';}}';
        }
        return $css . implode('', $rules);
    }

    /** @var array{typography:array<string,array<string,mixed>>,buttons:array<string,mixed>,layout:array<string,mixed>}|null */
    private static ?array $publishedCache = null;

    /** @var array{typography:array<string,array<string,mixed>>,buttons:array<string,mixed>,layout:array<string,mixed>}|null */
    private static ?array $previewState = null;

    /**
     * Scope both compiled styles and consumer markers to this render, including nested previews.
     * @template T
     * @param callable():T $render
     * @return T
     * @psalm-suppress PossiblyUnusedReturnValue Preserve callback results for nested renderers.
     */
    public static function withPreviewState(array $state, callable $render): mixed
    {
        $previous = self::$previewState;
        self::$previewState = self::normalize($state);
        try {
            return $render();
        } finally {
            self::$previewState = $previous;
        }
    }

    /** 已发布主题（前台读取，只读设置缓存；同一请求内元素渲染共用一次解析）。 */
    public static function published(): array
    {
        if (self::$previewState !== null) {
            return self::$previewState;
        }
        if (self::$publishedCache === null) {
            $decoded = json_decode((string) (function_exists('config') ? config(self::PUBLISHED_KEY, '') : ''), true);
            self::$publishedCache = self::normalize(is_array($decoded) ? ($decoded['state'] ?? []) : []);
        }
        return self::$publishedCache;
    }

    public static function resetCache(): void
    {
        self::$publishedCache = null;
    }

    /**
     * 把 theme-<角色> 排版 token 的字号（只认整数 px）、字重、行高叠回主题状态，供全站排版编辑器显示。
     * @param array{typography:array<string,array<string,mixed>>,buttons:array<string,mixed>,layout:array<string,mixed>} $theme
     * @return array{typography:array<string,array<string,mixed>>,buttons:array<string,mixed>,layout:array<string,mixed>}
     */
    private static function withTokenValues(array $theme): array
    {
        $tokens = self::typographyTokens();
        foreach (array_keys($theme['typography']) as $role) {
            $token = $tokens[self::TOKEN_ROLE_PREFIX . $role] ?? null;
            if ($token === null) continue;
            $size = [];
            foreach ((array) ($token['size'] ?? []) as $device => $value) {
                if (preg_match('/^(\d{2})px$/', (string) $value, $m) === 1 && (int) $m[1] >= 12 && (int) $m[1] <= 96) $size[$device] = (int) $m[1];
            }
            if (isset($size['d'])) $theme['typography'][$role]['size'] = $size;
            if (in_array((string) ($token['weight'] ?? ''), self::WEIGHTS, true)) $theme['typography'][$role]['weight'] = (string) $token['weight'];
            $lh = self::decimal($token['line_height'] ?? null, 1.0, 2.2);
            if ($lh !== null) $theme['typography'][$role]['line_height'] = $lh;
        }
        return self::normalize($theme);
    }

    /** 排版角色对应的 token id 前缀：theme-h1、theme-body … */
    public const TOKEN_ROLE_PREFIX = 'theme-';

    /** @return array<string,array<string,mixed>> 设计系统里的排版 token（id → 项） */
    private static function typographyTokens(): array
    {
        if (!class_exists(BloxDesignSystem::class)) return [];
        return array_column(BloxDesignSystem::snapshot()['typography'] ?? [], null, 'id');
    }

    /**
     * 把排版角色写成 theme-<角色> 排版 token（发布时；设计系统页打开时补缺）。只写字号、字重、行高——
     * token 管不了字体与颜色，这两项留在本类。$onlyMissing=true 时只补还没有的（不覆盖站长在设计系统页改过的值）。
     */
    public static function syncTypographyTokens(array $state, bool $onlyMissing = false): void
    {
        $theme = self::normalize($state);
        $items = [];
        foreach ($theme['typography'] as $role => $item) {
            if (!isset($item['size']['d'])) continue;   // 没有桌面字号的角色不成 token（token 要求桌面字号）
            $size = [];
            foreach (['d', 't', 'm'] as $device) {
                if (isset($item['size'][$device])) $size[$device] = $item['size'][$device] . 'px';
            }
            $items[] = [
                'id' => self::TOKEN_ROLE_PREFIX . $role,
                'name' => __('blox_typo_theme_role', ['role' => str_starts_with($role, 'h') ? strtoupper($role) : __('blox_design_theme_role_' . $role)]),
                'size' => $size,
                'line_height' => isset($item['line_height']) ? rtrim(rtrim(number_format((float) $item['line_height'], 2, '.', ''), '0'), '.') : '',
                'weight' => (string) ($item['weight'] ?? ''),
                'letter_spacing' => '',
            ];
        }
        // 发布时：没成 token 的角色（未设置、或只设了平板 / 手机）删掉旧的 theme-* token；只补缺时不删
        $synced = array_column($items, 'id');
        $remove = $onlyMissing ? [] : array_values(array_filter(array_map(static fn (string $role): string => self::TOKEN_ROLE_PREFIX . $role, self::ROLES),
            static fn (string $id): bool => !in_array($id, $synced, true)));
        if (($items !== [] || $remove !== []) && class_exists(BloxDesignSystem::class)) {
            BloxDesignSystem::upsertTypography($items, $onlyMissing, $remove);
        }
    }

    public static function hasTypography(string $role): bool
    {
        return isset(self::published()['typography'][$role]);
    }

    public static function hasButtons(): bool
    {
        // yk-btn-theme 是几何规则（尺寸/内边距/圆角）的挂载点；只配置变体颜色时不挂，
        // 无规则空类会让未配置几何的站点输出偏离基线。
        $buttons = self::published()['buttons'];
        return isset($buttons['size']) || isset($buttons['padding_x'])
            || isset($buttons['padding_y']) || isset($buttons['radius']);
    }

    /**
     * 局部按钮变体到主题预设名的映射：primary→filled、outline→outline、ghost→text。
     * dark/soft/link 无映射；未配置对应预设返回空串，元素保持原路径。
     */
    public static function buttonVariantPreset(string $variant): string
    {
        $preset = ['primary' => 'filled', 'outline' => 'outline', 'ghost' => 'text'][$variant] ?? '';
        if ($preset === '' || !isset(self::published()['buttons']['variants'][$preset])) {
            return '';
        }
        return $preset;
    }

    public static function hasButtonVariantFocus(string $preset): bool
    {
        return isset(self::published()['buttons']['variants'][$preset]['focus_color']);
    }

    public static function hasButtonVariantBorder(string $preset): bool
    {
        $variant = self::published()['buttons']['variants'][$preset] ?? [];
        return isset($variant['border_color']) || isset($variant['hover']['border_color'])
            || isset($variant['disabled']['border_color']);
    }

    public static function hasContainerGap(): bool
    {
        return isset(self::published()['layout']['container_gap']);
    }

    public static function hasContentWidth(): bool
    {
        return isset(self::published()['layout']['content_max_width']);
    }

    public static function hasSectionSpacing(): bool
    {
        return isset(self::published()['layout']['section_spacing']);
    }

    /**
     * @return array{draft:array<string,mixed>,published:array<string,mixed>,revision:int,published_revision:int,has_draft:bool}
     */
    public static function snapshot(): array
    {
        $raw = BloxDocumentWriteLock::rawSettings([self::DRAFT_KEY, self::PUBLISHED_KEY]);
        $published = self::decode($raw[self::PUBLISHED_KEY]);
        $draft = self::decode($raw[self::DRAFT_KEY]);
        // 三源归一：编辑器看到的已发布值以 theme-<角色> 排版 token 为准（站长可能在设计系统页改过）
        $publishedState = self::withTokenValues(self::normalize($published['state'] ?? []));
        $draftState = isset($draft['state']) ? self::normalize($draft['state']) : $publishedState;
        return [
            'draft' => $draftState,
            'published' => $publishedState,
            'revision' => max(0, (int) ($draft['revision'] ?? 0)),
            'published_revision' => max(0, (int) ($published['revision'] ?? 0)),
            'has_draft' => $draftState !== $publishedState,
        ];
    }

    public static function saveDraft(array $state, int $expectedRevision): array
    {
        return self::write($state, $expectedRevision, false);
    }

    public static function publish(array $state, int $expectedRevision): array
    {
        // 整页缓存由正式键写入触发的 data_changed 在提交后失效（HtmlCache::invalidateAfterCommit）；
        // cacheClear() 只清 storage/cache/*.cache，不负责页面输出。
        return self::write($state, $expectedRevision, true);
    }

    private static function write(array $state, int $expectedRevision, bool $publish): array
    {
        $keys = [self::DRAFT_KEY, self::PUBLISHED_KEY];
        $raw = BloxDocumentWriteLock::rawSettings($keys);
        $current = self::decode($raw[self::DRAFT_KEY]);
        if ($expectedRevision !== max(0, (int) ($current['revision'] ?? 0))) {
            throw new RuntimeException(__('blox_save_conflict'));
        }
        $revision = $expectedRevision + 1;
        $normalized = self::normalize($state);
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        BloxDocumentWriteLock::settings(self::DRAFT_KEY, $raw, static function () use ($normalized, $revision, $publish, $flags): void {
            $draft = json_encode(['revision' => $revision, 'updated_at' => time(), 'state' => $normalized], $flags);
            settingModel()->set(self::DRAFT_KEY, $draft, 'blox');
            if ($publish) {
                settingModel()->set(self::PUBLISHED_KEY, json_encode([
                    'revision' => $revision, 'published_at' => time(), 'state' => $normalized,
                ], $flags), 'blox');
            }
        }, '', 'blox');
        if ($publish) {
            // 三源归一：发布即把排版角色写成 theme-<角色> 排版 token（覆盖），前台改为引用 token
            self::syncTypographyTokens($normalized);
        }
        self::resetCache();
        return self::snapshot();
    }

    /** @return array<string,mixed> */
    private static function decode(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array{base:list<string>,tablet:list<string>,desktop:list<string>} $vars
     * @param array<string,int> $value
     */
    private static function addResponsive(array &$vars, string $name, array $value, string $unit): void
    {
        // 平板/手机为空时继承上一档；只在该断点值与更宽断点不同时输出，避免冗余覆盖。
        $desktop = $value['d'] ?? null;
        $tablet = $value['t'] ?? $desktop;
        $mobile = $value['m'] ?? $tablet;
        if ($mobile !== null) {
            $vars['base'][] = $name . ':' . $mobile . $unit;
        }
        if ($tablet !== null && $tablet !== $mobile) {
            $vars['tablet'][] = $name . ':' . $tablet . $unit;
        }
        if ($desktop !== null && $desktop !== $tablet) {
            $vars['desktop'][] = $name . ':' . $desktop . $unit;
        }
    }

    /** @return array<string,int> */
    private static function responsive(mixed $value, int $min, int $max): array
    {
        if (!is_array($value)) {
            $value = ['d' => $value];
        }
        $out = [];
        foreach (['d', 't', 'm'] as $device) {
            $number = self::integer($value[$device] ?? null, $min, $max);
            if ($number !== null) {
                $out[$device] = $number;
            }
        }
        return $out;
    }

    /**
     * Scope partial overrides so wider screens keep their original defaults, not an unset variable.
     * @param array{base:list<string>,tablet:list<string>,desktop:list<string>} $vars
     * @param list<string> $rules
     * @param array<string,int> $value
     */
    private static function responsiveDeclaration(array &$vars, array &$rules, string $selector, string $property, string $name, array $value): string
    {
        if (isset($value['d'])) {
            self::addResponsive($vars, $name, $value, 'px');
            return $property . ':var(' . $name . ');';
        }
        foreach (['t' => self::DESKTOP_MIN, 'm' => self::TABLET_MIN] as $device => $limit) {
            if (isset($value[$device])) {
                $rules[] = '@media not all and (min-width:' . $limit . 'px){' . $selector . '{'
                    . $name . ':' . $value[$device] . 'px;' . $property . ':var(' . $name . ');}}';
            }
        }
        return '';
    }

    /** @return array<string,mixed> 单个变体预设：全空返回 []，未设字段不输出声明 */
    private static function normalizeVariant(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $state = static function (mixed $source): ?array {
            $source = is_array($source) ? $source : [];
            $item = array_filter([
                'color' => self::token($source['color'] ?? null),
                'bg' => self::token($source['bg'] ?? null),
                'border_color' => self::token($source['border_color'] ?? null),
            ], static fn(mixed $value): bool => $value !== null);
            return $item === [] ? null : $item;
        };
        return array_filter([
            'color' => self::token($raw['color'] ?? null),
            'bg' => self::token($raw['bg'] ?? null),
            'border_color' => self::token($raw['border_color'] ?? null),
            'hover' => $state($raw['hover'] ?? null),
            'focus_color' => self::token($raw['focus_color'] ?? null),
            'disabled' => $state($raw['disabled'] ?? null),
        ], static fn(mixed $value): bool => $value !== null);
    }

    /** @param array<string,mixed> $state @return string */
    private static function variantDeclarations(array $state): string
    {
        $css = '';
        foreach (['color' => 'color', 'bg' => 'background-color', 'border_color' => 'border-color'] as $key => $property) {
            if (isset($state[$key])) {
                $css .= $property . ':var(--yk-color-' . $state[$key] . ');';
            }
        }
        return $css;
    }

    private static function integer(mixed $value, int $min, int $max): ?int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/^\d{1,4}$/D', trim($value)) === 1) {
            $number = (int) trim($value);
        } else {
            return null;
        }
        return $number >= $min && $number <= $max ? $number : null;
    }

    private static function decimal(mixed $value, float $min, float $max): ?string
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && preg_match('/^\d(?:\.\d)?$/D', trim($value)) === 1)) {
            return null;
        }
        $number = round((float) $value, 1);
        return $number >= $min && $number <= $max ? number_format($number, 1, '.', '') : null;
    }

    private static function token(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';
        return preg_match(self::TOKEN_PATTERN, $value) === 1 ? $value : null;
    }
}
