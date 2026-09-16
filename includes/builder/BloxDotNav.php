<?php
/**
 * R7A：单页圆点导航（页面级，默认关闭）。
 *
 * - 只提取「显式勾选参与导航」的顶层区块；隐藏区块、页头页尾、弹窗不进导航
 *   （后三者不在页面文档 sections 里，天然排除）。
 * - 锚点来自区块的既有 anchor_id（参与导航且为空时由管线按稳定节点 ID 补齐），
 *   输出真实 <a href="#…">：JS 不可用仍可跳转，不劫持浏览器后退。
 * - 标题默认取区块名称；页面文档本身按语言分份，标题随各语言文档分别编辑。
 * - 少于 2 项不渲染；手机默认隐藏，可显式开启；位置默认右侧居中，可改左侧。
 */

declare(strict_types=1);

final class BloxDotNav
{
    /** @return array{enabled:bool, position:'left'|'right', mobile:bool} */
    public static function normalizeSettings(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        return [
            'enabled' => in_array($value['enabled'] ?? false, [true, 1, '1'], true),
            'position' => ($value['position'] ?? 'right') === 'left' ? 'left' : 'right',
            'mobile' => in_array($value['mobile'] ?? false, [true, 1, '1'], true),
        ];
    }

    /**
     * 参与导航的条目。只信文档：显式勾选 + 未隐藏 + 有锚点。
     *
     * @param array<string,mixed> $document 归一化后的文档（settings + sections）
     * @return list<array{anchor:string, title:string}>
     */
    public static function items(array $document): array
    {
        $items = [];
        $seen = [];
        $index = 0;
        foreach (is_array($document['sections'] ?? null) ? $document['sections'] : [] as $section) {
            $index++;
            if (!is_array($section)) {
                continue;
            }
            $settings = is_array($section['settings'] ?? null) ? $section['settings'] : [];
            if (empty($settings['dot_nav_on']) || !empty($settings['hidden'])) {
                continue;
            }
            $anchor = BloxDocumentPipeline::normalizeSectionAnchorId($settings['anchor_id'] ?? '');
            if ($anchor === '' || isset($seen[strtolower($anchor)])) {
                // 旧文档未经补锚点归一化，或锚点冲突：跳过而不是输出坏链接
                continue;
            }
            $seen[strtolower($anchor)] = true;
            $title = trim((string) ($settings['dot_nav_title'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($section['name'] ?? ''));
            }
            if ($title === '') {
                $title = str_replace(':n', (string) $index, __('blox_dotnav_section_n'));
            }
            $items[] = ['anchor' => $anchor, 'title' => mb_substr($title, 0, 60)];
        }
        return $items;
    }

    /** 前台导航 HTML；未启用或不足 2 项返回空串（不注册资源）。 */
    public static function render(array $document): string
    {
        $settings = self::normalizeSettings($document['settings']['dot_nav'] ?? null);
        if (!$settings['enabled']) {
            return '';
        }
        $items = self::items($document);
        if (count($items) < 2) {
            return '';
        }
        if (class_exists('BloxAssetCollector')) {
            BloxAssetCollector::addStyle('/assets/css/blox-dot-nav.css');
            BloxAssetCollector::addScript('/assets/js/blox-dot-nav.js');
        }
        $classes = 'yk-dotnav yk-dotnav--' . $settings['position'] . ($settings['mobile'] ? ' yk-dotnav--mobile' : '');
        $html = '<nav class="' . e($classes) . '" data-yk-dotnav aria-label="' . e(__('blox_dotnav_aria')) . '">';
        foreach ($items as $item) {
            $html .= '<a class="yk-dotnav__dot" href="#' . e($item['anchor']) . '"'
                . ' data-yk-dotnav-target="' . e($item['anchor']) . '"'
                . ' aria-label="' . e($item['title']) . '" title="' . e($item['title']) . '">'
                . '<span class="yk-dotnav__label" aria-hidden="true">' . e($item['title']) . '</span>'
                . '</a>';
        }
        return $html . '</nav>';
    }
}
