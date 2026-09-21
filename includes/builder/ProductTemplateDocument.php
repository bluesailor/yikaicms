<?php
/** Product detail templates keep bindings separate from preview records. */
declare(strict_types=1);

final class ProductTemplateDocument
{
    private static ?array $product = null;

    /**
     * 预览态标记：画布/样本预览下，动态元素不得触发真实业务动作（如询价真实提交）。
     * 由预览入口显式打开，前台发布渲染不设此标记。
     */
    private static bool $preview = false;

    public static function currentProduct(): ?array
    {
        return self::$product;
    }

    public static function markPreview(bool $preview = true): void
    {
        self::$preview = $preview;
    }

    public static function isPreview(): bool
    {
        // 前台「主题默认预览」走常量（product.php 已在那条路径上禁用交互），
        // 画布预览走标记：两者都是预览，都不许落真实数据。
        return self::$preview || (defined('YK_PRODUCT_NATIVE_PREVIEW') && YK_PRODUCT_NATIVE_PREVIEW === true);
    }

    public static function withProduct(array $product, callable $render): string
    {
        $previous = self::$product;
        self::$product = $product;
        try {
            return $render();
        } finally {
            self::$product = $previous;
        }
    }

    /**
     * 把控制器输出归一为渲染上下文（单一契约处）。
     *
     * 兼容两种入参：ProductDetailController::prepare() 的返回值（含 productImages/specs/
     * prevProduct/nextProduct/relatedProducts），或产品行本身（旧调用方式）。
     * 相册与参数只存在于控制器结果里，产品行本身没有——这正是此前模板拿不到它们的原因。
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function normalizeContext(array $input): array
    {
        $product = is_array($input['product'] ?? null) ? $input['product'] : $input;

        $images = [];
        foreach (is_array($input['productImages'] ?? null) ? $input['productImages'] : [] as $image) {
            if (is_string($image) && $image !== '') {
                $images[] = $image;
            }
        }

        $specs = [];
        foreach (is_array($input['specs'] ?? null) ? $input['specs'] : [] as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $name = trim((string) ($spec['name'] ?? ''));
            $value = trim((string) ($spec['value'] ?? ''));
            if ($name === '' && $value === '') {
                continue;   // 空参数不上前台（与原生规格表同规则）
            }
            $specs[] = ['name' => $name, 'value' => $value];
        }

        $related = [];
        foreach (is_array($input['relatedProducts'] ?? null) ? $input['relatedProducts'] : [] as $row) {
            if (is_array($row) && trim((string) ($row['title'] ?? '')) !== '') {
                $related[] = $row;
            }
        }

        return [
            'id' => (int) ($product['id'] ?? 0),
            'title' => (string) ($product['title'] ?? ''),
            // slug/分类 slug 透传：productPrettyUrl 靠它们出静态化地址，缺了就退回 /product/{id}.html
            'slug' => (string) ($product['slug'] ?? ''),
            'category_slug' => (string) ($product['category_slug'] ?? ''),
            'subtitle' => (string) ($product['subtitle'] ?? ''),
            'summary' => (string) ($product['summary'] ?? ''),
            'content' => (string) ($product['content'] ?? ''),
            'cover' => (string) ($product['cover'] ?? ''),
            'model' => (string) ($product['model'] ?? ''),
            'price' => (string) ($product['price'] ?? ''),
            'market_price' => (string) ($product['market_price'] ?? ''),
            'tags' => (string) ($product['tags'] ?? ''),
            'lang' => (string) ($product['lang'] ?? ''),
            'category_id' => (int) ($product['category_id'] ?? 0),
            'images' => $images,
            'specs' => $specs,
            'category' => is_array($input['productCategory'] ?? null) ? $input['productCategory'] : null,
            'prev' => is_array($input['prevProduct'] ?? null) ? $input['prevProduct'] : null,
            'next' => is_array($input['nextProduct'] ?? null) ? $input['nextProduct'] : null,
            'related' => $related,
        ];
    }

    /** Missing or malformed scope never means all products. */
    public static function normalizeScope(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $mode = in_array($value['mode'] ?? null, ['all', 'selected'], true) ? $value['mode'] : 'selected';
        $ids = [];
        foreach (is_array($value['ids'] ?? null) ? array_slice($value['ids'], 0, 500) : [] as $id) {
            if ((is_int($id) || is_string($id)) && preg_match('/^[1-9][0-9]{0,9}$/', (string) $id)) {
                $ids[] = (int) $id;
            }
        }
        $language = is_string($value['lang'] ?? null) ? $value['lang'] : '';
        $scope = ['mode' => $mode, 'ids' => array_values(array_unique($ids)), 'lang' => $language];
        if (($value['source'] ?? null) === 'native') $scope['source'] = 'native';
        return $scope;
    }

    /**
     * 该文档是否已经使用 v2 详情条件（产品侧的权威契约）。
     *
     * TASK-002-R02：渲染侧（DetailTemplateProvider）v2 优先，但后台写入此前只改 v1，
     * 于是"切换 native / 改范围"对 v2 文档不生效。读写都必须先看这里。
     *
     * @param array<string,mixed> $document
     */
    public static function hasDetailTemplate(array $document): bool
    {
        $raw = $document['settings']['detail_template'] ?? null;
        return is_array($raw) && (int) ($raw['version'] ?? 0) === DetailTemplateResolver::VERSION;
    }

    /**
     * 权威作用域（v1 形态视图）：v2 存在时以 v2 为准，否则读 v1。
     *
     * 渲染、后台展示、后台写入三处都必须经过它，避免"渲染看 v2、后台改 v1"的分叉。
     * v2 的 category 规则无法用 v1 形态表达，这里只映射 item/all（展示用途），写入时另见 applyUiScope()。
     *
     * @param array<string,mixed> $document
     * @return array{mode:string,ids:list<int>,lang:string,source?:string}
     */
    public static function authoritativeScope(array $document): array
    {
        if (!self::hasDetailTemplate($document)) {
            return self::normalizeScope($document['settings']['product_template'] ?? null);
        }

        $v2 = DetailTemplateResolver::normalizeScope($document['settings']['detail_template']);
        $mode = 'selected';
        $ids = [];
        foreach ($v2['include'] as $rule) {
            $kind = (string) ($rule['kind'] ?? '');
            if ($kind === 'all') {
                $mode = 'all';
                $ids = [];
                break;
            }
            if ($kind === 'item') {
                foreach ((array) ($rule['ids'] ?? []) as $id) {
                    $ids[] = (int) $id;
                }
            }
        }

        $scope = ['mode' => $mode, 'ids' => array_values(array_unique($ids)), 'lang' => (string) $v2['lang']];
        if ($v2['source'] === DetailTemplateResolver::SOURCE_NATIVE) $scope['source'] = 'native';
        return $scope;
    }

    /**
     * 编辑器 boot 用的 v1 形态作用域（外审 P1-3，结构借鉴 GLM 整改分支）：
     * authoritativeScope + 语言回填收进可单测的一处。显式存储的 lang=''=全部语言
     * 必须原样保留（与文章 boot 同口径，v2 看 detail_template、v1 看 product_template）；
     * 只有「从未存过 lang」或存了非法语言才回退预览语言——否则跨语言共享的产品模板
     * 打开编辑器保存一次就被静默钉死在当前预览语言上。
     *
     * @param array<string,mixed> $document
     * @param array<string,string>|null $knownLanguages 站点可用语言表（测试注入；null=按 availableLanguages 取）
     * @return array{mode:string,ids:list<int>,lang:string,source?:string}
     * @psalm-suppress PossiblyUnusedMethod 调用方在 admin/ 与 tests/（均不在 Psalm projectFiles 内）
     */
    public static function scopeForEditorBoot(array $document, string $previewLanguage, ?array $knownLanguages = null): array
    {
        $scope = self::authoritativeScope($document);
        $stored = self::hasDetailTemplate($document)
            ? (is_array($document['settings']['detail_template'] ?? null) ? $document['settings']['detail_template'] : [])
            : (is_array($document['settings']['product_template'] ?? null) ? $document['settings']['product_template'] : []);
        $langExplicit = array_key_exists('lang', $stored) && is_string($stored['lang']);
        $lang = (string) ($scope['lang'] ?? '');
        // 拿不到可用语言表（测试环境不加载 functions.php）时保留非空值，不误伤合法语言
        $languages = $knownLanguages ?? (function_exists('availableLanguages') ? availableLanguages() : null);
        $langKnown = $lang === '' || $languages === null || isset($languages[$lang]);
        if (($lang === '' && !$langExplicit) || !$langKnown) {
            $scope['lang'] = $previewLanguage;
        }
        return $scope;
    }

    /**
     * 把后台 UI 的 v1 形态作用域写回文档：v2 存在时写进 v2（保留其中的 category 规则），
     * 否则维持既有 v1 行为（历史模板不被改写）。
     *
     * **缺省输入不等于"用户清空"**（TASK-002-R03）：纯 v2 文档没有 v1 镜像，若把
     * `settings.product_template ?? []` 当作用户意图写回，会把合法的 v2 include/lang 覆盖成空值，
     * 保存后模板直接失去匹配能力。因此 null / 空数组 / 缺 lang（无法匹配任何内容）一律原样返回。
     *
     * @param array<string,mixed> $document
     * @param mixed $uiScope 后台表单来源（可能脏数据或缺失，经 normalizeScope）
     * @return array<string,mixed>
     */
    public static function applyUiScope(array $document, mixed $uiScope): array
    {
        if (!is_array($document['settings'] ?? null)) $document['settings'] = [];
        if (!is_array($uiScope) || $uiScope === []) return $document;
        $scope = self::normalizeScope($uiScope);
        if ($scope['lang'] === '') return $document;   // 没有语言的作用域匹配不到任何内容，不写

        if (!self::hasDetailTemplate($document)) {
            $document['settings']['product_template'] = $scope;
            return $document;
        }

        $v2 = DetailTemplateResolver::normalizeScope($document['settings']['detail_template']);
        // 只替换 item/all 规则，保留 category 等 UI 表达不了的规则
        $kept = [];
        foreach ($v2['include'] as $rule) {
            if (in_array((string) ($rule['kind'] ?? ''), ['item', 'all'], true)) continue;
            $kept[] = $rule;
        }
        $include = $scope['mode'] === 'all'
            ? [['kind' => 'all', 'ids' => [], 'include_children' => false]]
            : [['kind' => 'item', 'ids' => array_values(array_map('intval', $scope['ids'])), 'include_children' => false]];
        $v2['include'] = array_merge($include, $kept);
        $v2['lang'] = $scope['lang'];
        $v2['source'] = ($scope['source'] ?? '') === 'native'
            ? DetailTemplateResolver::SOURCE_NATIVE
            : DetailTemplateResolver::SOURCE_CUSTOM;
        $document['settings']['detail_template'] = $v2;
        // v1 镜像同步，避免后台/旧读取方看到过期值；但没有 v1 的文档不主动创建
        if (isset($document['settings']['product_template'])) {
            $document['settings']['product_template'] = $scope;
        }
        return $document;
    }

    /**
     * v1 语义判定：命中模板若声明 source=native，表示这一条规则改用系统默认。
     *
     * v2 文档同样适用（source 存在 v2 里）；保留该入口是因为测试与历史调用仍依赖。
     *
     * @psalm-suppress PossiblyUnusedMethod v1 只读适配，测试覆盖中
     */
    public static function usesNative(?array $template): bool
    {
        if ($template === null) return true;
        $document = BloxDocumentPipeline::decode((string) ($template['published_data'] ?? ''));
        return (self::authoritativeScope($document)['source'] ?? '') === 'native';
    }

    /**
     * Switch only the published output source, retaining its layout and scope.
     *
     * 只改 source：v2 文档直接写 v2（作用域原样保留，作用域不可识别时也仍能切默认），
     * 没有 v2 的历史模板维持原 v1 行为。不经过 applyUiScope——切默认不是"提交范围"。
     */
    public static function changeSource(string $json, string $source): string
    {
        if (!in_array($source, ['native', 'custom'], true)) throw new InvalidArgumentException(__('blox_bad_request'));
        $document = BloxDocumentPipeline::decode($json);

        if (self::hasDetailTemplate($document)) {
            $v2 = $document['settings']['detail_template'];
            $v2['source'] = $source === 'native'
                ? DetailTemplateResolver::SOURCE_NATIVE
                : DetailTemplateResolver::SOURCE_CUSTOM;
            $document['settings']['detail_template'] = $v2;
            // 有 v1 镜像则同步，没有就不创建
            if (isset($document['settings']['product_template'])) {
                $mirror = self::normalizeScope($document['settings']['product_template']);
                unset($mirror['source']);
                if ($source === 'native') $mirror['source'] = 'native';
                $document['settings']['product_template'] = $mirror;
            }
            return json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        $scope = self::normalizeScope($document['settings']['product_template'] ?? null);
        unset($scope['source']);
        if ($source === 'native') $scope['source'] = 'native';
        $document['settings']['product_template'] = $scope;
        return json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function matches(array $scope, array $product): bool
    {
        $scope = self::normalizeScope($scope);
        return $scope['lang'] !== '' && $scope['lang'] === (string) ($product['lang'] ?? '')
            && ($scope['mode'] === 'all' || in_array((int) ($product['id'] ?? 0), $scope['ids'], true));
    }

    /**
     * v1 排序：指定产品优先，同范围取较大模板 ID。
     *
     * 发布渲染自本轮起走统一判定入口（DetailTemplateProvider::resolveFor），不再走这里；
     * 保留作为 v1 规则的只读适配与语义参照，测试锁定其行为。
     *
     * @psalm-suppress PossiblyUnusedMethod v1 只读适配，测试覆盖中
     */
    public static function resolve(array $templates, array $product): ?array
    {
        $winner = null;
        $score = -1;
        foreach ($templates as $template) {
            if (($template['type'] ?? '') !== 'product-detail' || (int) ($template['status'] ?? 0) !== 1) continue;
            try {
                $document = BloxDocumentPipeline::decode((string) ($template['published_data'] ?? ''));
            } catch (Throwable) {
                continue;
            }
            $scope = self::normalizeScope($document['settings']['product_template'] ?? null);
            if (!self::matches($scope, $product)) continue;
            $candidateScore = $scope['mode'] === 'selected' ? 1 : 0;
            if ($candidateScore > $score || ($candidateScore === $score && (int) $template['id'] > (int) ($winner['id'] ?? 0))) {
                $winner = $template;
                $score = $candidateScore;
            }
        }
        return $winner;
    }

    /**
     * @param array<string,mixed> $input 控制器返回值或产品行（见 normalizeContext）
     */
    public static function renderPublished(array $input): string
    {
        // Published output is independent of editor and download entitlements.
        $context = self::normalizeContext($input);
        // 统一判定入口：v2 的 detail_template 与 v1 的 product_template（只读适配）都经它，
        // 不再在产品侧保留第二套排序。native 终止语义由解析器统一处理。
        $resolution = DetailTemplateProvider::resolveFor('product', $context);
        $template = is_array($resolution['template'] ?? null) ? $resolution['template'] : null;
        if ($template === null || $resolution['source'] !== DetailTemplateResolver::SOURCE_CUSTOM) return '';
        try {
            $html = self::withProduct($context, static fn(): string => BlockRenderer::render((string) ($template['published_data'] ?? '')));
            if (!BlockRenderer::hasMeaningfulOutput($html)) return '';
            return '<div class="yk-blox-product-detail" data-template-id="' . (int) $template['id'] . '">' . $html . '</div>';
        } catch (Throwable $e) {
            error_log('[product-template] Render failed: ' . $e->getMessage());
            return '';
        }
    }

    public static function seed(string $language): string
    {
        return BloxDocumentPipeline::process(json_encode([
            'schema' => 1,
            'settings' => ['product_template' => ['mode' => 'selected', 'ids' => [], 'lang' => $language]],
            'sections' => [['columns' => [
                ['width' => 6, 'elements' => [['type' => 'product-image', 'data' => []]]],
                ['width' => 6, 'elements' => [
                    ['type' => 'product-title', 'data' => ['level' => 'h1']],
                    ['type' => 'product-content', 'data' => []],
                ]],
            ]]],
        ], JSON_THROW_ON_ERROR), 'product-template')['json'];
    }
}
