<?php
/** Blox site-wide typography, button and layout theme (E04): normalize, compile, draft and publish. */

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

        foreach ($theme['typography'] as $role => $item) {
            $prefix = '--yk-type-' . $role;
            $selector = in_array($role, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true)
                ? $role . '.yk-type-' . $role
                : '.yk-type-' . $role;
            $declarations = '';
            if (isset($item['size'])) {
                self::addResponsive($vars, $prefix . '-size', $item['size'], 'px');
                $declarations .= 'font-size:var(' . $prefix . '-size);';
            }
            if (isset($item['family'])) {
                $declarations .= 'font-family:' . self::FAMILIES[$item['family']] . ';';
            }
            if (isset($item['weight'])) {
                $declarations .= 'font-weight:' . $item['weight'] . ';';
            }
            if (isset($item['line_height'])) {
                $declarations .= 'line-height:' . $item['line_height'] . ';';
            }
            if (isset($item['color'])) {
                $declarations .= 'color:var(--yk-color-' . $item['color'] . ');';
            }
            $rules[] = $selector . '{' . $declarations . '}';
        }

        $button = '';
        if (isset($theme['buttons']['size'])) {
            self::addResponsive($vars, '--yk-btn-size', $theme['buttons']['size'], 'px');
            $button .= 'font-size:var(--yk-btn-size);';
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
            self::addResponsive($vars, '--yk-layout-gap', $theme['layout']['container_gap'], 'px');
            $rules[] = 'div.yk-gap-theme{gap:var(--yk-layout-gap);}';
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

    /** 已发布主题（前台读取，只读设置缓存；同一请求内元素渲染共用一次解析）。 */
    public static function published(): array
    {
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

    public static function hasTypography(string $role): bool
    {
        return isset(self::published()['typography'][$role]);
    }

    public static function hasButtons(): bool
    {
        return self::published()['buttons'] !== [];
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
        $publishedState = self::normalize($published['state'] ?? []);
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
        self::resetCache();
        return self::snapshot();
    }

    /** @return array<string,mixed> */
    private static function decode(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array{base:list<string>,tablet:list<string>,desktop:list<string>} $vars @param array<string,int> $value */
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
