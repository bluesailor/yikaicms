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
     * v1 语义判定：命中模板若声明 source=native，表示这一条规则改用系统默认。
     *
     * 统一判定入口（DetailTemplateProvider）已内含同样语义，产品侧不再调用它；
     * 保留是因为它是 v1 规则的只读适配，测试与历史调用仍依赖。
     *
     * @psalm-suppress PossiblyUnusedMethod v1 只读适配，测试覆盖中
     */
    public static function usesNative(?array $template): bool
    {
        if ($template === null) return true;
        $document = BloxDocumentPipeline::decode((string) ($template['published_data'] ?? ''));
        return (self::normalizeScope($document['settings']['product_template'] ?? null)['source'] ?? '') === 'native';
    }

    /** Switch only the published output source, retaining its layout and scope. */
    public static function changeSource(string $json, string $source): string
    {
        if (!in_array($source, ['native', 'custom'], true)) throw new InvalidArgumentException(__('blox_bad_request'));
        $document = BloxDocumentPipeline::decode($json);
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
        if (!bloxPageEditorEnabled() || !bloxAdvancedFeaturesEnabled()) return '';
        $context = self::normalizeContext($input);
        // 统一判定入口：v2 的 detail_template 与 v1 的 product_template（只读适配）都经它，
        // 不再在产品侧保留第二套排序。native 终止语义由解析器统一处理。
        $resolution = DetailTemplateProvider::resolveFor('product', $context);
        $template = is_array($resolution['template'] ?? null) ? $resolution['template'] : null;
        if ($template === null || $resolution['source'] !== DetailTemplateResolver::SOURCE_CUSTOM) return '';
        try {
            $html = self::withProduct($context, static fn(): string => BlockRenderer::render((string) ($template['published_data'] ?? '')));
            if (trim(strip_tags($html)) === '' && !preg_match('/<(?:img|video|iframe)\b/i', $html)) return '';
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
