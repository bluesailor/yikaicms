<?php
declare(strict_types=1);
$studioImage = UrlPolicy::image(themeContent('hero_image'));
$studioButton = UrlPolicy::href(themeContent('button_url'));
?>
<section class="studio-hero">
    <div class="studio-wrap">
        <div class="studio-copy">
            <p class="studio-eyebrow"><?= e(themeContent('eyebrow')) ?></p>
            <h1><?= e(themeContent('hero_title')) ?></h1>
            <p class="studio-description"><?= e(themeContent('hero_description')) ?></p>
            <?php if ($studioButton !== ''): ?><a class="studio-button" href="<?= e($studioButton) ?>"><?= e(themeContent('button_text')) ?><span aria-hidden="true"> ↗</span></a><?php endif; ?>
        </div>
        <?php if (themeContent('show_image') === '1' && $studioImage !== ''): ?>
        <figure class="studio-art"><img src="<?= e($studioImage) ?>" alt="<?= e(themeContent('hero_title')) ?>" width="800" height="600"></figure>
        <?php endif; ?>
    </div>
</section>
