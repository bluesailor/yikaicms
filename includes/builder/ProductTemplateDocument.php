<?php
/** Product detail templates keep bindings separate from preview records. */
declare(strict_types=1);

final class ProductTemplateDocument
{
    private static ?array $product = null;

    public static function currentProduct(): ?array
    {
        return self::$product;
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

    /** Specific products win; equal scopes use the highest template ID. */
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

    public static function renderPublished(array $product): string
    {
        if (!bloxPageEditorEnabled() || !bloxAdvancedFeaturesEnabled()) return '';
        $template = self::resolve(bloxTemplateModel()->publishedProductTemplates(), $product);
        if ($template === null) return '';
        try {
            // A native rule must stop resolution, not fall through to another custom layout.
            if (self::usesNative($template)) return '';
            $html = self::withProduct($product, static fn(): string => BlockRenderer::render((string) $template['published_data']));
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
