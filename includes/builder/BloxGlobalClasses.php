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

    private const RADIUS_MAP = [
        'none' => '0',
        'sm' => '0.25rem',
        'md' => '0.5rem',
        'lg' => '0.75rem',
        'full' => '9999px',
    ];
    /** 响应式 px 设置：key => [css 属性, min, max]。 */
    private const PX_SETTINGS = [
        'padding_px' => ['padding', 0, 160],
        'gap_px' => ['gap', 0, 160],
        'font_size_px' => ['font-size', 10, 120],
    ];
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
        foreach (self::catalog() as $class) {
            $css = self::classRules($class['name'], $class['settings']);
            if ($css !== '') {
                $rules[] = $css;
            }
        }
        return implode("\n", $rules);
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
        return ROOT_PATH . '/uploads/blox/css/classes.css';
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

    /** 单个类的 CSS 规则（基档=手机，t/d/w 按差异出媒体查询；无内容返回空串）。 */
    public static function classRules(string $name, array $settings): string
    {
        $selector = '.' . self::CLASS_PREFIX . $name;
        $base = [];
        foreach (self::COLOR_SETTINGS as $key => $property) {
            $value = $settings[$key] ?? '';
            if (is_string($value) && $value !== '') {
                $base[] = $property . ':' . $value;
            }
        }
        $radius = $settings['radius'] ?? '';
        if (is_string($radius) && $radius !== '' && $radius !== 'none' && isset(self::RADIUS_MAP[$radius])) {
            $base[] = 'border-radius:' . self::RADIUS_MAP[$radius];
        }
        if (isset($base[0]) && ($settings['border_color'] ?? '') !== '') {
            $base[] = 'border-style:solid';
            $base[] = 'border-width:1px';
        }

        $tiers = ['t' => [], 'd' => [], 'w' => []];
        foreach (self::PX_SETTINGS as $key => [$property]) {
            $value = $settings[$key] ?? null;
            if (!is_array($value) && !is_numeric($value)) {
                continue;
            }
            $raw = is_array($value) ? $value : ['d' => $value];
            $d = self::pxOrNull($raw['d'] ?? null, $key);
            if ($d === null) {
                continue;
            }
            $t = self::pxOrNull($raw['t'] ?? null, $key) ?? $d;
            $m = self::pxOrNull($raw['m'] ?? null, $key) ?? $t;
            $w = self::pxOrNull($raw['w'] ?? null, $key) ?? $d;
            $base[] = $property . ':' . $m . 'px';
            if ($t !== $m) {
                $tiers['t'][] = $property . ':' . $t . 'px';
            }
            if ($d !== $t) {
                $tiers['d'][] = $property . ':' . $d . 'px';
            }
            if ($w !== $d && BloxResponsiveValue::wideEnabled()) {
                $tiers['w'][] = $property . ':' . $w . 'px';
            }
        }

        if ($base === [] && $tiers['t'] === [] && $tiers['d'] === [] && $tiers['w'] === []) {
            return '';
        }
        $css = $base === [] ? '' : $selector . '{' . implode(';', $base) . '}';
        foreach (['t' => 768, 'd' => 1024, 'w' => 1440] as $tier => $minWidth) {
            if ($tiers[$tier] !== []) {
                $css .= '@media (min-width:' . $minWidth . 'px){' . $selector . '{' . implode(';', $tiers[$tier]) . '}}';
            }
        }
        return $css;
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
            'user_id' => max(0, (int) ($input['user_id'] ?? 0)),
            'created_at' => $now,
            'updated_at' => $now,
        ];
        db()->insert('blox_global_classes', $row);
        return $row;
    }

    /** @param array<string,mixed> $input */
    private static function updateSettings(array $input): array
    {
        $row = self::assertRow($input);
        $settings = self::normalizeSettings(is_array($input['settings'] ?? null) ? $input['settings'] : []);
        $now = time();
        db()->update('blox_global_classes', [
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE),
            'modified' => $now,
            'user_id' => max(0, (int) ($input['user_id'] ?? 0)),
            'updated_at' => $now,
        ], 'class_id = ?', [$row['class_id']]);
        return bloxGlobalClassModel()->findByClassId((string) $row['class_id']) ?? $row;
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
        db()->update('blox_global_classes', [
            'name' => $name,
            'modified' => $now,
            'updated_at' => $now,
        ], 'class_id = ?', [$row['class_id']]);
        return bloxGlobalClassModel()->findByClassId((string) $row['class_id']) ?? $row;
    }

    /** @param array<string,mixed> $input */
    private static function setStatus(array $input, string $status): array
    {
        $row = self::assertRow($input, false);
        $now = time();
        db()->update('blox_global_classes', [
            'status' => $status,
            'trashed_at' => $status === BloxGlobalClassModel::STATUS_TRASHED ? $now : 0,
            'modified' => $now,
            'updated_at' => $now,
        ], 'class_id = ?', [$row['class_id']]);
        return bloxGlobalClassModel()->findByClassId((string) $row['class_id']) ?? $row;
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
        db()->update('blox_global_classes', [
            'name' => $candidate,
            'status' => BloxGlobalClassModel::STATUS_ACTIVE,
            'trashed_at' => 0,
            'modified' => $now,
            'updated_at' => $now,
        ], 'class_id = ?', [$row['class_id']]);
        return bloxGlobalClassModel()->findByClassId((string) $row['class_id']) ?? $row;
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
        // 乐观并发：调用方带上读取时的 modified，落库时间戳不一致即冲突（Bricks 同款语义）
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
        foreach (array_keys(self::PX_SETTINGS) as $key) {
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
            if (isset($tiers['d'])) {
                $normalized[$key] = count($tiers) === 1 ? $tiers['d'] : $tiers;
            }
        }
        return $normalized;
    }

    private static function pxOrNull(mixed $value, string $key): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        [, $min, $max] = self::PX_SETTINGS[$key];
        $px = (int) $value;
        return $px >= $min && $px <= $max ? $px : null;
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
