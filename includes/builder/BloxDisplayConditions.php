<?php
/**
 * Blox 区块/元素显示条件：OR 组、组内 AND 规则、运行时布尔求值与授权边界。
 *
 * 条件键（v1.27 补齐六维）：login/date/channel/url（初版）+ language/device/datetime/
 * param/field。计划中的 role 键**未实现**：members 表没有角色体系（2026-09-20 实测
 * 仅 username/status 等基础列），无实体可指——等会员分组功能落地再补。
 * device 采用服务端求值（计划原写客户端）：HtmlCache 缓存键本就含 isMobile，
 * 与其同源判定即缓存安全，且无客户端显隐的闪烁问题；tablet 刻意不支持（键不分平板）。
 */

declare(strict_types=1);

require_once __DIR__ . '/BloxFeaturePolicy.php';

final class BloxDisplayConditions
{
    private const MAX_GROUPS = 10;
    private const MAX_RULES = 10;

    /**
     * 缓存安全分级（v1.27 硬规则）：含下列条件键的页面**禁止整页缓存**——同一 URL 的
     * 求值结果会随请求侧状态漂移，落盘即冻结错误分支。
     * 只有 datetime（分钟粒度，TTL 内必然漂移）不安全。其余键的安全性有明确支撑：
     * login（登录会话整体不缓存，isCacheable 排除 member/admin）、device/language
     * （键含 isMobile 与语言）、param/url（外审 P1-1 起条件与缓存键共用
     * HtmlCache::canonicalRequest() 同一规范化视图，同键必同求值）、
     * date（外审 P1-2 起缓存键含 Y-m-d 日期桶，跨午夜自动换桶）。
     * 新增条件键时先按此口径归级，不安全的加进本数组。
     */
    private const CACHE_UNSAFE_TYPES = ['datetime'];

    /** @var bool 本请求的真实前台求值是否遇到过缓存不安全条件（编辑器预览不设） */
    private static bool $pageHasCacheUnsafe = false;

    /** @var array<string,string|null> field 条件的请求级取值缓存（键=type:id:name） */
    private static array $fieldValueCache = [];

    /** @psalm-suppress PossiblyUnusedMethod 测试专用（单测进程共享请求级状态时复位） */
    public static function resetForTests(): void
    {
        self::$pageHasCacheUnsafe = false;
        self::$fieldValueCache = [];
    }

    /** HtmlCache 写盘前查询：本页是否出现过缓存不安全条件。 */
    public static function pageCacheMustSkip(): bool
    {
        return self::$pageHasCacheUnsafe;
    }

    /**
     * 一组条件是否含缓存不安全键（与渲染期上报同一口径）。
     * @psalm-suppress PossiblyUnusedMethod 供编辑器/诊断类外部标注入口与测试使用
     */
    public static function cacheUnsafe(mixed $raw): bool
    {
        $groups = self::parse($raw);
        foreach ($groups ?? [] as $group) {
            foreach ($group['rules'] as $rule) {
                if (in_array($rule['type'], self::CACHE_UNSAFE_TYPES, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @return array<string,mixed> */
    public static function currentContext(): array
    {
        // url/param 与缓存键同源规范化（外审 P1-1）：白名单内参数用 HtmlCache 的
        // 规范化视图（ksort 排序 + page 归一），保证同一缓存键下条件结果恒定；
        // 白名单外参数（query=null）的请求 isCacheable() 恒 false 永不落缓存，
        // 用原始值无一致性风险。
        $canonical = class_exists('HtmlCache') ? HtmlCache::canonicalRequest() : ['path' => null, 'query' => null];
        if (is_string($canonical['path']) && is_array($canonical['query'])) {
            $url = $canonical['path'];
            if ($canonical['query'] !== []) {
                $url .= '?' . http_build_query($canonical['query'], '', '&', PHP_QUERY_RFC3986);
            }
            $params = $canonical['query'];
        } else {
            $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
            $url = parse_url($requestUri, PHP_URL_PATH);
            $url = is_string($url) && $url !== '' ? $url : '/';
            $query = parse_url($requestUri, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                $url .= '?' . $query;
            }
            $params = [];
            foreach ($_GET as $key => $value) {
                if (is_string($key) && is_scalar($value)) {
                    $params[$key] = (string) $value;
                }
            }
        }

        return [
            'logged_in' => !empty($_SESSION['member_id']),
            'date' => date('Y-m-d'),
            'datetime' => date('Y-m-d H:i'),
            'channel_id' => (int) ($GLOBALS['currentChannelId'] ?? 0),
            'url' => $url,
            // v1.27：语言/设备与 HtmlCache 缓存键同源（键含语言与 isMobile → 两键缓存安全）
            'lang' => function_exists('siteLang') ? siteLang() : '',
            'device' => class_exists('HtmlCache') && HtmlCache::isMobileClient() ? 'mobile' : 'desktop',
            'params' => $params,
            // field 条件的取数源：详情页 setItem 的主条目 / 容器 Loop 的当前行（按行求值）
            'item' => class_exists('TagEngine') ? TagEngine::currentContext() : null,
        ];
    }

    /**
     * 空条件始终显示；有输入但结构或规则非法时 fail-closed。
     *
     * @param array<string,mixed>|null $context
     */
    public static function matches(mixed $raw, ?array $context = null): bool
    {
        if (!self::hasInput($raw)) {
            return true;
        }
        $groups = self::parse($raw);
        if ($groups === null || $groups === []) {
            return false;
        }
        // 缓存安全上报（v1.27）：页面文档里**存在**不安全条件即禁整页缓存，
        // 与求值结果无关（另一分支被冻结同样是错的）
        if (!self::$pageHasCacheUnsafe) {
            foreach ($groups as $group) {
                foreach ($group['rules'] as $rule) {
                    if (in_array($rule['type'], self::CACHE_UNSAFE_TYPES, true)) {
                        self::$pageHasCacheUnsafe = true;
                        break 2;
                    }
                }
            }
        }
        $context = self::normalizeContext($context ?? self::currentContext());
        foreach ($groups as $group) {
            $matches = true;
            foreach ($group['rules'] as $rule) {
                if (!self::ruleMatches($rule, $context)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }
        return false;
    }

    public static function hasInput(mixed $raw): bool
    {
        return $raw !== null && $raw !== [];
    }

    /** 画布角标显示“组数/规则数”，不泄露具体条件值。 */
    public static function badge(mixed $raw): string
    {
        $groups = self::parse($raw);
        if ($groups === null || $groups === []) {
            return self::hasInput($raw) ? '!' : '';
        }
        $rules = array_sum(array_map(static fn (array $group): int => count($group['rules']), $groups));
        return count($groups) . '/' . $rules;
    }

    /**
     * @return list<array{rules:list<array{type:string,operator:string,value:string|int}>}>|null
     */
    public static function parse(mixed $raw): ?array
    {
        if (!is_array($raw) || !BloxDocumentPipeline::isList($raw) || count($raw) > self::MAX_GROUPS) {
            return null;
        }
        $groups = [];
        foreach ($raw as $group) {
            if (!is_array($group) || !is_array($group['rules'] ?? null)
                || !BloxDocumentPipeline::isList($group['rules']) || $group['rules'] === []
                || count($group['rules']) > self::MAX_RULES) {
                return null;
            }
            $rules = [];
            foreach ($group['rules'] as $rule) {
                $normalized = self::parseRule($rule);
                if ($normalized === null) {
                    return null;
                }
                $rules[] = $normalized;
            }
            $groups[] = ['rules' => $rules];
        }
        return $groups;
    }

    /** @param array<int,mixed> $sections */
    public static function assertSectionsAllowed(array $sections, ?bool $advanced = null): void
    {
        $hasConditions = false;
        foreach ($sections as $section) {
            if (!is_array($section)) {
                continue;
            }
            self::assertRaw($section['settings']['_conditions'] ?? null, $hasConditions);
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $element) {
                    if (is_array($element)) {
                        self::assertElement($element, $hasConditions);
                    }
                }
            }
        }
        if ($hasConditions && !($advanced ?? BloxFeaturePolicy::allows('display_conditions'))) {
            throw new RuntimeException(__('blox_display_conditions_license_required'));
        }
    }

    /** @api Compatibility entry for callers with serialized documents. */
    public static function assertJsonAllowed(string $json, ?bool $advanced = null): void
    {
        $document = BloxDocumentPipeline::decode($json);
        self::assertSectionsAllowed($document['sections'], $advanced);
    }

    /** @param array<string,mixed> $element */
    private static function assertElement(array $element, bool &$hasConditions): void
    {
        $data = is_array($element['data'] ?? null) ? $element['data'] : [];
        self::assertRaw($data['_conditions'] ?? null, $hasConditions);
        foreach (is_array($data['children'] ?? null) ? $data['children'] : [] as $child) {
            if (is_array($child)) {
                self::assertElement($child, $hasConditions);
            }
        }
    }

    private static function assertRaw(mixed $raw, bool &$hasConditions): void
    {
        if ($raw === null || $raw === []) {
            return;
        }
        $hasConditions = true;
        if (self::parse($raw) === null) {
            throw new RuntimeException(__('blox_display_conditions_invalid'));
        }
    }

    /**
     * v1.27 起规则结构可带第四键 `name`（param / field 用：参数名或字段名）；
     * 旧三键规则原样兼容。
     *
     * @return array{type:string,operator:string,value:string|int,name?:string}|null
     */
    private static function parseRule(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $type = trim((string) ($raw['type'] ?? ''));
        $operator = trim((string) ($raw['operator'] ?? ''));
        $value = $raw['value'] ?? '';

        if ($type === 'login' && $operator === 'is' && in_array($value, ['logged_in', 'logged_out'], true)) {
            return ['type' => $type, 'operator' => $operator, 'value' => (string) $value];
        }
        if ($type === 'date' && in_array($operator, ['before', 'on', 'after'], true)) {
            $value = trim((string) $value);
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date !== false && $date->format('Y-m-d') === $value) {
                return ['type' => $type, 'operator' => $operator, 'value' => $value];
            }
            return null;
        }
        // v1.27 精确时间（分钟粒度）——缓存不安全键（CACHE_UNSAFE_TYPES）
        if ($type === 'datetime' && in_array($operator, ['before', 'after'], true)) {
            $value = trim((string) $value);
            $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);
            if ($moment !== false && $moment->format('Y-m-d H:i') === $value) {
                return ['type' => $type, 'operator' => $operator, 'value' => $value];
            }
            return null;
        }
        if ($type === 'channel' && in_array($operator, ['is', 'is_not'], true)) {
            $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            return $value === false ? null : ['type' => $type, 'operator' => $operator, 'value' => (int) $value];
        }
        if ($type === 'url' && in_array($operator, ['equals', 'not_equals', 'contains', 'not_contains', 'starts_with'], true)) {
            $value = trim((string) $value);
            if ($value !== '' && strlen($value) <= 500) {
                return ['type' => $type, 'operator' => $operator, 'value' => $value];
            }
            return null;
        }
        // v1.27 语言：值与区域解析同一形态校验（zh-CN / en / ja …）
        if ($type === 'language' && in_array($operator, ['is', 'is_not'], true)) {
            $value = trim((string) $value);
            if (preg_match('/^[a-z]{2,3}(?:-[A-Z]{2})?$/', $value) === 1) {
                return ['type' => $type, 'operator' => $operator, 'value' => $value];
            }
            return null;
        }
        // v1.27 设备：mobile/desktop 二分，与 HtmlCache 缓存键的 isMobile 同源（缓存安全）；
        // 不做 tablet——缓存键不分平板，分了就会把错误分支冻结进缓存
        if ($type === 'device' && $operator === 'is' && in_array($value, ['mobile', 'desktop'], true)) {
            return ['type' => $type, 'operator' => $operator, 'value' => (string) $value];
        }
        // v1.27 URL 参数：name 必填；exists/not_exists 不需要 value
        if ($type === 'param' && in_array($operator, ['equals', 'not_equals', 'contains', 'exists', 'not_exists'], true)) {
            $name = trim((string) ($raw['name'] ?? ''));
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_\-\[\]]{0,63}$/', $name) !== 1) {
                return null;
            }
            $value = mb_substr(trim((string) $value), 0, 200);
            if (in_array($operator, ['exists', 'not_exists'], true)) {
                $value = '';
            } elseif ($value === '') {
                return null;
            }
            return ['type' => $type, 'operator' => $operator, 'value' => $value, 'name' => $name];
        }
        // v1.27 自定义字段：当前条目（详情页主条目 / 循环当前行）的列或扩展字段
        if ($type === 'field' && in_array($operator, ['equals', 'not_equals', 'contains', 'empty', 'not_empty'], true)) {
            $name = strtolower(trim((string) ($raw['name'] ?? '')));
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) !== 1) {
                return null;
            }
            $value = mb_substr(trim((string) $value), 0, 200);
            if (in_array($operator, ['empty', 'not_empty'], true)) {
                $value = '';
            } elseif ($value === '') {
                return null;
            }
            return ['type' => $type, 'operator' => $operator, 'value' => $value, 'name' => $name];
        }
        return null;
    }

    /**
     * @param array{type:string,operator:string,value:string|int,name?:string} $rule
     * @param array<string,mixed> $context
     */
    private static function ruleMatches(array $rule, array $context): bool
    {
        $value = $rule['value'];
        return match ($rule['type']) {
            'login' => $context['logged_in'] === ($value === 'logged_in'),
            'date' => match ($rule['operator']) {
                'before' => $context['date'] < $value,
                'on' => $context['date'] === $value,
                'after' => $context['date'] > $value,
            },
            'datetime' => $rule['operator'] === 'before'
                ? $context['datetime'] < $value
                : $context['datetime'] > $value,
            'channel' => $rule['operator'] === 'is'
                ? $context['channel_id'] === $value
                : $context['channel_id'] !== $value,
            'url' => match ($rule['operator']) {
                'equals' => $context['url'] === $value,
                'not_equals' => $context['url'] !== $value,
                'contains' => str_contains($context['url'], (string) $value),
                'not_contains' => !str_contains($context['url'], (string) $value),
                'starts_with' => str_starts_with($context['url'], (string) $value),
            },
            'language' => $rule['operator'] === 'is'
                ? $context['lang'] === $value
                : $context['lang'] !== $value,
            'device' => $context['device'] === $value,
            'param' => self::paramMatches($rule, $context['params']),
            'field' => self::fieldMatches($rule, $context),
        };
    }

    /** @param array{type:string,operator:string,value:string|int,name?:string} $rule @param array<string,string> $params */
    private static function paramMatches(array $rule, array $params): bool
    {
        $name = (string) ($rule['name'] ?? '');
        $exists = array_key_exists($name, $params);
        $actual = $exists ? $params[$name] : '';
        return match ($rule['operator']) {
            'exists' => $exists,
            'not_exists' => !$exists,
            'equals' => $exists && $actual === $rule['value'],
            'not_equals' => !$exists || $actual !== $rule['value'],
            'contains' => $exists && str_contains($actual, (string) $rule['value']),
        };
    }

    /**
     * 字段值来自当前条目（详情主条目 / 循环当前行）：条目自身的标量列优先，
     * 缺列再查扩展字段（metas，owner 走 resolveExtFieldOwner 与存端同源）。
     * 无当前条目（首页/列表卡片之外）= 无值：equals/contains fail-closed，empty 视为空。
     *
     * @param array{type:string,operator:string,value:string|int,name?:string} $rule
     * @param array<string,mixed> $context
     */
    private static function fieldMatches(array $rule, array $context): bool
    {
        $name = (string) ($rule['name'] ?? '');
        // 显式注入（测试/未来的服务端预判）优先，其次当前条目惰性取值
        $fields = is_array($context['fields'] ?? null) ? $context['fields'] : null;
        $actual = $fields !== null
            ? (array_key_exists($name, $fields) ? (string) $fields[$name] : null)
            : self::fieldValue(is_array($context['item'] ?? null) ? $context['item'] : null, $name);
        $actual = $actual !== null ? trim($actual) : null;
        return match ($rule['operator']) {
            'empty' => $actual === null || $actual === '',
            'not_empty' => $actual !== null && $actual !== '',
            'equals' => $actual !== null && $actual === $rule['value'],
            'not_equals' => $actual === null || $actual !== $rule['value'],
            'contains' => $actual !== null && str_contains($actual, (string) $rule['value']),
        };
    }

    /** @param array<string,mixed>|null $item */
    private static function fieldValue(?array $item, string $name): ?string
    {
        if ($item === null) {
            return null;
        }
        if (array_key_exists($name, $item)) {
            return is_scalar($item[$name]) ? (string) $item[$name] : null;
        }
        $id = (int) ($item['id'] ?? 0);
        $type = trim((string) ($item['type'] ?? ($item['_type'] ?? '')));
        if ($id <= 0 || $type === '' || !function_exists('getMeta')) {
            return null;
        }
        $owner = $type === 'product'
            ? 'product'
            : (function_exists('resolveExtFieldOwner') ? resolveExtFieldOwner($type) : 'content');
        $cacheKey = $owner . ':' . $id . ':' . $name;
        if (!array_key_exists($cacheKey, self::$fieldValueCache)) {
            $meta = getMeta($owner, $id, $name);
            self::$fieldValueCache[$cacheKey] = is_scalar($meta) ? (string) $meta : null;
        }
        return self::$fieldValueCache[$cacheKey];
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function normalizeContext(array $context): array
    {
        $date = (string) ($context['date'] ?? date('Y-m-d'));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            $date = date('Y-m-d');
        }
        $datetime = (string) ($context['datetime'] ?? date('Y-m-d H:i'));
        $parsedMoment = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $datetime);
        if ($parsedMoment === false || $parsedMoment->format('Y-m-d H:i') !== $datetime) {
            $datetime = date('Y-m-d H:i');
        }
        $params = [];
        foreach (is_array($context['params'] ?? null) ? $context['params'] : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $params[$key] = (string) $value;
            }
        }
        return [
            'logged_in' => !empty($context['logged_in']),
            'date' => $date,
            'datetime' => $datetime,
            'channel_id' => max(0, (int) ($context['channel_id'] ?? 0)),
            'url' => (string) ($context['url'] ?? '/'),
            'lang' => (string) ($context['lang'] ?? ''),
            'device' => ($context['device'] ?? '') === 'mobile' ? 'mobile' : 'desktop',
            'params' => $params,
            'item' => is_array($context['item'] ?? null) ? $context['item'] : null,
            'fields' => is_array($context['fields'] ?? null) ? $context['fields'] : null,
        ];
    }
}
