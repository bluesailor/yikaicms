<?php
declare(strict_types=1);
$breadcrumbEffective = $breadcrumbEffective ?? 'pageHeroPreviewOptions()';
$breadcrumbName = $breadcrumbName ?? 'pageHero.name';
$breadcrumbParents = $breadcrumbParents ?? [];
?>
<div class="yk-breadcrumb-bar" :data-style="<?= e($breadcrumbEffective) ?>.breadcrumb_style || 'minimal'" :data-spacing="<?= e($breadcrumbEffective) ?>.breadcrumb_spacing || 'standard'" :data-tone="<?= e($breadcrumbEffective) ?>.breadcrumb_tone || 'default'" data-testid="blox-breadcrumb-preview">
    <div class="yk-breadcrumb-inner">
        <div class="yk-breadcrumb-nav"><ol><li><i class="ti ti-home" aria-hidden="true"></i><span class="sr-only"><?= e(__('breadcrumb_home')) ?></span></li><?php foreach ($breadcrumbParents as $breadcrumbParent): ?><li><i class="ti ti-chevron-right yk-breadcrumb-separator" aria-hidden="true"></i><span><?= e((string) $breadcrumbParent) ?></span></li><?php endforeach; ?><li><i class="ti ti-chevron-right yk-breadcrumb-separator" aria-hidden="true"></i><span aria-current="page" x-text="<?= e($breadcrumbName) ?>"></span></li></ol></div>
    </div>
</div>
<?php unset($breadcrumbEffective, $breadcrumbName, $breadcrumbParents); ?>
