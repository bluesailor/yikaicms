<?php
/**
 * 详情模板「候选 + 内容上下文」准备层（模型层唯一取数处）。
 *
 * DetailTemplateResolver 是纯判定，不查库；本类负责一次性备好：
 * - 候选模板（读取已发布模板，取出并归一化其条件作用域；v1 的 product_template 经
 *   legacyScope() 只读适配，进入同一判定路径，避免两套排序分叉）
 * - 内容上下文（把「内容所属分类/栏目 + 祖先链 + 距离」算好，使 matcher 不必遍历分类树）
 *
 * 分类链遍历带访问去重、深度与规模上限，循环数据不会死循环。
 */

declare(strict_types=1);

final class DetailTemplateProvider
{
    public const MAX_CATEGORY_DEPTH = 12;
    public const MAX_CATEGORY_NODES = 50;

    /**
     * 模板行类型（+ 作用域声明的 content_type）→ 判定用内容类型。
     *
     * v1.26 Single 扩自定义模型：article-detail 的作用域可声明**已注册**模型 key，
     * 模板之间靠该维度区分。这里是 IO 层唯一的注册核对点（resolver 纯层只验形态）；
     * 未注册/缺失/损坏一律回落 'article'，非详情模板类型返回 ''（调用方 fail-closed）。
     */
    public static function contentTypeForTemplate(string $templateType, mixed $scopeContentType = null): string
    {
        if ($templateType === 'product-detail') {
            return 'product';
        }
        if ($templateType !== 'article-detail') {
            return '';
        }
        $declared = is_string($scopeContentType) ? trim($scopeContentType) : '';
        if ($declared !== '' && $declared !== 'article') {
            try {
                if (db()->tableExists('content_models')
                    && in_array($declared, contentModelModel()->keys(), true)) {
                    return $declared;
                }
            } catch (Throwable) {
                // 表未建：按 article 处理
            }
        }
        return 'article';
    }

    /**
     * 候选模板列表（含已归一化 scope 与 published_data，供渲染直接使用）。
     *
     * @return list<array<string,mixed>>
     */
    public static function candidates(string $contentType): array
    {
        $templateType = DetailTemplateResolver::templateTypeFor($contentType);
        if ($templateType === '') {
            return [];
        }

        $rows = [];
        foreach (bloxTemplateModel()->publishedDetailTemplates($templateType) as $row) {
            try {
                $document = BloxDocumentPipeline::decode((string) ($row['published_data'] ?? ''));
            } catch (Throwable) {
                continue;   // 文档损坏的模板不参与判定（渲染阶段本就有安全回退）
            }
            $settings = is_array($document['settings'] ?? null) ? $document['settings'] : [];

            $scope = self::scopeFromSettings($contentType, $settings);
            if ($scope === null) {
                continue;   // 没有条件声明的模板不参与自动匹配（不等于 all）
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'type' => (string) $row['type'],
                'status' => (int) $row['status'],
                'lang' => (string) $scope['lang'],
                'source' => (string) $scope['source'],
                'priority' => (int) $scope['priority'],
                'scope' => $scope,
                'published_data' => (string) ($row['published_data'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * 文档设置里的有效条件（渲染候选、发布检查共用）：v2 优先；只有 v1 产品规则时只读适配。
     *
     * @param array<string,mixed> $settings
     * @return array<string,mixed>|null null=没有条件声明，不参与自动匹配（不等于 all）
     */
    public static function scopeFromSettings(string $contentType, array $settings): ?array
    {
        if (array_key_exists('detail_template', $settings)) {
            return DetailTemplateResolver::normalizeScope($settings['detail_template']);
        }
        if ($contentType === 'product' && array_key_exists('product_template', $settings)) {
            // 旧规则只读适配：不写回、不发布，仅参与同一判定
            return DetailTemplateResolver::legacyScope(is_array($settings['product_template']) ? $settings['product_template'] : []);
        }
        return null;
    }

    /**
     * 条件面板的下拉选项补齐：规则里已引用、但不在预览列表（前 100 条）里的内容/分类，
     * 按草稿语言批量补进选项并标记 referenced；真正不存在或语言不同的 ID 不补，由面板显示为"缺失"。
     * 否则重开模板时这些目标在多选框里"看起来没选"，用户会误以为规则丢了。
     *
     * @param list<array{id:int,name:string}> $options 已有选项
     * @param array<string,mixed>|null $scope 文档里的有效条件
     * @return list<array<string,mixed>>
     * @psalm-suppress PossiblyUnusedMethod 调用方是 admin/blox_editor.php 模板里输出给编辑器的选项 JSON。
     */
    public static function optionsWithReferences(string $contentType, string $kind, array $options, ?array $scope, string $lang): array
    {
        if (!DetailTemplateResolver::isContentType($contentType)
            || !is_array($scope) || $lang === '' || !in_array($kind, ['item', 'category'], true)) {
            return $options;
        }
        $listed = [];
        foreach ($options as $option) {
            $listed[(int) ($option['id'] ?? 0)] = true;
        }
        $wanted = [];
        foreach (['include', 'exclude'] as $side) {
            foreach (is_array($scope[$side] ?? null) ? $scope[$side] : [] as $rule) {
                if (($rule['kind'] ?? '') !== $kind) {
                    continue;
                }
                foreach (is_array($rule['ids'] ?? null) ? $rule['ids'] : [] as $id) {
                    if ((int) $id > 0 && !isset($listed[(int) $id])) {
                        $wanted[(int) $id] = true;
                    }
                }
            }
        }
        if ($wanted === []) {
            return $options;
        }
        $ids = array_slice(array_keys($wanted), 0, 1000);
        $in = implode(',', array_fill(0, count($ids), '?'));
        if ($kind === 'item') {
            // 类型过 MODEL_KEY_PATTERN 形态校验（无引号字符），可安全内插（v1.26 模型支持）
            $sql = $contentType === 'product'
                ? 'SELECT id, title AS name FROM ' . DB_PREFIX . 'products WHERE lang = ? AND deleted_at IS NULL AND id IN (' . $in . ')'
                : 'SELECT id, title AS name FROM ' . DB_PREFIX . "contents WHERE lang = ? AND type = '" . $contentType . "' AND deleted_at IS NULL AND id IN (" . $in . ')';
        } else {
            $sql = $contentType === 'product'
                ? 'SELECT id, name FROM ' . DB_PREFIX . 'product_categories WHERE lang = ? AND id IN (' . $in . ')'
                : 'SELECT id, name FROM ' . DB_PREFIX . 'channels WHERE lang = ? AND id IN (' . $in . ')';
        }
        foreach (db()->fetchAll($sql, array_merge([$lang], $ids)) as $row) {
            $options[] = ['id' => (int) $row['id'], 'name' => (string) ($row['name'] ?? ''), 'referenced' => true];
        }
        return $options;
    }

    /**
     * 诊断用候选（TASK-008，只读）：在已发布候选基础上**移除该模板自己的旧发布版本**，
     * 再把待诊断草稿作为**同一个 id**、status=1 的候选注入——语义是"如果现在发布它"。
     * 为什么不新建匹配算法：注入后仍交给同一个 `DetailTemplateResolver::resolve()` 排序与判定，
     * 候选形状与 `candidates()` 完全一致，不复制任何命中逻辑。
     *
     * 草稿不可用（空 include / 类型语言不齐）时**不注入**，由调用方如实告知"当前条件不参与匹配"，
     * 而不是让它以空条件参与后装作命中。
     *
     * @param array<string,mixed> $draftScope 已严格校验的 v2 作用域（或等价的归一化输入）
     * @return list<array<string,mixed>>
     */
    public static function candidatesWithDraft(string $contentType, int $templateId, array $draftScope): array
    {
        return self::injectDraft(self::candidates($contentType), $contentType, $templateId, $draftScope);
    }

    /**
     * 候选注入的**纯函数**部分（便于单测，不查库）：
     * 移除同 id 的旧发布候选，草稿可用时以同 id、status=1 追加。
     *
     * @param list<array<string,mixed>> $candidates 既有候选（通常来自 candidates()）
     * @return list<array<string,mixed>>
     */
    public static function injectDraft(array $candidates, string $contentType, int $templateId, array $draftScope): array
    {
        $kept = [];
        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if ($templateId > 0 && (int) ($candidate['id'] ?? 0) === $templateId) {
                continue;   // 自身旧发布版本退出：诊断的是"发布后的它"，不能与自己并列
            }
            $kept[] = $candidate;
        }

        $templateType = DetailTemplateResolver::templateTypeFor($contentType);
        $scope = DetailTemplateResolver::normalizeScope($draftScope);
        // 未迁移的 v1 草稿（发布检查会传入）保留 v1 并列语义，不能因注入而变成"新并列"
        $scope['legacy'] = ($draftScope['legacy'] ?? false) === true;
        if ($templateId <= 0 || $templateType === '' || $scope['content_type'] !== $contentType
            || !DetailTemplateResolver::scopeIsUsable($scope)) {
            return $kept;
        }

        $kept[] = [
            'id' => $templateId,
            'type' => $templateType,
            'status' => 1,
            'lang' => (string) $scope['lang'],
            'source' => (string) $scope['source'],
            'priority' => (int) $scope['priority'],
            'scope' => $scope,
            'published_data' => '',
        ];

        return $kept;
    }

    /**
     * 草稿能否参与匹配的前置条件（纯函数；用 resolver 的公共工具，不是命中判断）。
     *
     * @param array<string,mixed> $scope 已归一化的草稿作用域
     * @param array<string,mixed> $context 已归一化的内容上下文
     * @return string 'ok' | 'scope_unusable' | 'lang_mismatch'
     */
    public static function preconditionFor(array $scope, string $contentType, array $context): string
    {
        $usable = $scope['content_type'] === $contentType && DetailTemplateResolver::scopeIsUsable($scope);
        if (!$usable) {
            return 'scope_unusable';
        }
        // lang=''（v1.26 全语言）对任何内容语言都是前置满足
        return (string) $scope['lang'] === '' || (string) $scope['lang'] === (string) ($context['lang'] ?? '')
            ? 'ok' : 'lang_mismatch';
    }

    /**
     * 把 resolve() 的结果命名为可读判定（纯函数，只做比较，不重新判定）。
     *
     * @param array<string,mixed> $result DetailTemplateResolver::resolve() 的原始输出
     */
    public static function verdictFor(string $precondition, int $templateId, array $result): string
    {
        if ($precondition !== 'ok') {
            return 'not_considered';
        }
        // 本模板是并列成员：无论 ID 兜底选中了谁，业务上都是未解决的冲突
        foreach ($result['conflicts'] ?? [] as $conflict) {
            if ((int) ($conflict['template_id'] ?? 0) === $templateId) {
                return 'conflicted';
            }
        }
        $decidedId = (int) ($result['decided_template_id'] ?? ($result['template_id'] ?? 0));
        if ($decidedId === 0) {
            return 'no_match';
        }
        if ($decidedId !== $templateId) {
            return 'lost';
        }
        if ((string) ($result['reason'] ?? '') === DetailTemplateResolver::REASON_TEMPLATE_NATIVE) {
            return 'native';   // 本模板决定了这条内容，但它声明输出主题默认
        }
        return (string) ($result['reason'] ?? '') === DetailTemplateResolver::REASON_CONFLICTED ? 'conflicted' : 'won';
    }

    /**
     * 草稿**单独**面对这条内容时是否命中（纯函数）：只把草稿一个候选交给同一个 resolve()；
     * 未命中时再去掉排除规则问一次，区分"被排除"与"纳入条件不包含它"。不另写匹配。
     *
     * @param array<string,mixed> $draftScope
     * @param array<string,mixed> $context
     * @return string 'matched' | 'excluded' | 'not_included' | 'not_considered'
     */
    public static function draftMatchFor(string $contentType, int $templateId, array $draftScope, array $context, string $precondition): string
    {
        if ($precondition !== 'ok') {
            return 'not_considered';
        }
        $alone = DetailTemplateResolver::resolve(self::injectDraft([], $contentType, $templateId, $draftScope), $context);
        if ((int) ($alone['decided_template_id'] ?? 0) === $templateId) {
            return 'matched';
        }
        $withoutExclude = $draftScope;
        $withoutExclude['exclude'] = [];
        $open = DetailTemplateResolver::resolve(self::injectDraft([], $contentType, $templateId, $withoutExclude), $context);
        return (int) ($open['decided_template_id'] ?? 0) === $templateId ? 'excluded' : 'not_included';
    }

    /**
     * 草稿规则引用了但当前已不存在（已删除，或语言与草稿不同）的内容 / 分类 ID。
     * 只报告事实、不改写规则：缺失引用在 resolver 里只是"匹配不到"，诊断需要把原因说出来。
     * 每类最多两次批量查询（按 500 分块），不逐条查。
     *
     * @param array<string,mixed> $draftScope
     * @return array{items:list<int>,categories:list<int>}
     */
    public static function missingReferences(string $contentType, array $draftScope): array
    {
        $scope = DetailTemplateResolver::normalizeScope($draftScope);
        $wanted = ['item' => [], 'category' => []];
        foreach (['include', 'exclude'] as $side) {
            foreach ($scope[$side] as $rule) {
                if (!isset($wanted[$rule['kind']])) {
                    continue;
                }
                foreach ($rule['ids'] as $id) {
                    $wanted[$rule['kind']][(int) $id] = true;
                }
            }
        }
        // lang=''（全语言）：目标存在性无法按单一语言核对，直接不报缺失（宁少报不误报）
        if ($scope['content_type'] !== $contentType || $scope['lang'] === '') {
            return ['items' => [], 'categories' => []];
        }
        // 非产品按内容真实类型过滤（v1.26：article 或已注册模型 key——都过了
        // MODEL_KEY_PATTERN 形态校验（无引号字符），可安全内插）
        $itemSql = $contentType === 'product'
            ? 'SELECT id FROM ' . DB_PREFIX . 'products WHERE lang = ? AND deleted_at IS NULL AND id IN (%s)'
            : 'SELECT id FROM ' . DB_PREFIX . "contents WHERE lang = ? AND type = '" . $contentType . "' AND deleted_at IS NULL AND id IN (%s)";
        $categorySql = $contentType === 'product'
            ? 'SELECT id FROM ' . DB_PREFIX . 'product_categories WHERE lang = ? AND id IN (%s)'
            : 'SELECT id FROM ' . DB_PREFIX . 'channels WHERE lang = ? AND id IN (%s)';

        return [
            'items' => self::absentIds(array_keys($wanted['item']), $itemSql, (string) $scope['lang']),
            'categories' => self::absentIds(array_keys($wanted['category']), $categorySql, (string) $scope['lang']),
        ];
    }

    /**
     * @param list<int> $ids
     * @return list<int>
     */
    private static function absentIds(array $ids, string $sqlTemplate, string $lang): array
    {
        if ($ids === []) {
            return [];
        }
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $sql = sprintf($sqlTemplate, implode(',', array_fill(0, count($chunk), '?')));
            foreach (db()->fetchAll($sql, array_merge([$lang], $chunk)) as $row) {
                $found[(int) ($row['id'] ?? 0)] = true;
            }
        }
        return array_values(array_filter($ids, static fn (int $id): bool => !isset($found[$id])));
    }

    /**
     * 单条真实内容的只读诊断（TASK-008）。
     *
     * 结果里的 `winner` / `conflicts` 一律来自 `DetailTemplateResolver::resolve()` 的原样输出；
     * 本地只额外做三件事：① 用 resolver 的公共工具判断草稿的**前置条件**（是否参与匹配）；
     * ② 把 winner 与注入 id 的比较命名为 verdict；③ 回显上下文里已有的字段（不含标题正文）。
     *
     * @param array<string,mixed> $content 产品行或内容行
     * @param array<string,mixed> $draftScope
     * @return array<string,mixed>
     */
    public static function diagnoseFor(string $contentType, array $content, int $templateId, array $draftScope): array
    {
        $context = self::context($contentType, $content);
        $scope = DetailTemplateResolver::normalizeScope($draftScope);
        $result = DetailTemplateResolver::resolve(
            self::candidatesWithDraft($contentType, $templateId, $draftScope),
            $context
        );

        // 前置条件：决定了这份草稿有没有参与匹配（不是命中判断，命中判断只属于 resolve()）
        $precondition = self::preconditionFor($scope, $contentType, $context);
        $winnerId = isset($result['template_id']) ? (int) $result['template_id'] : 0;
        $conflicts = $result['conflicts'] ?? [];
        $selfInConflict = in_array($templateId, array_map('intval', array_column($conflicts, 'template_id')), true);

        return [
            'template_id' => $templateId,
            'template_type' => DetailTemplateResolver::templateTypeFor($contentType),
            'content_type' => $contentType,
            'content' => [
                'id' => (int) ($context['content_id'] ?? 0),
                'lang' => (string) ($context['lang'] ?? ''),
                'categories' => $context['categories'] ?? [],
                // 前台只展示已发布内容：未发布时结果表示"发布后"的判定
                'published' => (int) ($content['status'] ?? 0) === 1,
            ],
            'draft' => [
                'injected' => $precondition === 'ok',
                'precondition' => $precondition,
                'lang' => (string) $scope['lang'],
                'priority' => (int) $scope['priority'],
                'source' => (string) $scope['source'],
                'match' => self::draftMatchFor($contentType, $templateId, $draftScope, $context, $precondition),
                'missing_references' => self::missingReferences($contentType, $draftScope),
            ],
            'winner' => [
                'template_id' => $winnerId > 0 ? $winnerId : null,
                'decided_template_id' => isset($result['decided_template_id']) ? (int) $result['decided_template_id'] : null,
                'source' => (string) ($result['source'] ?? ''),
                'reason' => (string) ($result['reason'] ?? ''),
                'specificity' => $result['specificity'] ?? ['level' => 0, 'detail' => 0],
            ],
            'conflicts' => $conflicts,
            // 并列发生在其它模板之间：本模板不受影响，但这条内容实际仍靠模板 ID 兜底
            'others_conflicted' => $conflicts !== [] && !$selfInConflict,
            'verdict' => self::verdictFor($precondition, $templateId, $result),
        ];
    }

    /**
     * 内容上下文。content_type 取 contents.type（product/article）；
     * categories 带「与内容的距离」（0=直属），供判定具体度。
     *
     * @param array<string,mixed> $content 产品行或内容行
     * @return array<string,mixed>
     */
    public static function context(string $contentType, array $content): array
    {
        $lang = is_string($content['lang'] ?? null) && (string) $content['lang'] !== ''
            ? (string) $content['lang']
            : siteLang();

        if ($contentType === 'product') {
            $categoryId = (int) ($content['category_id'] ?? 0);
            return [
                'content_type' => 'product',
                'content_id' => (int) ($content['id'] ?? 0),
                'lang' => $lang,
                'channel_type' => 'product',
                'categories' => self::ancestorChain($categoryId, true),
                'ancestors' => array_column(self::ancestorChain($categoryId, true), 'id'),
            ];
        }

        $channelId = (int) ($content['channel_id'] ?? 0);
        $chain = self::ancestorChain($channelId, false);
        return [
            'content_type' => $contentType,
            'content_id' => (int) ($content['id'] ?? 0),
            'lang' => $lang,
            'channel_type' => (string) ($content['channel_type'] ?? ''),
            'categories' => $chain,
            'ancestors' => array_column($chain, 'id'),
        ];
    }

    /**
     * 便捷入口：渲染 / 后台预览 / 「为什么使用它」共用同一结果。
     *
     * @param array<string,mixed> $content
     * @param array<string,mixed>|null $binding 内容级手动绑定
     * @return array<string,mixed> 见 DetailTemplateResolver::resolve()
     */
    public static function resolveFor(string $contentType, array $content, ?array $binding = null): array
    {
        return DetailTemplateResolver::resolve(
            self::candidates($contentType),
            self::context($contentType, $content),
            $binding
        );
    }

    /**
     * 分类/栏目祖先链（含自身，distance 从 0 起）。
     * 带访问去重（防循环）、深度上限与规模上限；查不到的行直接停止。
     *
     * @return list<array{id:int,distance:int}>
     */
    private static function ancestorChain(int $id, bool $isProduct): array
    {
        if ($id <= 0) {
            return [];   // 未分类：不伪造分类条件
        }

        $chain = [];
        $seen = [];
        $cursor = $id;
        $distance = 0;

        while ($cursor > 0 && $distance < self::MAX_CATEGORY_DEPTH && count($chain) < self::MAX_CATEGORY_NODES) {
            if (isset($seen[$cursor])) {
                break;   // 循环数据保护
            }
            $seen[$cursor] = true;

            $row = $isProduct ? getProductCategory($cursor) : getChannel($cursor);
            if (!is_array($row)) {
                if ($distance === 0) {
                    return [];   // 直属分类不可用时不把条件建立在猜测上
                }
                break;
            }

            $chain[] = ['id' => $cursor, 'distance' => $distance];
            $cursor = (int) ($row['parent_id'] ?? 0);
            $distance++;
        }

        return $chain;
    }
}
