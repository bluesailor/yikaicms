<?php

declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/init.php';

$name = preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($_GET['name'] ?? '')));
$url = '/uploads/images/' . ($name !== '' ? $name : 'missing-responsive-fixture') . '.png';
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
</body>
</html>
