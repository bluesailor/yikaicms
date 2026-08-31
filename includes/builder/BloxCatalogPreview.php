<?php
/** Render page catalogs using the public controllers and views, without request filters. */
declare(strict_types=1);

final class BloxCatalogPreview
{
    public static function render(array $channel, string $json): string
    {
        $type = (string) ($channel['type'] ?? '');
        if (!in_array($type, ['product', 'list'], true)) {
            return renderBlocksToHtml($json);
        }
        require_once ROOT_PATH . '/controllers/list/ListRouter.php';
        // Admin query parameters must not become public sort/filter URLs or product filters.
        $savedGet = $_GET;
        $savedUri = $_SERVER['REQUEST_URI'] ?? null;
        $_GET = [];
        $_SERVER['REQUEST_URI'] = channelUrl($channel);
        try {
            $id = (int) $channel['id'];
            $context = ListRouter::dispatch($type)->prepare($channel, [
                'channelId' => $id, 'slug' => (string) ($channel['slug'] ?? ''),
                'page' => 1, 'perPage' => 12, 'keyword' => '', 'cat' => '', 'sort' => '',
            ]);
            $context['rootChannel'] = $channel;
            if ($type === 'product') {
                $context['isProductType'] = true;
                $context['subChannels'] = getChannels($id, false);
                $context['categoryTree'] = productCategoryModel()->getNavigationTree((int) ($context['productCategoryId'] ?? 0));
                ProductCatalogElement::setRuntimeContext($context);
            } else {
                $context['categories'] = getChannels($id, false);
                ContentCatalogElement::setRuntimeContext($context);
            }
            return renderBlocksToHtml($json);
        } finally {
            ProductCatalogElement::setRuntimeContext(null);
            ContentCatalogElement::setRuntimeContext(null);
            $_GET = $savedGet;
            if ($savedUri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $savedUri;
            }
        }
    }
}
