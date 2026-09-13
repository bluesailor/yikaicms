<?php
/**
 * 详情页模板条件判定（纯函数层，v2 契约）。
 *
 * 设计约束（见 yikaicms-docs 五轮任务书 DS-BLOX-01A/01D）：
 * - **自包含**：候选模板、内容上下文、内容级手动绑定、分类距离全部由调用方显式传入。
 *   本类不查库、不读 $_GET/$_SESSION/全局、不依赖隐藏状态；同一输入必得同一输出。
 * - **fail-closed**：契约缺失或畸形一律「未应用」，只有显式 kind=all 才代表全部内容；
 *   语言不符、候选为空、类型不符都退化为 native，绝不退化成全站套用。
 * - **不静默抢答**：同具体度同 priority 的并列会在结果里返回 reason=conflicted 与
 *   conflicts 清单，供后台发布前要求用户解决；运行时仍给出确定性的兜底（取最大模板 ID），
 *   不得随机返回。旧规则（v1）经 legacyScope() 只读适配，并保留其历史并列语义。
 * - 与「样式继承」「元素交互」无关：本类只回答「哪套布局」。
 *
 * PHP 8.0 兼容（composer.json 要求 php >=8.0）：不使用 enum/readonly/never 等新语法，
 * 用类常量表达枚举，与相邻契约类（BloxDisplayConditions 等）写法一致。
 */

declare(strict_types=1);

final class DetailTemplateResolver
{
    public const VERSION = 2;

    /** 内容类型词汇（对齐 contents.type，见 install/sql/mysql.sql）。 */
    public const CONTENT_TYPES = ['product', 'article'];

    /**
     * 内容类型 → 模板行类型 的显式映射。
     * 两套词表**不同**：contents.type 是 product/article，blox_templates.type 是
     * product-detail/article-detail。这里必须显式映射，不可直接比较，否则判定永不命中。
     */
    public const TEMPLATE_TYPES = ['product' => 'product-detail', 'article' => 'article-detail'];

    /** 条件规则种类：all=该类型全部；item=指定内容；category=指定分类/栏目。 */
    public const KINDS = ['all', 'item', 'category'];

    /** 内容级手动绑定模式。 */
    public const BINDING_MODES = ['auto', 'native', 'template'];

    public const MAX_RULES = 20;
    public const MAX_IDS_PER_RULE = 500;
    public const MAX_PRIORITY = 100;

    public const SOURCE_CUSTOM = 'custom';
    public const SOURCE_NATIVE = 'native';

    /** reason 枚举：后台影响预览与「为什么使用它」直接消费，不要在别处另立词汇。 */
    public const REASON_BINDING_TEMPLATE = 'binding_template';
    public const REASON_BINDING_NATIVE = 'binding_native';
    public const REASON_BINDING_INVALID = 'binding_invalid';
    public const REASON_SPECIFIC_ITEM = 'specific_item';
    public const REASON_SPECIFIC_CATEGORY = 'specific_category';
    public const REASON_TYPE_ALL = 'type_all';
    public const REASON_NO_CANDIDATE = 'no_candidate';
    public const REASON_CONFLICTED = 'conflicted';

    private const SPEC_ITEM = 3;
    private const SPEC_CATEGORY = 2;
    private const SPEC_ALL = 1;

    private const LEGACY_DIMENSION = 'same_specificity_and_priority';

    /**
     * 归一化 v2 条件作用域。任何输入都返回结构完整的数组（不可用时 content_type=''）。
     *
     * @return array{version:int,content_type:string,lang:string,source:string,priority:int,
     *               include:list<array{kind:string,ids:list<int>,include_children:bool}>,
     *               exclude:list<array{kind:string,ids:list<int>,include_children:bool}>,legacy:bool}
     */
    public static function normalizeScope(mixed $raw): array
    {
        $value = is_array($raw) ? $raw : [];

        $scope = [
            'version' => self::VERSION,
            'content_type' => '',
            'lang' => '',
            'source' => self::SOURCE_CUSTOM,
            'priority' => 0,
            'include' => [],
            'exclude' => [],
            'legacy' => false,
        ];

        // version 只接受 2；legacy 由 legacyScope() 显式标记，不从数据里推断
        $version = $value['version'] ?? null;
        if ($version !== null && $version !== self::VERSION && $version !== (string) self::VERSION) {
            return $scope;
        }

        $contentType = is_string($value['content_type'] ?? null) ? trim((string) $value['content_type']) : '';
        if (!in_array($contentType, self::CONTENT_TYPES, true)) {
            return $scope;   // 类型不认识 → 整个作用域不可用，绝不降级成 all
        }
        $scope['content_type'] = $contentType;

        $lang = is_string($value['lang'] ?? null) ? trim((string) $value['lang']) : '';
        if ($lang === '' || strlen($lang) > 16 || preg_match('/[\x00-\x1f\x7f]/', $lang) === 1) {
            return $scope;   // 语言缺失/异常 → 不可用（与 v1 的「lang 必填」一致）
        }
        $scope['lang'] = $lang;

        if (($value['source'] ?? null) === self::SOURCE_NATIVE) {
            $scope['source'] = self::SOURCE_NATIVE;
        }

        $priority = $value['priority'] ?? 0;
        if (is_int($priority) || (is_string($priority) && preg_match('/^\d+$/D', $priority) === 1)) {
            $scope['priority'] = max(0, min(self::MAX_PRIORITY, (int) $priority));
        }

        $scope['include'] = self::normalizeRules($value['include'] ?? null, true);
        $scope['exclude'] = self::normalizeRules($value['exclude'] ?? null, false);

        return $scope;
    }

    /**
     * 归一化内容级手动绑定。null / 非法一律视为 auto（不指定），
     * mode=template 但 template_id 非法时保留 template 模式，由 resolve() 报 binding_invalid。
     *
     * @return array{mode:string,template_id:int}
     */
    public static function normalizeBinding(mixed $raw): array
    {
        $value = is_array($raw) ? $raw : [];
        $mode = is_string($value['mode'] ?? null) ? (string) $value['mode'] : 'auto';
        if (!in_array($mode, self::BINDING_MODES, true)) {
            $mode = 'auto';
        }
        $id = $value['template_id'] ?? 0;
        $id = (is_int($id) || (is_string($id) && preg_match('/^\d+$/D', $id) === 1)) ? (int) $id : 0;
        if ($id < 0) {
            $id = 0;
        }
        // auto/native 不保留生效的 template_id（避免三种表示混用）
        if ($mode !== 'template') {
            $id = 0;
        }

        return ['mode' => $mode, 'template_id' => $id];
    }

    /**
     * 旧 product_template（v1）只读适配：{mode:all|selected, ids[], lang, source} → v2 作用域。
     * 不写回、不发布、不改旧数据；legacy=true 让 resolve() 沿用历史并列语义（同级取最大模板 ID）。
     *
     * @param array<string,mixed> $legacy
     * @psalm-suppress PossiblyUnusedMethod 调用方在 admin/ 与 tests/（均不在 Psalm projectFiles 内）：
     *                                     v1 数据适配由模板列表/编辑器读取，本轮尚无核心调用点。
     */
    public static function legacyScope(array $legacy): array
    {
        $mode = ($legacy['mode'] ?? null) === 'all' ? 'all' : 'selected';
        $include = $mode === 'all'
            ? [['kind' => 'all', 'ids' => [], 'include_children' => false]]
            : [['kind' => 'item', 'ids' => self::normalizeIds($legacy['ids'] ?? null), 'include_children' => false]];
        if ($mode === 'selected' && $include[0]['ids'] === []) {
            // v1 的 selected + 空 ids 表示「未应用」，不是 all
            $include = [];
        }

        $scope = self::normalizeScope([
            'version' => self::VERSION,
            'content_type' => 'product',   // v1 只有产品详情模板
            'lang' => is_string($legacy['lang'] ?? null) ? $legacy['lang'] : '',
            'source' => ($legacy['source'] ?? null) === self::SOURCE_NATIVE ? self::SOURCE_NATIVE : self::SOURCE_CUSTOM,
            'priority' => 0,
            'include' => $include,
        ]);
        $scope['legacy'] = true;

        return $scope;
    }

    /** 内容类型对应的模板行类型；未知内容类型返回 ''（不可用）。 */
    public static function templateTypeFor(string $contentType): string
    {
        return self::TEMPLATE_TYPES[$contentType] ?? '';
    }

    /** 作用域是否可用于判定（类型与语言齐备且有 include）。 */
    public static function scopeIsUsable(array $scope): bool
    {
        return $scope['content_type'] !== ''
            && $scope['lang'] !== ''
            && in_array($scope['content_type'], self::CONTENT_TYPES, true)
            && $scope['include'] !== [];
    }

    /**
     * 归一化内容上下文。content_type 取 contents.type；categories 必须自带距离
     * （0=内容直属分类，1=其父级……），使具体度判定不需要 matcher 自己遍历分类树。
     *
     * @param array<string,mixed> $context
     * @return array{content_type:string,content_id:int,lang:string,channel_type:string,
     *               categories:list<array{id:int,distance:int}>,ancestors:list<int>}
     */
    public static function normalizeContext(array $context): array
    {
        $contentType = is_string($context['content_type'] ?? null) ? trim((string) $context['content_type']) : '';
        $contentId = (int) ($context['content_id'] ?? 0);
        $lang = is_string($context['lang'] ?? null) ? trim((string) $context['lang']) : '';
        $channelType = is_string($context['channel_type'] ?? null) ? trim((string) $context['channel_type']) : '';

        $categories = [];
        foreach (is_array($context['categories'] ?? null) ? $context['categories'] : [] as $row) {
            $id = is_array($row) ? (int) ($row['id'] ?? 0) : (int) $row;
            $distance = is_array($row) ? (int) ($row['distance'] ?? 0) : 0;
            if ($id <= 0 || $distance < 0) {
                continue;
            }
            $categories[$id] = ['id' => $id, 'distance' => min($distance, 99)];
        }

        return [
            'content_type' => $contentType,
            'content_id' => max(0, $contentId),
            'lang' => $lang,
            'channel_type' => $channelType,
            'categories' => array_values($categories),
            'ancestors' => array_values(array_map('intval', is_array($context['ancestors'] ?? null) ? $context['ancestors'] : [])),
        ];
    }

    /**
     * 纯判定：返回唯一的解析结果。渲染、后台影响预览、「为什么使用它」共用本结果。
     *
     * @param list<array<string,mixed>> $candidates 候选模板（含 id/type/status/lang/source/scope/legacy）
     * @param array<string,mixed> $context 内容上下文（见 normalizeContext）
     * @param array<string,mixed>|null $binding 内容级手动绑定（见 normalizeBinding）
     * @return array{template_id:int|null,source:string,rule_version:int,reason:string,
     *               specificity:array{level:int,detail:int},matched_include:list<int>,
     *               matched_exclude:list<int>,conflicts:list<array{template_id:int,dimension:string}>,template:array|null}
     * @psalm-suppress PossiblyUnusedMethod 调用方在 product.php / article.php / detail.php / admin/ 与 tests/
     *                                     （均不在 Psalm projectFiles 内）；本类是被它们消费的纯判定层。
     */
    public static function resolve(array $candidates, array $context, ?array $binding = null): array
    {
        $ctx = self::normalizeContext($context);
        $bind = self::normalizeBinding($binding);

        // 1) 内容级绑定是终止决策，先于任何规则
        if ($bind['mode'] === 'native') {
            return self::result(null, self::SOURCE_NATIVE, self::REASON_BINDING_NATIVE);
        }
        if ($bind['mode'] === 'template') {
            $pinned = self::findBoundTemplate($candidates, $bind['template_id'], $ctx);
            if ($pinned !== null) {
                return self::result($pinned['id'], self::SOURCE_CUSTOM, self::REASON_BINDING_TEMPLATE, 0, 0, [], [], [], $pinned);
            }
            // 失效/被删/类型语言不符：回退系统默认并留失效信号，绝不自动改选另一套
            return self::result(null, self::SOURCE_NATIVE, self::REASON_BINDING_INVALID);
        }

        // 2) 自动匹配
        $matched = [];
        foreach ($candidates as $candidate) {
            $evaluated = self::evaluateCandidate($candidate, $ctx);
            if ($evaluated !== null) {
                $matched[] = $evaluated;
            }
        }
        if ($matched === []) {
            return self::result(null, self::SOURCE_NATIVE, self::REASON_NO_CANDIDATE);
        }

        usort($matched, static function (array $left, array $right): int {
            if ($left['level'] !== $right['level']) {
                return $right['level'] <=> $left['level'];
            }
            if ($left['detail'] !== $right['detail']) {
                return $right['detail'] <=> $left['detail'];
            }
            if ($left['priority'] !== $right['priority']) {
                return $right['priority'] <=> $left['priority'];
            }
            return $right['id'] <=> $left['id'];   // 确定性兜底，不代表最终语义
        });

        $top = $matched[0];
        $tied = array_values(array_filter($matched, static function (array $row) use ($top): bool {
            return $row['level'] === $top['level']
                && $row['detail'] === $top['detail']
                && $row['priority'] === $top['priority'];
        }));

        $winner = $tied[0];
        $conflicts = [];
        $reason = $winner['reason'];

        if (count($tied) > 1) {
            $allLegacy = true;
            foreach ($tied as $row) {
                if ($row['legacy'] !== true) {
                    $allLegacy = false;
                    break;
                }
            }
            if (!$allLegacy) {
                // 新规则并列：确定性兜底 + 诊断清单，由发布前流程要求用户解决
                $reason = self::REASON_CONFLICTED;
                foreach ($tied as $row) {
                    $conflicts[] = ['template_id' => $row['id'], 'dimension' => self::LEGACY_DIMENSION];
                }
            }
        }

        return self::result(
            $winner['id'],
            self::SOURCE_CUSTOM,
            $reason,
            $winner['level'],
            $winner['detail'],
            $winner['matched_include'],
            $winner['matched_exclude'],
            $conflicts,
            $winner['template']
        );
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,mixed> $ctx
     * @return array<string,mixed>|null null=该候选不参与
     */
    private static function evaluateCandidate(array $candidate, array $ctx): ?array
    {
        $id = (int) ($candidate['id'] ?? 0);
        $type = is_string($candidate['type'] ?? null) ? (string) $candidate['type'] : '';
        $status = (int) ($candidate['status'] ?? 0);
        if ($id <= 0 || $status !== 1) {
            return null;
        }
        // 类型之间绝不互相匹配：模板行类型必须等于该内容类型对应的模板类型
        //（product ↔ product-detail、article ↔ article-detail，见 TEMPLATE_TYPES）
        $expectedType = self::templateTypeFor($ctx['content_type']);
        if ($expectedType === '' || $type !== $expectedType) {
            return null;
        }

        $scope = is_array($candidate['scope'] ?? null) ? $candidate['scope'] : self::normalizeScope(null);
        if (!self::scopeIsUsable($scope) || $scope['content_type'] !== $ctx['content_type']) {
            return null;
        }
        if ($scope['lang'] !== $ctx['lang']) {
            return null;   // 第一版不跨语言共享布局
        }

        $excluded = [];
        foreach ($scope['exclude'] as $index => $rule) {
            if (self::ruleMatches($rule, $ctx)) {
                $excluded[] = $index;
            }
        }
        if ($excluded !== []) {
            return null;   // 排除命中即排除，匹配顺序不抵消排除
        }

        $level = 0;
        $detail = 0;
        $hits = [];
        foreach ($scope['include'] as $index => $rule) {
            $hit = self::ruleDetail($rule, $ctx);
            if ($hit === null) {
                continue;
            }
            $hits[] = $index;
            if ($hit['level'] > $level || ($hit['level'] === $level && $hit['detail'] > $detail)) {
                $level = $hit['level'];
                $detail = $hit['detail'];
            }
        }
        if ($hits === []) {
            return null;
        }

        return [
            'id' => $id,
            'type' => $type,
            'level' => $level,
            'detail' => $detail,
            'priority' => (int) $scope['priority'],
            'legacy' => ($scope['legacy'] ?? false) === true,
            'matched_include' => $hits,
            'matched_exclude' => [],
            'reason' => self::reasonForLevel($level),
            'template' => $candidate,
        ];
    }

    /** 规则是否命中（用于 exclude；include 走 ruleDetail 以取具体度）。 */
    private static function ruleMatches(array $rule, array $ctx): bool
    {
        return self::ruleDetail($rule, $ctx) !== null;
    }

    /**
     * 规则命中的具体度；null=未命中。detail 越大越具体（分类越近越大）。
     *
     * @return array{level:int,detail:int}|null
     */
    private static function ruleDetail(array $rule, array $ctx): ?array
    {
        $kind = (string) ($rule['kind'] ?? '');
        $ids = is_array($rule['ids'] ?? null) ? $rule['ids'] : [];
        $children = ($rule['include_children'] ?? false) === true;

        if ($kind === 'all') {
            return ['level' => self::SPEC_ALL, 'detail' => 0];
        }
        if ($kind === 'item') {
            if ($ctx['content_id'] > 0 && in_array($ctx['content_id'], $ids, true)) {
                return ['level' => self::SPEC_ITEM, 'detail' => 0];
            }
            return null;
        }
        if ($kind === 'category') {
            $best = null;
            foreach ($ctx['categories'] as $row) {
                $distance = (int) $row['distance'];
                if (!in_array((int) $row['id'], $ids, true)) {
                    continue;
                }
                if (!$children && $distance !== 0) {
                    continue;   // 未开「包含子级」：只匹配内容直属分类
                }
                $detail = -$distance;   // 距离越近越具体
                if ($best === null || $detail > $best) {
                    $best = $detail;
                }
            }
            return $best === null ? null : ['level' => self::SPEC_CATEGORY, 'detail' => $best];
        }

        return null;
    }

    /** @param list<array<string,mixed>> $candidates */
    private static function findBoundTemplate(array $candidates, int $templateId, array $ctx): ?array
    {
        foreach ($candidates as $candidate) {
            if ((int) ($candidate['id'] ?? 0) !== $templateId) {
                continue;
            }
            if ((int) ($candidate['status'] ?? 0) !== 1) {
                return null;
            }
            if (is_string($candidate['type'] ?? null)) {
                $expectedType = self::templateTypeFor($ctx['content_type']);
                if ($expectedType === '' || (string) $candidate['type'] !== $expectedType) {
                    return null;   // 必须同类型
                }
            }
            $scope = is_array($candidate['scope'] ?? null) ? $candidate['scope'] : self::normalizeScope(null);
            if ($scope['lang'] !== '' && $scope['lang'] !== $ctx['lang']) {
                return null;   // 必须同语言
            }
            return $candidate;
        }

        return null;
    }

    /**
     * 规则数组归一化：丢掉非法规则而不是整段放行（fail-closed）。
     *
     * @return list<array{kind:string,ids:list<int>,include_children:bool}>
     */
    private static function normalizeRules(mixed $raw, bool $allowAll): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $rules = [];
        foreach (array_slice($raw, 0, self::MAX_RULES) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = is_string($item['kind'] ?? null) ? (string) $item['kind'] : '';
            if (!in_array($kind, self::KINDS, true)) {
                continue;
            }
            if ($kind === 'all') {
                if (!$allowAll) {
                    continue;   // exclude 不支持 all
                }
                $rules[] = ['kind' => 'all', 'ids' => [], 'include_children' => false];
                continue;
            }
            $ids = self::normalizeIds($item['ids'] ?? null);
            if ($ids === []) {
                continue;   // 空 id 的 item/category 规则无意义，丢弃而不是当成 all
            }
            $rules[] = [
                'kind' => $kind,
                'ids' => $ids,
                'include_children' => ($item['include_children'] ?? false) === true,
            ];
        }

        return $rules;
    }

    /** @return list<int> 正整数字符串 id（上限 500，去重，保持首次出现顺序） */
    private static function normalizeIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach (array_slice($raw, 0, self::MAX_IDS_PER_RULE) as $id) {
            if (!is_int($id) && !is_string($id)) {
                continue;
            }
            if (preg_match('/^[1-9][0-9]{0,9}$/', (string) $id) !== 1) {
                continue;
            }
            $value = (int) $id;
            if (!in_array($value, $ids, true)) {
                $ids[] = $value;
            }
        }

        return $ids;
    }

    private static function reasonForLevel(int $level): string
    {
        if ($level === self::SPEC_ITEM) {
            return self::REASON_SPECIFIC_ITEM;
        }
        if ($level === self::SPEC_CATEGORY) {
            return self::REASON_SPECIFIC_CATEGORY;
        }
        return self::REASON_TYPE_ALL;
    }

    /**
     * @param list<int> $matchedInclude
     * @param list<int> $matchedExclude
     * @param list<array{template_id:int,dimension:string}> $conflicts
     * @param array<string,mixed>|null $template
     * @return array<string,mixed>
     */
    private static function result(
        ?int $templateId,
        string $source,
        string $reason,
        int $level = 0,
        int $detail = 0,
        array $matchedInclude = [],
        array $matchedExclude = [],
        array $conflicts = [],
        ?array $template = null
    ): array {
        return [
            'template_id' => $templateId,
            'source' => $source,
            'rule_version' => self::VERSION,
            'reason' => $reason,
            'specificity' => ['level' => $level, 'detail' => $detail],
            'matched_include' => $matchedInclude,
            'matched_exclude' => $matchedExclude,
            'conflicts' => $conflicts,
            'template' => $template,
        ];
    }
}
