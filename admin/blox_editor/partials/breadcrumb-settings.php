<?php
declare(strict_types=1);

// The caller supplies trusted Alpine expressions for the editor or global design form.
$breadcrumbOptions = $breadcrumbOptions ?? 'pageHero.style_options';
$breadcrumbDisabled = $breadcrumbDisabled ?? "pageHero.style_source !== 'self'";
$breadcrumbEffective = $breadcrumbEffective ?? 'pageHeroPreviewOptions()';
?>
<fieldset :disabled="<?= e($breadcrumbDisabled) ?>" class="space-y-4" data-testid="blox-breadcrumb-settings">
    <legend class="mb-2 text-sm font-medium text-gray-800"><?= e(__('blox_breadcrumb_layout')) ?></legend>
    <div class="blox-hero-mode-choices">
        <?php foreach (['banner' => 'blox_breadcrumb_banner', 'compact' => 'blox_breadcrumb_compact'] as $value => $label): ?>
        <label class="blox-hero-mode-choice" :data-selected="(<?= e($breadcrumbEffective) ?>.layout || 'banner') === '<?= e($value) ?>'">
            <input type="radio" name="blox-hero-layout" :checked="(<?= e($breadcrumbEffective) ?>.layout || 'banner') === '<?= e($value) ?>'" @change="<?= e($breadcrumbOptions) ?>.layout = '<?= e($value) ?>'" value="<?= e($value) ?>" data-testid="blox-page-hero-layout-<?= e($value) ?>">
            <i class="ti <?= $value === 'banner' ? 'ti-layout-navbar' : 'ti-route' ?>" aria-hidden="true"></i>
            <span><?= e(__($label)) ?></span>
        </label>
        <?php endforeach; ?>
    </div>
    <div x-show="<?= e($breadcrumbEffective) ?>.layout === 'compact'" class="space-y-4" data-testid="blox-breadcrumb-compact-controls">
        <fieldset>
            <legend class="mb-2 text-xs font-medium text-gray-600"><?= e(__('blox_breadcrumb_style')) ?></legend>
            <div class="blox-breadcrumb-choices">
                <?php foreach (['minimal', 'soft', 'contained'] as $variant): ?>
                <label class="blox-breadcrumb-choice" :data-selected="(<?= e($breadcrumbEffective) ?>.breadcrumb_style || 'minimal') === '<?= e($variant) ?>'">
                    <span class="blox-breadcrumb-sample" data-style="<?= e($variant) ?>" aria-hidden="true"><span><i class="ti ti-home"></i><i class="ti ti-chevron-right"></i><b></b><i class="ti ti-chevron-right"></i><b></b></span></span>
                    <span class="blox-breadcrumb-choice-label"><input type="radio" name="blox-breadcrumb-style" :checked="(<?= e($breadcrumbEffective) ?>.breadcrumb_style || 'minimal') === '<?= e($variant) ?>'" @change="<?= e($breadcrumbOptions) ?>.breadcrumb_style = '<?= e($variant) ?>'" value="<?= e($variant) ?>"><span><?= e(__('blox_breadcrumb_style_' . $variant)) ?></span></span>
                </label>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <fieldset>
                <legend class="mb-2 text-xs font-medium text-gray-600"><?= e(__('blox_breadcrumb_spacing')) ?></legend>
                <div class="blox-breadcrumb-spacing">
                    <?php foreach (['compact', 'standard', 'relaxed'] as $spacing): ?>
                    <label :data-selected="(<?= e($breadcrumbEffective) ?>.breadcrumb_spacing || 'standard') === '<?= e($spacing) ?>'"><input type="radio" name="blox-breadcrumb-spacing" :checked="(<?= e($breadcrumbEffective) ?>.breadcrumb_spacing || 'standard') === '<?= e($spacing) ?>'" @change="<?= e($breadcrumbOptions) ?>.breadcrumb_spacing = '<?= e($spacing) ?>'" value="<?= e($spacing) ?>"><span><?= e(__('blox_breadcrumb_spacing_' . $spacing)) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <fieldset>
                <legend class="mb-2 text-xs font-medium text-gray-600"><?= e(__('blox_breadcrumb_color')) ?></legend>
                <div class="blox-breadcrumb-colors">
                    <?php foreach (['default' => '#374151', 'gray' => '#4b5563', 'blue' => '#1d4ed8'] as $tone => $color): ?>
                    <label class="blox-breadcrumb-swatch" style="--swatch:<?= e($color) ?>" :data-selected="(<?= e($breadcrumbEffective) ?>.breadcrumb_tone || 'default') === '<?= e($tone) ?>'" title="<?= e(__('blox_breadcrumb_color_' . $tone)) ?>">
                        <input type="radio" name="blox-breadcrumb-tone" :checked="(<?= e($breadcrumbEffective) ?>.breadcrumb_tone || 'default') === '<?= e($tone) ?>'" @change="<?= e($breadcrumbOptions) ?>.breadcrumb_tone = '<?= e($tone) ?>'" value="<?= e($tone) ?>" aria-label="<?= e(__('blox_breadcrumb_color_' . $tone)) ?>">
                        <i class="ti ti-check" aria-hidden="true"></i>
                    </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
        </div>
    </div>
</fieldset>
<?php unset($breadcrumbOptions, $breadcrumbDisabled, $breadcrumbEffective); ?>
