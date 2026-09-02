<?php

declare(strict_types=1);

/** Theme-local presentation only: never write alternation back into the document. */
function businessHomeSurface(array $block): array
{
    static $next = 'light';
    BloxAssetCollector::addStyle('/themes/business/assets/css/home-surfaces.css');
    BloxAssetCollector::addScript('/themes/business/assets/js/home-surfaces.js');

    $mode = in_array($block['home_surface'] ?? '', ['light', 'dark', 'custom'], true)
        ? $block['home_surface'] : 'auto';
    $background = static function (array $data): array {
        return [
            'bg_color' => AbstractElement::cssColor($data['bg_color'] ?? null) ?? '',
            'bg_image' => AbstractElement::cssImageUrl($data['bg_image'] ?? null) ?? '',
        ];
    };
    $own = $background($block);
    $inherited = false;
    $colors = $own;
    if (!in_array($mode, ['light', 'dark'], true) && !array_filter($own)) {
        foreach (($block['_blox_parent_backgrounds'] ?? []) as $parent) {
            $candidate = is_array($parent) ? $background($parent) : [];
            if (array_filter($candidate) || !empty($parent['bg_gradient'])) {
                $colors = $candidate;
                $inherited = true;
                break;
            }
        }
    }
    if ($mode === 'auto' && (array_filter($own) || $inherited)) {
        $mode = 'custom';
    }
    if ($mode === 'custom') {
        $tone = !empty($block['text_light']) || PageHeroStyleResolver::textTone([
            'background_color' => $colors['bg_color'] ?: '#ffffff',
        ], $colors['bg_image']) === 'light' ? 'dark' : 'light';
    } else {
        $tone = $mode === 'auto' ? $next : $mode;
        $next = $tone === 'light' ? 'dark' : 'light';
    }
    $bg = $mode === 'custom' && !$inherited
        ? getBlockBg(array_merge($block, $own)) : getBlockBg([]);
    $attrs = 'data-business-surface="' . e($mode) . '" data-business-tone="' . e($tone) . '"';
    if ($inherited) {
        $attrs .= ' data-business-inherited="true"';
    }
    return $bg + ['attributes' => $attrs];
}
