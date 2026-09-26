<?php
declare(strict_types=1);

/**
 * 区块边界预设（V2.0.1 A）：Section 顶/底的弧线、波浪、斜切装饰面。
 *
 * 为什么用内联 SVG 而不是 clip-path：边界要跨整幅宽度且高度可调，
 * SVG + preserveAspectRatio="none" 在目标浏览器行为一致；clip-path: path()
 * 在 Safari 上支持不齐。SVG 内联输出，不引外部资源、不走 CDN。
 *
 * 安全口径：形状是枚举、高度是限幅整数、颜色过 AbstractElement::cssColor()，
 * 不接受作者输入的 path、clip-path 字符串或 URL。
 *
 * 兼容口径：所有键都是新增可选键。一个都不设时 html() 返回空串、
 * isEnabled() 为 false，Section 渲染路径与 2.0.0 逐字节一致。
 */
final class BloxSectionDivider
{
    /** 形状枚举。'' = 不启用。 */
    public const SHAPES = ['arc', 'wave', 'slant'];

    public const EDGES = ['top', 'bottom'];

    public const HEIGHT_MIN = 24;
    public const HEIGHT_MAX = 120;
    public const HEIGHT_STEP = 4;
    public const HEIGHT_DEFAULT = 48;

    /** 手机端高度允许 0，表示该断点不显示这条装饰。 */
    public const HEIGHT_M_MIN = 0;

    public const STYLESHEET = '/assets/css/blox-section-divider.css';

    /**
     * 固定内置路径，统一 viewBox 0 0 1200 100，形状占据下半部分。
     * 顶边通过 CSS scaleY(-1) 翻转复用同一条路径，避免维护两套。
     */
    private const PATHS = [
        // 柔和弧线：两端贴边、中间隆起的一段三次贝塞尔
        'arc' => 'M0,100 C300,4 900,4 1200,100 Z',
        // 浅波浪：两个半周期，振幅刻意压低，避免吃掉正文空间
        'wave' => 'M0,64 C200,104 400,24 600,64 C800,104 1000,24 1200,64 L1200,100 L0,100 Z',
        // 斜切：一条直线切角
        'slant' => 'M0,100 L1200,16 L1200,100 Z',
    ];

    /** 相邻区块拿不到纯色背景时的兜底：页面内容底色。 */
    public const FALLBACK_COLOR = 'var(--yk-content-bg,#ffffff)';

    /**
     * 渲染期占位符：颜色留空表示"跟随相邻区块"，但渲染器是顺序拼接的，
     * 拼到第 N 块时还不知道第 N+1 块的背景色。于是先写占位符、
     * 全部区块拼完后再回填（见 resolveNeighborColors）。
     * 这样既不用把渲染主循环拆成两趟，也不必复制"隐藏/条件/块库"三处跳过逻辑。
     */
    private const PLACEHOLDER_PATTERN = '/\{\{YK_SD:(PREV|NEXT):(\d+)\}\}/';

    /**
     * 形状路径表。编辑器画布的形状预览直接取它，避免 PHP 与 JS 各存一份而走形。
     *
     * @psalm-api 由 admin/blox_editor.php 内联进编辑器 JS（Psalm 数不到那处调用）。
     * @return array<string,string>
     */
    public static function paths(): array
    {
        return self::PATHS;
    }

    /**
     * @psalm-api 契约表与单测据此核对存储键，不在渲染路径上。
     * @return list<string> 本特性用到的全部 Section 设置键
     */
    public static function settingKeys(): array
    {
        $keys = [];
        foreach (self::EDGES as $edge) {
            foreach (['', '_flip', '_height', '_height_m', '_color'] as $suffix) {
                $keys[] = 'divider_' . $edge . $suffix;
            }
        }
        return $keys;
    }

    /** 作者是否给这个 Section 开了任一条边界。 */
    public static function isEnabled(array $settings): bool
    {
        foreach (self::EDGES as $edge) {
            if (in_array((string) ($settings['divider_' . $edge] ?? ''), self::SHAPES, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 归一化：导入与编辑器保存共用同一入口（BloxDocumentPipeline 调用）。
     * 只清洗已存在的键——没设过的键不要凭空补默认值，否则旧文档会被写脏。
     */
    public static function normalizeSettings(array $settings): array
    {
        foreach (self::EDGES as $edge) {
            $shapeKey = 'divider_' . $edge;
            if (array_key_exists($shapeKey, $settings)) {
                $shape = is_string($settings[$shapeKey]) ? $settings[$shapeKey] : '';
                $settings[$shapeKey] = in_array($shape, self::SHAPES, true) ? $shape : '';
            }

            $flipKey = $shapeKey . '_flip';
            if (array_key_exists($flipKey, $settings)) {
                $settings[$flipKey] = in_array($settings[$flipKey], [true, 1, '1'], true);
            }

            $heightKey = $shapeKey . '_height';
            if (array_key_exists($heightKey, $settings)) {
                $settings[$heightKey] = self::clampHeight($settings[$heightKey], self::HEIGHT_MIN);
            }

            $heightMobileKey = $shapeKey . '_height_m';
            if (array_key_exists($heightMobileKey, $settings)) {
                $settings[$heightMobileKey] = self::clampHeight($settings[$heightMobileKey], self::HEIGHT_M_MIN);
            }

            $colorKey = $shapeKey . '_color';
            if (array_key_exists($colorKey, $settings)) {
                // '' 是合法值，含义是"跟随相邻区块背景"，不要当成非法值丢掉
                $raw = is_string($settings[$colorKey]) ? trim($settings[$colorKey]) : '';
                $settings[$colorKey] = $raw === '' ? '' : (AbstractElement::cssColor($raw) ?? '');
            }
        }
        return $settings;
    }

    private static function clampHeight(mixed $value, int $min): int
    {
        $px = (int) $value;
        if ($px < $min) {
            return $min;
        }
        return min(self::HEIGHT_MAX, $px);
    }

    /**
     * 生成一条边界的 HTML。未启用返回空串。
     *
     * @param int $ordinal 该 Section 在实际输出序列中的序号，用于颜色占位符回填
     */
    public static function html(array $settings, string $edge, int $ordinal): string
    {
        if (!in_array($edge, self::EDGES, true)) {
            return '';
        }
        $shape = (string) ($settings['divider_' . $edge] ?? '');
        if (!in_array($shape, self::SHAPES, true)) {
            return '';
        }

        $height = self::clampHeight($settings['divider_' . $edge . '_height'] ?? self::HEIGHT_DEFAULT, self::HEIGHT_MIN);
        $style = '--yk-sd-h:' . $height . 'px;';

        $mobileOff = false;
        $heightMobileKey = 'divider_' . $edge . '_height_m';
        if (array_key_exists($heightMobileKey, $settings)) {
            $mobileHeight = self::clampHeight($settings[$heightMobileKey], self::HEIGHT_M_MIN);
            $mobileOff = $mobileHeight === 0;
            $style .= '--yk-sd-hm:' . $mobileHeight . 'px;';
        }

        $color = (string) ($settings['divider_' . $edge . '_color'] ?? '');
        $color = $color === '' ? '' : (AbstractElement::cssColor($color) ?? '');
        if ($color === '') {
            // 顶边接上一块、底边接下一块
            $color = '{{YK_SD:' . ($edge === 'top' ? 'PREV' : 'NEXT') . ':' . $ordinal . '}}';
        }

        $classes = 'yk-sec-divider yk-sec-divider--' . $edge;
        if (!empty($settings['divider_' . $edge . '_flip'])) {
            $classes .= ' yk-sec-divider--flip';
        }

        BloxAssetCollector::addStyle(self::STYLESHEET);

        return '<div class="' . $classes . '" aria-hidden="true"'
            . ($mobileOff ? ' data-yk-sd-mobile-off' : '')
            . ' style="' . htmlspecialchars($style, ENT_QUOTES) . '">'
            . '<svg viewBox="0 0 1200 100" preserveAspectRatio="none" focusable="false" aria-hidden="true">'
            . '<path d="' . self::PATHS[$shape] . '" fill="' . htmlspecialchars($color, ENT_QUOTES) . '"></path>'
            . '</svg></div>';
    }

    /**
     * 回填"跟随相邻区块"的颜色。
     *
     * @param string             $html         已拼好的全部区块 HTML
     * @param array<int,?string> $sectionColors 输出序号 => 该区块纯色背景（无纯色背景为 null）
     */
    public static function resolveNeighborColors(string $html, array $sectionColors): string
    {
        if (!str_contains($html, '{{YK_SD:')) {
            return $html;
        }
        return (string) preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static function (array $m) use ($sectionColors): string {
                $ordinal = (int) $m[2];
                $neighbor = $m[1] === 'PREV' ? $ordinal - 1 : $ordinal + 1;
                $color = $sectionColors[$neighbor] ?? null;
                return htmlspecialchars($color ?? self::FALLBACK_COLOR, ENT_QUOTES);
            },
            $html
        );
    }

    /**
     * 编辑器控件用的契约表（验收门槛 1）。
     *
     * @psalm-api 交付文档与单测消费，不在渲染路径上。
     * @return list<array<string,mixed>>
     */
    public static function propertyContract(): array
    {
        $fields = [];
        foreach (self::EDGES as $edge) {
            $fields[] = ['key' => 'divider_' . $edge, 'type' => 'enum', 'default' => '',
                'options' => array_merge([''], self::SHAPES), 'responsive' => false];
            $fields[] = ['key' => 'divider_' . $edge . '_flip', 'type' => 'bool', 'default' => false,
                'responsive' => false];
            $fields[] = ['key' => 'divider_' . $edge . '_height', 'type' => 'px', 'default' => self::HEIGHT_DEFAULT,
                'min' => self::HEIGHT_MIN, 'max' => self::HEIGHT_MAX, 'step' => self::HEIGHT_STEP, 'responsive' => false];
            $fields[] = ['key' => 'divider_' . $edge . '_height_m', 'type' => 'px', 'default' => self::HEIGHT_DEFAULT,
                'min' => self::HEIGHT_M_MIN, 'max' => self::HEIGHT_MAX, 'step' => self::HEIGHT_STEP, 'responsive' => true];
            $fields[] = ['key' => 'divider_' . $edge . '_color', 'type' => 'color', 'default' => '',
                'note' => 'empty = follow neighbour section background', 'responsive' => false];
        }
        return $fields;
    }
}
