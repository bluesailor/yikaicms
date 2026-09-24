<?php
/**
 * Blox 全局样式类（Global Classes，v1.23 设计系统 Phase 1）。
 *
 * 与 BloxDesignSystem 的样式预设分工：预设是「一键外观快照」（内联 !important），
 * 全局类是「可复用的 CSS 类」——元素 data._classes 存 ID 数组，渲染期映射当前
 * 类名（改名零迁移），规则输出为真正的样式表（前缀 yk-c- 避免与 Tailwind 工具类撞名）。
 *
 * 授权语义与 style_presets 同族：渲染永远免费（已存在的类照常输出），
 * 类的创建/修改/回收等全部 class_* 变更动作要求作者端授权。
 *
 * 用量反向索引 blox_class_refs 在文档保存时维护（replaceDocumentRefs），
 * 管理器的使用统计与失效链只吃索引，禁止正则扫全站 JSON。
 */

declare(strict_types=1);

final class BloxGlobalClasses
{
    public const ID_PATTERN = '/^gc_[a-f0-9]{12}$/';
    public const NAME_PATTERN = '/^[a-z][a-z0-9-]{1,47}$/';
    public const CLASS_PREFIX = 'yk-c-';
    public const MAX_CLASSES = 200;
    public const MAX_PER_ELEMENT = 8;
    /** 回收站保留天数：过期在目录加载时惰性清理。 */
    public const TRASH_RETENTION_DAYS = 30;
    /** 共享样式表相对站点根的位置（uploads 可 HTTP 访问、禁 PHP 执行）。 */
    public const STYLESHEET_RELATIVE = 'uploads/blox/css/classes.css';

    private const RADIUS_MAP = [
        'none' => '0',
        'sm' => '0.25rem',
        'md' => '0.5rem',
        'lg' => '0.75rem',
        'full' => '9999px',
    ];
    /**
     * 响应式长度设置：key => [css 属性, min, max, 单位]。值为整数，或 {d,t,m,w} 档位表
     * （继承 t←d、m←t、w←d，与 BloxResponsiveValue 同义）。顺序即输出顺序：
     * 简写 padding 必须排在四边之前，四边才能覆盖简写。编辑器表单按 propertyContract() 生成。
     */
    private const RESPONSIVE_SETTINGS = [
        'padding_px' => ['padding', 0, 160, 'px'],
        'padding_top_px' => ['padding-top', 0, 400, 'px'],
        'padding_right_px' => ['padding-right', 0, 400, 'px'],
        'padding_bottom_px' => ['padding-bottom', 0, 400, 'px'],
        'padding_left_px' => ['padding-left', 0, 400, 'px'],
        'margin_top_px' => ['margin-top', -400, 400, 'px'],
        'margin_right_px' => ['margin-right', -400, 400, 'px'],
        'margin_bottom_px' => ['margin-bottom', -400, 400, 'px'],
        'margin_left_px' => ['margin-left', -400, 400, 'px'],
        'font_size_px' => ['font-size', 8, 160, 'px'],
        'gap_px' => ['gap', 0, 160, 'px'],
        'width_pct' => ['width', 1, 100, '%'],
        'max_width_px' => ['max-width', 40, 2400, 'px'],
    ];
    /** 简写属性被某条规则输出时，同规则内要重申的分边属性（否则简写会吃掉分边值）。 */
    private const SHORTHAND_SIDES = [
        'padding' => ['padding_top_px', 'padding_right_px', 'padding_bottom_px', 'padding_left_px'],
    ];
    /** 枚举设置（不分档）：key => [css 属性, 合法值]。 */
    private const ENUM_SETTINGS = [
        'font_weight' => ['font-weight', ['300', '400', '500', '600', '700', '800']],
        'text_align' => ['text-align', ['left', 'center', 'right', 'justify']],
        'justify_content' => ['justify-content', ['flex-start', 'center', 'flex-end', 'space-between', 'space-around', 'space-evenly']],
        'align_items' => ['align-items', ['flex-start', 'center', 'flex-end', 'stretch', 'baseline']],
    ];
    private const LINE_HEIGHT_RANGE = [0.8, 3.0];
    private const BORDER_WIDTH_RANGE = [0, 20];
    private const RADIUS_PX_RANGE = [0, 999];
    /**
     * 类选择器后缀：把特异性从 0,1,0 抬到 0,1,1，与主题的元素限定默认值（h2.yk-type-h2、
     * [data-blox-text-tone] :is(h1…)）打平后靠加载顺序胜出（类样式表在主题与设计 token 之后）；
     * 仍低于元素本地值（.yk-r-* 为 0,2,0，内联/预设为 style 属性）。只作用于挂了类的元素。
     */
    private const SELECTOR_SUFFIX = ':not(yk-none)';
    /**
     * 类的交互状态（V2.0.0 提前纳入）：settings.states.{state} 只存下面这些不分档的键。
     * 聚焦用 :focus-visible（鼠标点击不触发），并用 :has() 让标题这类「类挂在外层、链接在里面」
     * 的元素在内部链接获得键盘焦点时同样生效；不支持 :has 的浏览器由 :is() 的宽容解析忽略该分支。
     * 状态规则特异性 0,2,1：高于类的基础规则与元素的档位预设，仍低于内联的本地值与样式预设。
     */
    public const STATES = [
        'hover' => ':hover',
        'focus' => ':is(:focus-visible,:has(:focus-visible))',
    ];
    public const STATE_KEYS = ['text_color', 'bg_color', 'border_color', 'border_width_px', 'radius_px', 'font_weight'];
    private const TRANSITION_RANGE = [0, 2000];
    /** 状态能改变、且值得过渡的属性（font-weight 是离散值，不列）。 */
    private const TRANSITION_PROPERTIES = 'color,background-color,border-color,border-width,border-radius';
    /** 颜色类设置：key => css 属性。值为 hex 或站点色 token 引用。 */
    private const COLOR_SETTINGS = [
        'text_color' => 'color',
        'bg_color' => 'background-color',
        'border_color' => 'border-color',
    ];
    private const TOKEN_REF_PATTERN = '/^var\(--yk-color-[a-z][a-z0-9_-]{0,47}\)$/';

    /** @var array<string,array<string,mixed>>|null 请求内目录缓存：class_id => row（settings 已解码） */
    private static ?array $catalog = null;

    /** @psalm-suppress PossiblyUnusedMethod 测试专用（单测进程共享请求级缓存时复位） */
    public static function resetForTests(): void
    {
        self::$catalog = null;
    }

    public static function available(): bool
    {
        // 不做进程级缓存：单测进程共享 PDO 且逐用例重建表，缓存会跨用例撒谎；
        // 生产侧 catalog() 有请求级缓存，这里最多一次表存在性查询。
        return db()->tableExists('blox_global_classes');
    }

    /** @return array<string,array<string,mixed>> 活跃类目录：class_id => {class_id,name,category,settings,modified} */
    public static function catalog(): array
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }
        if (!self::available()) {
            return self::$catalog = [];
        }
        self::purgeExpiredTrash();
        $catalog = [];
        foreach (bloxGlobalClassModel()->allByStatus(BloxGlobalClassModel::STATUS_ACTIVE) as $row) {
            $classId = (string) ($row['class_id'] ?? '');
            $name = (string) ($row['name'] ?? '');
            if (!preg_match(self::ID_PATTERN, $classId) || !preg_match(self::NAME_PATTERN, $name)) {
                continue;
            }
            $settings = json_decode((string) ($row['settings'] ?? ''), true);
            $catalog[$classId] = [
                'class_id' => $classId,
                'name' => $name,
                'category' => (string) ($row['category'] ?? ''),
                'settings' => self::normalizeSettings(is_array($settings) ? $settings : []),
                'modified' => (int) ($row['modified'] ?? 0),
                'revision' => (int) ($row['revision'] ?? 0),
            ];
        }
        return self::$catalog = $catalog;
    }

    // ── 元素侧：data._classes ────────────────────────────────────────

    /**
     * 归一元素 data 上的 _classes：只留合法 ID、去重、限量；空则删除键。
     * 不校验 ID 是否存在——类被删后引用成为无害悬挂（渲染期跳过），恢复即复活。
     */
    public static function normalizeElementData(array $data): array
    {
        if (!array_key_exists('_classes', $data)) {
            return $data;
        }
        $raw = $data['_classes'];
        $ids = [];
        foreach (is_array($raw) ? $raw : [] as $candidate) {
            if (is_string($candidate) && preg_match(self::ID_PATTERN, $candidate) && !in_array($candidate, $ids, true)) {
                $ids[] = $candidate;
                if (count($ids) >= self::MAX_PER_ELEMENT) {
                    break;
                }
            }
        }
        if ($ids === []) {
            unset($data['_classes']);
        } else {
            $data['_classes'] = $ids;
        }
        return $data;
    }

    /** 渲染期：元素引用的活跃类 → 类名串（前导空格；无有效引用返回空串）。 */
    public static function classAttributeFor(array $data): string
    {
        $ids = $data['_classes'] ?? null;
        if (!is_array($ids) || $ids === []) {
            return '';
        }
        $catalog = self::catalog();
        $names = [];
        foreach ($ids as $id) {
            if (is_string($id) && isset($catalog[$id])) {
                $names[] = self::CLASS_PREFIX . $catalog[$id]['name'];
            }
        }
        return $names === [] ? '' : ' ' . implode(' ', $names);
    }

    /** 文档遍历：sections 树中引用到的 class_id => 引用次数（喂反向索引）。 */
    public static function collectReferences(array $sections): array
    {
        $counts = [];
        $walkElement = static function (array $element) use (&$walkElement, &$counts): void {
            $data = is_array($element['data'] ?? null) ? $element['data'] : [];
            foreach (is_array($data['_classes'] ?? null) ? $data['_classes'] : [] as $id) {
                if (is_string($id) && preg_match(self::ID_PATTERN, $id)) {
                    $counts[$id] = ($counts[$id] ?? 0) + 1;
                }
            }
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
        return $counts;
    }

    // ── CSS 输出 ────────────────────────────────────────────────────

    /** 活跃类的完整样式表（共享文件内容；「只输出被引用类的每页文件」在 CSS 分层批次落地）。 */
    public static function stylesheet(): string
    {
        $rules = [];
        foreach (self::cascadeOrder() as $class) {
            $css = self::classRules($class['name'], $class['settings']);
            if ($css !== '') {
                $rules[] = $css;
            }
        }
        return implode("\n", $rules);
    }

    /**
     * 编辑器预览用：把若干类的设置替换为草稿后的整张样式表（不落库）。
     * 草稿同样过白名单；不在目录里的 class_id 忽略，无草稿时即当前样式表。
     *
     * @param array<mixed,mixed> $drafts class_id => settings
     * @param array<mixed,mixed> $forcedStates class_id => 正在编辑的状态（画布里强制显示该状态）
     */
    public static function previewStylesheet(array $drafts, array $forcedStates = []): string
    {
        $rules = [];
        foreach (self::cascadeOrder() as $id => $class) {
            $settings = is_array($drafts[$id] ?? null) ? self::normalizeSettings($drafts[$id]) : $class['settings'];
            $forced = $forcedStates[$id] ?? '';
            $css = self::classRules($class['name'], $settings, is_string($forced) && isset(self::STATES[$forced]) ? $forced : '');
            if ($css !== '') {
                $rules[] = $css;
            }
        }
        return implode("\n", $rules);
    }

    /**
     * 首批类属性契约（V2.0.0）：编辑器表单按它生成，字段、范围与输出规则同源。
     * type: color | px | pct | number | enum；responsive=true 的字段可分 d/t/m/w 档；
     * applies: all | flex（仅对 display:flex/grid 的容器生效）。
     *
     * @return list<array<string,mixed>>
     */
    public static function propertyContract(): array
    {
        $fields = [];
        /** @var array<string,list<string>> $groups */
        $groups = [
            'spacing' => ['margin_top_px', 'margin_right_px', 'margin_bottom_px', 'margin_left_px',
                'padding_px', 'padding_top_px', 'padding_right_px', 'padding_bottom_px', 'padding_left_px'],
            'typography' => ['text_color', 'font_size_px', 'font_weight', 'line_height', 'text_align'],
            'background' => ['bg_color'],
            'border' => ['border_color', 'border_width_px', 'radius_px'],
            'layout' => ['width_pct', 'max_width_px', 'gap_px', 'justify_content', 'align_items'],
            // 状态过渡时长存在基础设置里，编辑器在状态页签里展示
            'states' => ['transition_ms'],
        ];
        foreach ($groups as $group => $keys) {
            foreach ($keys as $key) {
                $field = ['key' => $key, 'group' => $group, 'responsive' => false, 'applies' => 'all'];
                if (isset(self::COLOR_SETTINGS[$key])) {
                    $field += ['type' => 'color', 'css' => self::COLOR_SETTINGS[$key]];
                } elseif (isset(self::RESPONSIVE_SETTINGS[$key])) {
                    [$css, $min, $max, $unit] = self::RESPONSIVE_SETTINGS[$key];
                    $field = ['type' => $unit === '%' ? 'pct' : 'px', 'css' => $css, 'min' => $min, 'max' => $max,
                        'step' => 1, 'unit' => $unit, 'responsive' => true] + $field;
                } elseif (isset(self::ENUM_SETTINGS[$key])) {
                    [$css, $options] = self::ENUM_SETTINGS[$key];
                    $field += ['type' => 'enum', 'css' => $css, 'options' => $options];
                } elseif ($key === 'line_height') {
                    $field += ['type' => 'number', 'css' => 'line-height', 'min' => self::LINE_HEIGHT_RANGE[0],
                        'max' => self::LINE_HEIGHT_RANGE[1], 'step' => 0.05, 'unit' => ''];
                } elseif ($key === 'border_width_px') {
                    $field += ['type' => 'px', 'css' => 'border-width', 'min' => self::BORDER_WIDTH_RANGE[0],
                        'max' => self::BORDER_WIDTH_RANGE[1], 'step' => 1, 'unit' => 'px'];
                } elseif ($key === 'transition_ms') {
                    $field += ['type' => 'number', 'css' => 'transition-duration', 'min' => self::TRANSITION_RANGE[0],
                        'max' => self::TRANSITION_RANGE[1], 'step' => 50, 'unit' => 'ms'];
                } elseif ($key === 'radius_px') {
                    $field += ['type' => 'px', 'css' => 'border-radius', 'min' => self::RADIUS_PX_RANGE[0],
                        'max' => self::RADIUS_PX_RANGE[1], 'step' => 1, 'unit' => 'px'];
                }
                if (in_array($key, ['gap_px', 'justify_content', 'align_items'], true)) {
                    $field['applies'] = 'flex';
                }
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * 样式表输出顺序 = 多类冲突的胜负顺序：按类名字节序升序，后者胜出。
     * 显式排序而不依赖数据库排序规则（MySQL 与 SQLite 的排序规则不同）；编辑器提示按同一规则。
     *
     * @return array<string,array<string,mixed>>
     */
    private static function cascadeOrder(): array
    {
        $catalog = self::catalog();
        uasort($catalog, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        return $catalog;
    }

    public static function styleTag(): string
    {
        $css = self::stylesheet();
        return $css === '' ? '' : '<style id="yk-blox-classes">' . $css . '</style>';
    }

    /**
     * 共享样式表文件（uploads 是「可 HTTP 访问 + 禁 PHP 执行」的生成目录先例；
     * storage 在 .htaccess/nginx/站点健康三层被封禁，不可服务）。
     * 变更后文件被删除，下次访问惰性重建；URL 带 mtime 版本戳。
     */
    public static function stylesheetFilePath(): string
    {
        return ROOT_PATH . '/' . self::STYLESHEET_RELATIVE;
    }

    /** 头部输出：优先 <link> 共享文件；目录不可写等异常回退内联 <style>（fail-open）。 */
    public static function headOutput(): string
    {
        $css = self::stylesheet();
        if ($css === '') {
            return '';
        }
        $path = self::stylesheetFilePath();
        if (!is_file($path)) {
            $directory = dirname($path);
            if (!is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
            $header = "/* YikaiCMS Blox global classes - generated, do not edit. */\n";
            if (@file_put_contents($path, $header . $css . "\n", LOCK_EX) === false) {
                return '<style id="yk-blox-classes">' . $css . '</style>';
            }
        }
        $version = (int) @filemtime($path);
        return '<link rel="stylesheet" id="yk-blox-classes" href="/uploads/blox/css/classes.css?v=' . $version . '">';
    }

    /**
     * 类表被外部整体替换后调用（整站模板导入/恢复导入前）：丢请求级目录缓存并删共享样式表。
     * 否则前台继续按旧站的 classes.css（同一个 mtime 版本戳）渲染，直到有人再改一次类。
     */
    public static function forgetAfterBulkReplace(?string $siteRoot = null): void
    {
        self::$catalog = null;
        $path = $siteRoot === null ? self::stylesheetFilePath() : rtrim($siteRoot, '/\\') . '/' . self::STYLESHEET_RELATIVE;
        if (is_file($path)) {
            @unlink($path);
        }
        if (function_exists('do_action')) {
            do_action('data_changed', 'blox_global_classes', 0);
        }
    }

    /** 变更后的失效：删文件（惰性重建）+ 走站点统一的 data_changed 失效链（页面 HTML 缓存联动）。 */
    public static function invalidateStylesheet(): void
    {
        $path = self::stylesheetFilePath();
        if (is_file($path)) {
            @unlink($path);
        }
        if (function_exists('do_action')) {
            do_action('data_changed', 'blox_global_classes', 0);
        }
    }

    public static function bootstrap(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        // 排在设计 token（优先级 4）之后，先于主题自定义输出
        add_action('ik_head', static function (): void {
            echo self::headOutput();
        }, 5);
    }

    /**
     * 单个类的 CSS 规则（无内容返回空串）。
     *
     * 设了桌面值的响应式设置走移动优先：基档=手机，t/d/w 只在与下一档不同时出 min-width 媒体查询。
     * 没有桌面值、只设了平板/手机/宽屏的走区间规则（排在最后），只作用于该档，不向上泄漏。
     * 多个类在同一属性上冲突时按样式表顺序（目录按类名升序）后者胜出，与挂载顺序无关。
     */
    public static function classRules(string $name, array $settings, string $forcedState = ''): string
    {
        $selector = '.' . self::CLASS_PREFIX . $name . self::SELECTOR_SUFFIX;
        $wide = BloxResponsiveValue::wideEnabled();
        $base = self::scalarDeclarations($settings);
        $lineHeight = self::lineHeightOrNull($settings['line_height'] ?? null);
        if ($lineHeight !== null) {
            $base['line-height'] = $lineHeight;
        }
        $transition = self::intInRange($settings['transition_ms'] ?? null, self::TRANSITION_RANGE);
        if ($transition !== null && $transition > 0) {
            $base['transition-property'] = self::TRANSITION_PROPERTIES;
            $base['transition-duration'] = $transition . 'ms';
        }

        /** @var array<string,array{m:?int,t:?int,d:?int,w:?int}> $resolved */
        $resolved = [];
        foreach (array_keys(self::RESPONSIVE_SETTINGS) as $key) {
            $tiers = self::resolveTiers($settings[$key] ?? null, $key, $wide);
            if ($tiers !== null) {
                $resolved[$key] = $tiers;
            }
        }

        $media = ['t' => [], 'd' => [], 'w' => []];
        $ranges = ['m' => [], 't' => [], 'w' => []];
        foreach ($resolved as $key => $tiers) {
            [$property, , , $unit] = self::RESPONSIVE_SETTINGS[$key];
            if ($tiers['d'] !== null) {
                $base[$property] = $tiers['m'] . $unit;
                if ($tiers['t'] !== $tiers['m']) {
                    $media['t'][$property] = $tiers['t'] . $unit;
                }
                if ($tiers['d'] !== $tiers['t']) {
                    $media['d'][$property] = $tiers['d'] . $unit;
                }
                if ($wide && $tiers['w'] !== $tiers['d']) {
                    $media['w'][$property] = $tiers['w'] . $unit;
                }
                continue;
            }
            foreach (['m', 't', 'w'] as $tier) {
                if ($tiers[$tier] !== null) {
                    $ranges[$tier][$property] = $tiers[$tier] . $unit;
                }
            }
        }

        $css = '';
        $blocks = [
            ['', 'm', $base],
            ['@media (min-width:768px)', 't', $media['t']],
            ['@media (min-width:1024px)', 'd', $media['d']],
            ['@media (min-width:1440px)', 'w', $media['w']],
            ['@media not all and (min-width:768px)', 'm', $ranges['m']],
            ['@media (min-width:768px) and (max-width:1023.98px)', 't', $ranges['t']],
            ['@media (min-width:1440px)', 'w', $ranges['w']],
        ];
        foreach ($blocks as [$query, $tier, $declarations]) {
            if ($declarations === []) {
                continue;
            }
            $declarations = self::restateSides($declarations, $resolved, $tier);
            $body = [];
            foreach ($declarations as $property => $value) {
                $body[] = $property . ':' . $value;
            }
            $rule = $selector . '{' . implode(';', $body) . '}';
            $css .= $query === '' ? $rule : $query . '{' . $rule . '}';
        }

        $states = is_array($settings['states'] ?? null) ? $settings['states'] : [];
        foreach (self::STATES as $state => $pseudo) {
            $declarations = is_array($states[$state] ?? null) ? self::scalarDeclarations($states[$state], true) : [];
            if ($declarations === []) {
                continue;
            }
            $body = implode(';', array_map(
                static fn(string $property, string $value): string => $property . ':' . $value,
                array_keys($declarations),
                $declarations
            ));
            $css .= $selector . $pseudo . '{' . $body . '}';
            // 编辑器正在编辑该状态：预览里对所有挂了此类的元素强制显示状态样式
            if ($forcedState === $state) {
                $css .= $selector . $selector . '{' . $body . '}';
            }
        }
        return $css;
    }

    /**
     * 不分档的声明：颜色、圆角、边框、枚举（基础规则与各状态规则共用）。
     * 状态里只改边框颜色时只出 border-color，沿用基础规则的线型与宽度（否则悬停会把 2px 边框改回 1px）。
     *
     * @return array<string,string> css 属性 => 值
     */
    private static function scalarDeclarations(array $settings, bool $state = false): array
    {
        $declarations = [];
        foreach (self::COLOR_SETTINGS as $key => $property) {
            $value = $settings[$key] ?? '';
            if (is_string($value) && $value !== '') {
                $declarations[$property] = $value;
            }
        }
        $radiusPx = self::intInRange($settings['radius_px'] ?? null, self::RADIUS_PX_RANGE);
        $radius = $settings['radius'] ?? '';
        if ($radiusPx !== null) {
            $declarations['border-radius'] = $radiusPx . 'px';
        } elseif (is_string($radius) && $radius !== '' && $radius !== 'none' && isset(self::RADIUS_MAP[$radius])) {
            $declarations['border-radius'] = self::RADIUS_MAP[$radius];
        }
        $borderWidth = self::intInRange($settings['border_width_px'] ?? null, self::BORDER_WIDTH_RANGE);
        if ($state ? $borderWidth !== null : (($settings['border_color'] ?? '') !== '' || $borderWidth !== null)) {
            $declarations['border-style'] = 'solid';
            $declarations['border-width'] = ($borderWidth ?? 1) . 'px';
        }
        foreach (self::ENUM_SETTINGS as $key => [$property, $allowed]) {
            $value = self::enumOrNull($settings[$key] ?? null, $allowed);
            if ($value !== null) {
                $declarations[$property] = $value;
            }
        }
        return $declarations;
    }

    /**
     * 解析一个响应式设置的四档取值（null=该档不设）。有桌面值时按 t←d、m←t、w←d 补齐；
     * 没有桌面值时只继承 m←t（宽屏不从平板继承），宽屏开关关闭时丢弃宽屏档。
     *
     * @return array{m:?int,t:?int,d:?int,w:?int}|null
     */
    private static function resolveTiers(mixed $value, string $key, bool $wide): ?array
    {
        if (!is_array($value) && !is_numeric($value)) {
            return null;
        }
        $raw = is_array($value) ? $value : ['d' => $value];
        $d = self::pxOrNull($raw['d'] ?? null, $key);
        $t = self::pxOrNull($raw['t'] ?? null, $key) ?? $d;
        $m = self::pxOrNull($raw['m'] ?? null, $key) ?? $t;
        $w = $wide ? (self::pxOrNull($raw['w'] ?? null, $key) ?? $d) : $d;
        if ($d === null && !$wide) {
            $w = null;
        }
        if ($d === null && $t === null && $m === null && $w === null) {
            return null;
        }
        return ['m' => $m, 't' => $t, 'd' => $d, 'w' => $w];
    }

    /**
     * 某条规则输出了简写（padding）时，把已设置的分边值按该档重申在简写之后，
     * 否则 `padding:24px` 会在平板档吃掉基档里的 `padding-top:40px`。
     *
     * @param array<string,string> $declarations
     * @param array<string,array{m:?int,t:?int,d:?int,w:?int}> $resolved
     * @return array<string,string>
     */
    private static function restateSides(array $declarations, array $resolved, string $tier): array
    {
        foreach (self::SHORTHAND_SIDES as $shorthand => $sideKeys) {
            if (!isset($declarations[$shorthand])) {
                continue;
            }
            foreach ($sideKeys as $sideKey) {
                $value = $resolved[$sideKey][$tier] ?? null;
                if ($value === null) {
                    continue;
                }
                [$property, , , $unit] = self::RESPONSIVE_SETTINGS[$sideKey];
                unset($declarations[$property]);
                $declarations[$property] = $value . $unit;
            }
        }
        return $declarations;
    }

    // ── 变更（class_* 动作，作者端授权） ──────────────────────────────

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> 变更后的目标类行
     */
    public static function mutate(string $action, array $input, bool $advanced): array
    {
        if (!self::available()) {
            throw new RuntimeException(__('blox_feature_disabled'));
        }
        if (!$advanced) {
            throw new RuntimeException(__('blox_class_license_required'));
        }
        $result = match ($action) {
            'class_add' => self::create($input),
            'class_update' => self::updateSettings($input),
            'class_rename' => self::rename($input),
            'class_trash' => self::setStatus($input, BloxGlobalClassModel::STATUS_TRASHED),
            'class_restore' => self::restore($input),
            default => throw new RuntimeException(__('blox_design_invalid')),
        };
        self::$catalog = null;
        self::invalidateStylesheet();
        return $result;
    }

    /** @param array<string,mixed> $input */
    private static function create(array $input): array
    {
        $name = self::assertName((string) ($input['name'] ?? ''));
        if (bloxGlobalClassModel()->findActiveByName($name) !== null) {
            throw new RuntimeException(__('blox_class_duplicate_name'));
        }
        $total = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'blox_global_classes');
        if ($total >= self::MAX_CLASSES) {
            throw new RuntimeException(__('blox_class_limit', ['max' => self::MAX_CLASSES]));
        }
        $now = time();
        $row = [
            'class_id' => 'gc_' . bin2hex(random_bytes(6)),
            'name' => $name,
            'category' => self::normalizeCategory((string) ($input['category'] ?? '')),
            'settings' => json_encode(self::normalizeSettings(is_array($input['settings'] ?? null) ? $input['settings'] : []), JSON_UNESCAPED_UNICODE),
            'status' => BloxGlobalClassModel::STATUS_ACTIVE,
            'modified' => $now,
            'revision' => 0,
            'user_id' => max(0, (int) ($input['user_id'] ?? 0)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        db()->insert('blox_global_classes', $row);
        return $row;
    }

    /**
     * 乐观并发写（外审 P1-4）：UPDATE 条件带读取时的 revision，命中 0 行即有并发写
     * 抢先（读-改-写窗口被撕掉）。秒级 modified 时间戳在同一秒内分不出先后，
     * revision 每写 +1 才能让"同秒二次写旧版本"必然冲突。
     *
     * @param array<string,mixed> $row assertRow 刚读出的行
     * @param array<string,mixed> $data 要写的列（不含 revision，这里统一 +1）
     * @return array<string,mixed>
     */
    private static function guardedUpdate(array $row, array $data): array
    {
        $expected = (int) ($row['revision'] ?? 0);
        $data['revision'] = $expected + 1;
        $affected = db()->update('blox_global_classes', $data, 'class_id = ? AND revision = ?', [(string) $row['class_id'], $expected]);
        if ($affected === 0) {
            // 区分「类已不存在」与「并发冲突」（借鉴另一条整改分支），报错文案才不会误导
            if (bloxGlobalClassModel()->findByClassId((string) $row['class_id']) === null) {
                throw new RuntimeException(__('blox_class_not_found'));
            }
            throw new RuntimeException(__('blox_design_conflict'));
        }
        return bloxGlobalClassModel()->findByClassId((string) $row['class_id']) ?? $row;
    }

    /** @param array<string,mixed> $input */
    private static function updateSettings(array $input): array
    {
        $row = self::assertRow($input);
        $settings = self::normalizeSettings(is_array($input['settings'] ?? null) ? $input['settings'] : []);
        $now = time();
        return self::guardedUpdate($row, [
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'modified' => $now,
            'user_id' => max(0, (int) ($input['user_id'] ?? 0)),
            'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $input */
    private static function rename(array $input): array
    {
        $row = self::assertRow($input);
        $name = self::assertName((string) ($input['name'] ?? ''));
        $existing = bloxGlobalClassModel()->findActiveByName($name);
        if ($existing !== null && (string) $existing['class_id'] !== (string) $row['class_id']) {
            throw new RuntimeException(__('blox_class_duplicate_name'));
        }
        $now = time();
        return self::guardedUpdate($row, [
            'name' => $name,
            'modified' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $input */
    private static function setStatus(array $input, string $status): array
    {
        $row = self::assertRow($input, false);
        $now = time();
        return self::guardedUpdate($row, [
            'status' => $status,
            'trashed_at' => $status === BloxGlobalClassModel::STATUS_TRASHED ? $now : 0,
            'modified' => $now,
            'updated_at' => $now,
        ]);
    }

    /** 恢复：同名活跃类已存在时自动加 -2/-3… 后缀（回收站语义）。 */
    private static function restore(array $input): array
    {
        $row = self::assertRow($input, false);
        $name = (string) $row['name'];
        $candidate = $name;
        $suffix = 2;
        while (($conflict = bloxGlobalClassModel()->findActiveByName($candidate)) !== null
            && (string) $conflict['class_id'] !== (string) $row['class_id']) {
            $candidate = substr($name, 0, 44) . '-' . $suffix;
            $suffix++;
            if ($suffix > 20) {
                throw new RuntimeException(__('blox_class_duplicate_name'));
            }
        }
        $now = time();
        return self::guardedUpdate($row, [
            'name' => $candidate,
            'status' => BloxGlobalClassModel::STATUS_ACTIVE,
            'trashed_at' => 0,
            'modified' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array<string,mixed> $input */
    private static function assertRow(array $input, bool $checkRevision = true): array
    {
        $classId = (string) ($input['id'] ?? '');
        if (!preg_match(self::ID_PATTERN, $classId)) {
            throw new RuntimeException(__('blox_class_not_found'));
        }
        $row = bloxGlobalClassModel()->findByClassId($classId);
        if ($row === null) {
            throw new RuntimeException(__('blox_class_not_found'));
        }
        // 乐观并发：调用方带上读取时的 revision（每写 +1），不一致即冲突（外审 P1-4）。
        // 秒级 modified 时间戳同秒分不出先后，仅作旧客户端的兼容校验保留；
        // 真正的写门在 guardedUpdate 的 CAS（UPDATE ... WHERE revision = 读取值）。
        if ($checkRevision && array_key_exists('revision', $input)
            && (int) $input['revision'] !== (int) ($row['revision'] ?? 0)) {
            throw new RuntimeException(__('blox_design_conflict'));
        }
        if ($checkRevision && array_key_exists('modified', $input)
            && (int) $input['modified'] !== (int) ($row['modified'] ?? 0)) {
            throw new RuntimeException(__('blox_design_conflict'));
        }
        return $row;
    }

    private static function assertName(string $name): string
    {
        $name = strtolower(trim($name));
        if (!preg_match(self::NAME_PATTERN, $name)) {
            throw new RuntimeException(__('blox_class_bad_name'));
        }
        return $name;
    }

    private static function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        return preg_match('/^[a-z][a-z0-9-]{0,31}$/', $category) ? $category : '';
    }

    /** 设置白名单归一：非法字段丢弃（安全边界——值最终进样式表）。 */
    public static function normalizeSettings(array $settings): array
    {
        $normalized = [];
        foreach (array_keys(self::COLOR_SETTINGS) as $key) {
            $value = $settings[$key] ?? '';
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $value = trim($value);
            if (preg_match(self::TOKEN_REF_PATTERN, $value)) {
                $normalized[$key] = $value;
                continue;
            }
            $color = AbstractElement::cssColor($value);
            if ($color !== null) {
                $normalized[$key] = $color;
            }
        }
        $radius = $settings['radius'] ?? '';
        if (is_string($radius) && isset(self::RADIUS_MAP[$radius]) && $radius !== 'none') {
            $normalized['radius'] = $radius;
        }
        foreach (['radius_px' => self::RADIUS_PX_RANGE, 'border_width_px' => self::BORDER_WIDTH_RANGE] as $key => $range) {
            $value = self::intInRange($settings[$key] ?? null, $range);
            if ($value !== null) {
                $normalized[$key] = $value;
            }
        }
        foreach (self::ENUM_SETTINGS as $key => [, $allowed]) {
            $value = self::enumOrNull($settings[$key] ?? null, $allowed);
            if ($value !== null) {
                $normalized[$key] = $key === 'font_weight' ? (int) $value : $value;
            }
        }
        $lineHeight = self::lineHeightOrNull($settings['line_height'] ?? null);
        if ($lineHeight !== null) {
            $normalized['line_height'] = (float) $lineHeight;
        }
        foreach (array_keys(self::RESPONSIVE_SETTINGS) as $key) {
            $value = $settings[$key] ?? null;
            if (is_numeric($value)) {
                $px = self::pxOrNull($value, $key);
                if ($px !== null) {
                    $normalized[$key] = $px;
                }
                continue;
            }
            if (!is_array($value)) {
                continue;
            }
            $tiers = [];
            foreach (['d', 't', 'm', 'w'] as $tier) {
                $px = self::pxOrNull($value[$tier] ?? null, $key);
                if ($px !== null) {
                    $tiers[$tier] = $px;
                }
            }
            // 只有桌面值时存标量（与旧数据同形）；只设了平板/手机的也保留（区间规则）
            if ($tiers !== []) {
                $normalized[$key] = count($tiers) === 1 && isset($tiers['d']) ? $tiers['d'] : $tiers;
            }
        }
        $transition = self::intInRange($settings['transition_ms'] ?? null, self::TRANSITION_RANGE);
        if ($transition !== null && $transition > 0) {
            $normalized['transition_ms'] = $transition;
        }
        // 状态：只收白名单状态与 STATE_KEYS，值走与基础相同的校验；空状态不落盘
        $rawStates = is_array($settings['states'] ?? null) ? $settings['states'] : [];
        $states = [];
        foreach (array_keys(self::STATES) as $state) {
            if (!is_array($rawStates[$state] ?? null)) {
                continue;
            }
            $allowed = array_flip(self::STATE_KEYS);
            $clean = array_intersect_key(self::normalizeSettings(array_intersect_key($rawStates[$state], $allowed)), $allowed);
            if ($clean !== []) {
                $states[$state] = $clean;
            }
        }
        if ($states !== []) {
            $normalized['states'] = $states;
        }
        return $normalized;
    }

    private static function pxOrNull(mixed $value, string $key): ?int
    {
        [, $min, $max] = self::RESPONSIVE_SETTINGS[$key];
        return self::intInRange($value, [$min, $max]);
    }

    /** @param array{0:int,1:int} $range */
    private static function intInRange(mixed $value, array $range): ?int
    {
        // 与旧 pxOrNull 同样宽松：数字或数字串取整（表单可能给出 "16"、16.0）
        if (!is_numeric($value) || !is_finite((float) $value)) {
            return null;
        }
        $number = (int) $value;
        return $number >= $range[0] && $number <= $range[1] ? $number : null;
    }

    /** @param list<string> $allowed */
    private static function enumOrNull(mixed $value, array $allowed): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** 行高：无单位倍数，0.8–3，保留两位小数；返回 CSS 文本。 */
    private static function lineHeightOrNull(mixed $value): ?string
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric(trim($value)))) {
            return null;
        }
        $number = round((float) $value, 2);
        if (!is_finite($number) || $number < self::LINE_HEIGHT_RANGE[0] || $number > self::LINE_HEIGHT_RANGE[1]) {
            return null;
        }
        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    // ── 单模板 JSON 的类可移植性（整站模板是整表替换，不走这里） ────────────

    /**
     * 导出：sections 引用到的活跃类的规范化定义（按类名排序）。回收站里的类不导出，
     * 导入端会把它们报为缺失引用。
     *
     * @return list<array{class_id:string,name:string,settings:array<string,mixed>}>
     */
    public static function exportDefinitions(array $sections): array
    {
        $catalog = self::catalog();
        $definitions = [];
        foreach (array_keys(self::collectReferences($sections)) as $classId) {
            if (isset($catalog[$classId])) {
                $definitions[] = [
                    'class_id' => $classId,
                    'name' => $catalog[$classId]['name'],
                    'settings' => $catalog[$classId]['settings'],
                ];
            }
        }
        usort($definitions, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $definitions;
    }

    /**
     * 导入前规划：把包里的类引用映射到本站。
     * - 同名且定义相同 → 复用本站类；
     * - 同名但定义不同 → 用「原名-定义哈希6位」这个稳定新名（重复导入同一个包得到同一个名字），不覆盖本站类；
     * - 本站没有同名类 → 新建（总是生成新 ID，不沿用来源站 ID）；
     * - 包里没带定义（旧包）：本站恰有同 ID 活跃类则保留，否则报缺失、引用原样保留（渲染期跳过）。
     * 只规划不写库；写入由 applyImportPlan() 在调用方事务内完成。
     *
     * @return array{
     *   map:array<string,string>,
     *   create:list<array{class_id:string,name:string,settings:array<string,mixed>}>,
     *   diagnostics:array{reused:list<string>,created:list<string>,renamed:list<array{from:string,to:string}>,missing:list<string>}
     * }
     */
    public static function planImport(array $sections, mixed $packageClasses): array
    {
        $plan = ['map' => [], 'create' => [], 'diagnostics' => ['reused' => [], 'created' => [], 'renamed' => [], 'missing' => []]];
        $references = array_keys(self::collectReferences($sections));
        if ($references === []) {
            return $plan;
        }
        if ($packageClasses !== null && !is_array($packageClasses)) {
            throw new RuntimeException(__('blox_class_import_invalid'));
        }
        $definitions = [];
        foreach (is_array($packageClasses) ? array_values($packageClasses) : [] as $index => $entry) {
            if ($index >= self::MAX_CLASSES || !is_array($entry)) {
                throw new RuntimeException(__('blox_class_import_invalid'));
            }
            $classId = (string) ($entry['class_id'] ?? '');
            $name = (string) ($entry['name'] ?? '');
            if (!preg_match(self::ID_PATTERN, $classId) || !preg_match(self::NAME_PATTERN, $name)) {
                throw new RuntimeException(__('blox_class_import_invalid'));
            }
            $definitions[$classId] = [
                'name' => $name,
                'settings' => self::normalizeSettings(is_array($entry['settings'] ?? null) ? $entry['settings'] : []),
            ];
        }
        $available = self::available();
        $planned = [];
        foreach ($references as $sourceId) {
            $definition = $definitions[$sourceId] ?? null;
            if ($definition === null) {
                if ($available && isset(self::catalog()[$sourceId])) {
                    $plan['map'][$sourceId] = $sourceId;
                } else {
                    $plan['diagnostics']['missing'][] = $sourceId;
                }
                continue;
            }
            if (!$available) {
                throw new RuntimeException(__('blox_class_storage_missing'));
            }
            $target = self::resolveImportName($definition['name'], $definition['settings'], $planned);
            if ($target['class_id'] !== '') {
                $plan['map'][$sourceId] = $target['class_id'];
                $plan['diagnostics']['reused'][] = $target['name'];
            } else {
                $row = ['class_id' => 'gc_' . bin2hex(random_bytes(6)), 'name' => $target['name'], 'settings' => $definition['settings']];
                $planned[$row['name']] = $row;
                $plan['create'][] = $row;
                $plan['map'][$sourceId] = $row['class_id'];
                $plan['diagnostics']['created'][] = $row['name'];
            }
            if ($target['name'] !== $definition['name']) {
                $plan['diagnostics']['renamed'][] = ['from' => $definition['name'], 'to' => $target['name']];
            }
        }
        if ($plan['create'] !== []) {
            $total = (int) db()->fetchColumn('SELECT COUNT(*) FROM ' . DB_PREFIX . 'blox_global_classes');
            if ($total + count($plan['create']) > self::MAX_CLASSES) {
                throw new RuntimeException(__('blox_class_limit', ['max' => self::MAX_CLASSES]));
            }
        }
        return $plan;
    }

    /**
     * 同名同定义复用，同名异定义换稳定新名。返回 class_id 为空表示需要新建。
     *
     * @param array<string,array{class_id:string,name:string,settings:array<string,mixed>}> $planned 本次已规划新建的类
     * @return array{class_id:string,name:string}
     */
    private static function resolveImportName(string $name, array $settings, array $planned): array
    {
        $fingerprint = self::settingsFingerprint($settings);
        $suffix = substr(hash('sha256', $fingerprint), 0, 6);
        $candidates = [$name, substr($name, 0, 41) . '-' . $suffix];
        for ($i = 2; $i <= 9; $i++) {
            $candidates[] = substr($name, 0, 39) . '-' . $suffix . '-' . $i;
        }
        foreach ($candidates as $candidate) {
            if (isset($planned[$candidate])) {
                if (self::settingsFingerprint($planned[$candidate]['settings']) === $fingerprint) {
                    return ['class_id' => $planned[$candidate]['class_id'], 'name' => $candidate];
                }
                continue;
            }
            $existing = bloxGlobalClassModel()->findActiveByName($candidate);
            if ($existing === null) {
                return ['class_id' => '', 'name' => $candidate];
            }
            $existingSettings = json_decode((string) ($existing['settings'] ?? ''), true);
            if (self::settingsFingerprint(self::normalizeSettings(is_array($existingSettings) ? $existingSettings : [])) === $fingerprint) {
                return ['class_id' => (string) $existing['class_id'], 'name' => $candidate];
            }
        }
        throw new RuntimeException(__('blox_class_duplicate_name'));
    }

    /** 定义比较用的规范化指纹：键序无关。 */
    private static function settingsFingerprint(array $settings): string
    {
        $sort = static function (array $value) use (&$sort): array {
            ksort($value);
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $sort($item);
                }
            }
            return $value;
        };
        return json_encode($sort($settings), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    /** 按规划把 sections 里的 _classes 换成本站 ID（未映射的原样保留）。 */
    public static function remapSections(array $sections, array $map): array
    {
        if ($map === []) {
            return $sections;
        }
        $remapElement = static function (array $element) use (&$remapElement, $map): array {
            $data = is_array($element['data'] ?? null) ? $element['data'] : null;
            if ($data === null) {
                return $element;
            }
            if (is_array($data['_classes'] ?? null)) {
                $data['_classes'] = array_values(array_unique(array_map(
                    static fn(mixed $id): mixed => is_string($id) && isset($map[$id]) ? $map[$id] : $id,
                    $data['_classes']
                ), SORT_REGULAR));
            }
            if (is_array($data['children'] ?? null)) {
                $data['children'] = array_map(static fn(mixed $child): mixed => is_array($child) ? $remapElement($child) : $child, $data['children']);
            }
            $element['data'] = $data;
            return $element;
        };
        foreach ($sections as $si => $section) {
            if (!is_array($section) || !is_array($section['columns'] ?? null)) {
                continue;
            }
            foreach ($section['columns'] as $ci => $column) {
                if (!is_array($column) || !is_array($column['elements'] ?? null)) {
                    continue;
                }
                foreach ($column['elements'] as $ei => $element) {
                    if (is_array($element)) {
                        $sections[$si]['columns'][$ci]['elements'][$ei] = $remapElement($element);
                    }
                }
            }
        }
        return $sections;
    }

    /**
     * 在调用方事务内写入规划的新类。规划与写入之间若有人建了同名类：定义相同就算了，
     * 不同则抛错让整个导入回滚（不留半套类，也不覆盖对方）。事务提交后调用 invalidateStylesheet()。
     *
     * @param list<array{class_id:string,name:string,settings:array<string,mixed>}> $create
     */
    public static function applyImportPlan(array $create, int $userId = 0): void
    {
        if ($create === []) {
            return;
        }
        $now = time();
        foreach ($create as $row) {
            $existing = bloxGlobalClassModel()->findActiveByName($row['name']);
            if ($existing !== null) {
                throw new RuntimeException(__('blox_class_import_changed'));
            }
            db()->insert('blox_global_classes', [
                'class_id' => $row['class_id'],
                'name' => $row['name'],
                'category' => '',
                'settings' => json_encode(self::normalizeSettings($row['settings']), JSON_UNESCAPED_UNICODE),
                'status' => BloxGlobalClassModel::STATUS_ACTIVE,
                'modified' => $now,
                'revision' => 0,
                'user_id' => max(0, $userId),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        self::$catalog = null;
    }

    // ── 用量反向索引 ─────────────────────────────────────────────────

    /** 文档保存时调用：整体替换该文档的类引用行。 */
    public static function replaceDocumentRefs(string $docKey, array $counts): void
    {
        if (!self::available() || $docKey === '' || strlen($docKey) > 64) {
            return;
        }
        $now = time();
        db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_class_refs WHERE doc_key = ?', [$docKey]);
        foreach ($counts as $classId => $count) {
            if (!is_string($classId) || !preg_match(self::ID_PATTERN, $classId) || (int) $count < 1) {
                continue;
            }
            db()->insert('blox_class_refs', [
                'class_id' => $classId,
                'doc_key' => $docKey,
                'ref_count' => (int) $count,
                'updated_at' => $now,
            ]);
        }
    }

    /** @return array<string,array{docs:int,refs:int}> class_id => 用量 */
    public static function usage(): array
    {
        if (!self::available()) {
            return [];
        }
        $usage = [];
        foreach (db()->fetchAll(
            'SELECT class_id, COUNT(*) AS docs, SUM(ref_count) AS refs FROM ' . DB_PREFIX . 'blox_class_refs GROUP BY class_id'
        ) as $row) {
            $usage[(string) $row['class_id']] = [
                'docs' => (int) ($row['docs'] ?? 0),
                'refs' => (int) ($row['refs'] ?? 0),
            ];
        }
        return $usage;
    }

    private static function purgeExpiredTrash(): void
    {
        $cutoff = time() - self::TRASH_RETENTION_DAYS * 86400;
        $expired = db()->fetchAll(
            'SELECT class_id FROM ' . DB_PREFIX . "blox_global_classes WHERE status = 'trashed' AND trashed_at > 0 AND trashed_at < ?",
            [$cutoff]
        );
        foreach ($expired as $row) {
            $classId = (string) ($row['class_id'] ?? '');
            db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_global_classes WHERE class_id = ?', [$classId]);
            db()->execute('DELETE FROM ' . DB_PREFIX . 'blox_class_refs WHERE class_id = ?', [$classId]);
        }
    }
}
