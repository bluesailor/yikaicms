<?php
/** 站点级多语言网页头/脚管理面板。 */

declare(strict_types=1);

$view = $GLOBALS['bloxLanguageAreaView'] ?? null;
if (!is_array($view)) {
    return;
}
/** @var list<array{code:string,label:string,is_default:bool,areas:array<string,array<string,mixed>>}> $languageAreaRows */
$languageAreaRows = is_array($view['rows'] ?? null) ? $view['rows'] : [];
$selectedLanguageAreaRow = is_array($view['selected_row'] ?? null) ? $view['selected_row'] : null;
$defaultLanguageAreaRow = is_array($view['default_row'] ?? null) ? $view['default_row'] : null;
/** @var list<string> $overviewTypes */
$overviewTypes = is_array($view['types'] ?? null) ? $view['types'] : [];
$selectedAreaLanguage = (string) ($view['selected_language'] ?? '');
$filterType = (string) ($view['filter_type'] ?? 'all');
$currentTheme = (string) ($view['current_theme'] ?? 'default');
if ($selectedLanguageAreaRow === null || $defaultLanguageAreaRow === null) {
    return;
}
?>
<section id="blox-language-areas" class="border-y border-gray-200 bg-white py-5" data-testid="blox-language-areas">
    <div class="flex flex-wrap items-start justify-between gap-3 px-4">
        <div>
            <h2 class="text-sm font-semibold text-gray-900"><?php echo e(__('blox_language_areas_title')); ?></h2>
            <p class="mt-1 max-w-3xl text-xs leading-5 text-gray-500"><?php echo e(__('blox_language_areas_hint')); ?></p>
        </div>
        <div class="flex max-w-full flex-wrap gap-1 border border-gray-200 bg-gray-50 p-1" role="tablist" aria-label="<?php echo e(__('blox_language_switch_label')); ?>">
            <?php foreach ($languageAreaRows as $languageRow):
                $isSelected = $languageRow['code'] === $selectedAreaLanguage;
                $languageQuery = ['area_lang' => $languageRow['code']];
                if ($filterType !== 'all') {
                    $languageQuery['type'] = $filterType;
                }
            ?>
            <a href="/admin/blox_templates.php?<?php echo e(http_build_query($languageQuery)); ?>#blox-language-areas"
               role="tab"
               aria-selected="<?php echo $isSelected ? 'true' : 'false'; ?>"
               data-testid="blox-language-tab-<?php echo e($languageRow['code']); ?>"
               class="inline-flex h-8 items-center gap-1.5 px-3 text-xs font-medium <?php echo $isSelected ? 'bg-gray-900 text-white' : 'text-gray-600 hover:bg-white hover:text-gray-900'; ?>">
                <?php echo e($languageRow['label']); ?>
                <?php if ($languageRow['is_default']): ?>
                <span class="text-[10px] opacity-70"><?php echo e(__('blox_language_default_badge')); ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="mt-4 grid gap-px border-y border-gray-200 bg-gray-200 <?php echo count($overviewTypes) > 1 ? 'md:grid-cols-2' : 'grid-cols-1'; ?>">
        <?php foreach ($overviewTypes as $areaType):
            $areaState = $selectedLanguageAreaRow['areas'][$areaType];
            $candidate = is_array($areaState['candidate'] ?? null) ? $areaState['candidate'] : null;
            $draft = is_array($areaState['draft'] ?? null) ? $areaState['draft'] : null;
            $mode = (string) ($areaState['mode'] ?? 'theme');
            $isHeader = $areaType === 'header';
            $sourceState = $defaultLanguageAreaRow['areas'][$areaType] ?? [];
            $source = is_array($sourceState['candidate'] ?? null) ? $sourceState['candidate'] : null;
            $previewUrl = langUrl('/', $selectedAreaLanguage);
            $previewUrl .= str_contains($previewUrl, '?') ? '&preview=1' : '?preview=1';
            $modeKey = match ($mode) {
                'disabled' => 'blox_language_area_mode_disabled',
                'independent' => 'blox_language_area_mode_independent',
                'advanced' => 'blox_language_area_mode_advanced',
                'default' => 'blox_language_area_mode_default',
                'inherit' => 'blox_language_area_mode_inherit',
                default => 'blox_language_area_mode_theme',
            };
            $modeClass = match ($mode) {
                'independent' => 'bg-emerald-50 text-emerald-700',
                'advanced' => 'bg-blue-50 text-blue-700',
                'disabled' => 'bg-amber-50 text-amber-700',
                default => 'bg-gray-100 text-gray-600',
            };
        ?>
        <article class="min-w-0 bg-white px-4 py-4" data-testid="blox-language-area-<?php echo e($areaType); ?>">
            <div class="flex min-w-0 items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center bg-gray-100 text-gray-600" aria-hidden="true">
                    <i class="ti <?php echo $isHeader ? 'ti-layout-navbar' : 'ti-layout-bottombar'; ?> text-lg"></i>
                </span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold text-gray-900"><?php echo e($isHeader ? __('blox_tpl_type_header') : __('blox_tpl_type_footer')); ?></h3>
                        <span class="px-2 py-0.5 text-[10px] font-medium <?php echo e($modeClass); ?>" data-testid="blox-language-area-mode">
                            <?php echo e(__($modeKey)); ?>
                        </span>
                    </div>
                    <p class="mt-1 truncate text-sm text-gray-700" data-testid="blox-language-area-template">
                        <?php echo e((string) ($candidate['name'] ?? __('blox_current_theme_fallback', ['theme' => $currentTheme]))); ?>
                    </p>
                    <p class="mt-1 text-xs leading-5 text-gray-500">
                        <?php if ($draft !== null && $mode !== 'independent'): ?>
                            <?php echo e(__('blox_language_area_draft_hint', ['name' => (string) $draft['name']])); ?>
                        <?php elseif ($mode === 'inherit'): ?>
                            <?php echo e(__('blox_language_area_inherit_hint', ['language' => (string) $defaultLanguageAreaRow['label']])); ?>
                        <?php elseif ($mode === 'advanced'): ?>
                            <?php echo e(__('blox_language_area_advanced_hint')); ?>
                        <?php elseif ($mode === 'theme'): ?>
                            <?php echo e(__('blox_language_area_theme_hint')); ?>
                        <?php else: ?>
                            <?php echo e(__('blox_language_area_current_hint')); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <div class="mt-4 flex flex-wrap items-center gap-3 border-t border-gray-100 pt-3">
                <?php if ($draft !== null && $mode !== 'independent'): ?>
                <a href="/admin/blox_editor.php?template=<?php echo (int) $draft['id']; ?>"
                   class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75"
                   data-testid="blox-language-edit-draft"><i class="ti ti-edit"></i><?php echo e(__('blox_language_area_edit_draft')); ?></a>
                <?php elseif ($candidate !== null && in_array($mode, ['independent', 'advanced', 'default'], true)): ?>
                <a href="/admin/blox_editor.php?template=<?php echo (int) $candidate['id']; ?>"
                   class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75"
                   data-testid="blox-language-edit-current"><i class="ti ti-edit"></i><?php echo e(__('blox_current_edit')); ?></a>
                <?php elseif (!$selectedLanguageAreaRow['is_default'] && $source !== null): ?>
                <form method="post" class="contents">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="create_area_language_draft">
                    <input type="hidden" name="area" value="<?php echo e($areaType); ?>">
                    <input type="hidden" name="language" value="<?php echo e($selectedAreaLanguage); ?>">
                    <input type="hidden" name="source_id" value="<?php echo (int) $source['id']; ?>">
                    <button type="submit" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75" data-testid="blox-language-copy-default">
                        <i class="ti ti-copy-plus"></i><?php echo e(__('blox_language_area_copy_default')); ?>
                    </button>
                </form>
                <?php else: ?>
                <a href="/admin/blox_templates.php?type=<?php echo e($areaType); ?>" class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:opacity-75"><i class="ti ti-layout-grid-add"></i><?php echo e(__('blox_current_choose_design')); ?></a>
                <?php endif; ?>

                <?php if (!empty($areaState['managed']) && !$selectedLanguageAreaRow['is_default']): ?>
                <form method="post" class="contents" onsubmit="return confirm(<?php echo e(json_encode(__('blox_language_area_restore_confirm'), JSON_UNESCAPED_UNICODE)); ?>)">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="restore_area_language_inheritance">
                    <input type="hidden" name="area" value="<?php echo e($areaType); ?>">
                    <input type="hidden" name="language" value="<?php echo e($selectedAreaLanguage); ?>">
                    <button type="submit" class="text-sm text-gray-500 hover:text-red-600" data-testid="blox-language-restore-inherit"><?php echo e(__('blox_language_area_restore')); ?></button>
                </form>
                <?php endif; ?>

                <a href="<?php echo e($previewUrl); ?>" target="_blank" rel="noopener" class="ml-auto inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-900">
                    <i class="ti ti-external-link"></i><?php echo e(__('blox_current_preview')); ?>
                </a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <p class="px-4 pt-3 text-xs text-gray-500"><i class="ti ti-info-circle mr-1"></i><?php echo e(__('blox_language_area_override_hint')); ?></p>
</section>
