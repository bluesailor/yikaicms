<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/init.php';

$name = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['name'] ?? '')));
$url = '/uploads/images/' . ($name !== '' ? $name : 'missing-responsive-fixture') . '.png';
$alternateUrl = '/uploads/images/' . ($name !== '' ? $name . '-alt' : 'missing-responsive-fixture-alt') . '.png';
$gallerySizes = '(min-width: 1024px) 50vw, 100vw';
$galleryVariants = array_map(
    static function (string $image) use ($gallerySizes): array {
        $variant = responsiveImageData($image, 'medium');
        $variant['sizes'] = $gallerySizes;
        return $variant;
    },
    [$url, $alternateUrl]
);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Responsive image fixture</title>
</head>
<body style="margin:0">
    <img data-testid="responsive-image"
         <?php echo responsiveImageAttributes($url, 'medium', '100vw'); ?>
         alt="Responsive image fixture" style="display:block;width:100%;height:auto">
    <div style="max-width:480px;margin:24px auto">
        <?php
        $item = [
            'id' => 1,
            'slug' => 'responsive-image-fixture',
            'title' => 'Responsive image fixture',
            'cover' => $url,
            'is_new' => 0,
            'is_hot' => 0,
            'is_recommend' => 0,
            'model' => '',
            'price' => 0,
        ];
        $isProductType = true;
        require ROOT_PATH . '/themes/default/partials/product-card.php';
        ?>
    </div>
    <div style="max-width:720px;margin:24px auto">
        <img data-testid="product-gallery-main"
             <?php echo responsiveImageAttributes($url, 'medium', $gallerySizes); ?>
             alt="Product gallery fixture" style="display:block;width:100%;height:auto">
        <button type="button" data-testid="product-gallery-next" onclick="switchFixtureImage(1)">Next</button>
        <button type="button" data-testid="product-gallery-plain" onclick="switchFixtureImage(2)">Plain</button>
    </div>
    <a data-testid="preview-original" href="<?php echo e($url); ?>">
        <img data-testid="preview-thumb"
             <?php echo responsiveImageAttributes($url, 'thumb', '80px'); ?>
             alt="Thumbnail preview fixture" style="display:block;width:80px;height:80px;object-fit:cover">
    </a>
    <div data-testid="builder-image">
        <?php echo (new ImageElement())->render([
            'src' => $url,
            'alt' => 'Builder image fixture',
            'click_action' => 'lightbox',
        ]); ?>
    </div>
    <div data-testid="builder-card">
        <?php echo (new CardElement())->render(['image' => $url, 'title' => 'Builder card fixture']); ?>
    </div>
    <div data-testid="builder-banner">
        <?php echo HomeBannerItemElement::responsiveImageHtml([
            'image' => $url,
            'image_mobile' => $alternateUrl,
            'title' => 'Builder banner fixture',
        ]); ?>
    </div>
    <div data-testid="builder-dynamic-card">
        <?php
        TagEngine::setItem([
            'cover' => $url,
            'title' => 'Dynamic card fixture',
            'url' => '/dynamic-card.html',
        ]);
        echo TagEngine::render(DynamicListItemSchema::render([]));
        TagEngine::setItem(null);
        ?>
    </div>
    <script src="/assets/js/product-gallery.js"></script>
    <script>
    var fixtureVariants = <?php echo json_encode($galleryVariants, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    fixtureVariants.push({src: '/uploads/images/plain-fallback.png', srcset: '', sizes: '', width: 0, height: 0});
    function switchFixtureImage(idx) {
        window.YikaiProductGallery.applyImageVariant(
            document.querySelector('[data-testid="product-gallery-main"]'),
            fixtureVariants[idx]
        );
    }
    </script>
</body>
</html>
