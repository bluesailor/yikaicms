<?php

declare(strict_types=1);

final class HomeBannerItemElement extends AbstractElement
{
    private const CONTENT_MOTIONS = ['inherit', 'none', 'fade-up', 'slide-left', 'slide-right', 'zoom-in', 'clip-reveal', 'blur-up', 'pop-in'];
    private const BACKGROUND_MOTIONS = ['inherit', 'none', 'zoom-in', 'zoom-out'];
    /** 非默认语言在编辑器里改过的轮播文字：{lang: {field: value}}，优先于该语言的轮播图记录。 */
    public const I18N_KEY = '_home_banner_i18n';
    /** 编辑器标记：{lang, shown, source}，保存时还原共享文档并写入 I18N_KEY。 */
    public const EDIT_KEY = '_home_banner_edit';
    private const LOCALIZED_FIELDS = ['title', 'subtitle', 'btn1_text', 'btn1_url', 'btn2_text', 'btn2_url', 'link_url', 'link_target'];

    public function type(): string { return 'home-banner-item'; }
    public function label(): string { return __('blox_home_banner_item'); }
    public function icon(): string { return 'photo'; }
    public function category(): string { return 'dynamic'; }
    public function paletteVisible(string $context = 'page'): bool { return false; }
    public function canBeGenericChild(): bool { return false; }
    public function supportsBoxStyles(): bool { return false; }
    public function treeLabelField(): ?string { return 'title'; }

    public function controls(): array
    {
        return [
            ...BannerContentLayout::controls(true),
            ['key' => 'title', 'type' => 'text', 'label' => __('blox_home_banner_title'), 'default' => ''],
            ['key' => 'subtitle', 'type' => 'textarea', 'label' => __('blox_home_banner_subtitle'), 'default' => ''],
            [
                'key' => 'content_motion',
                'type' => 'select',
                'label' => __('blox_home_banner_content_motion'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_banner_motion_inherit'),
                    'none' => __('blox_banner_motion_none'),
                    'fade-up' => __('blox_banner_motion_fade_up'),
                    'slide-left' => __('blox_banner_motion_slide_left'),
                    'slide-right' => __('blox_banner_motion_slide_right'),
                    'zoom-in' => __('blox_banner_motion_zoom_in'),
                    'clip-reveal' => __('blox_banner_motion_clip_reveal'),
                    'blur-up' => __('blox_banner_motion_blur_up'),
                    'pop-in' => __('blox_banner_motion_pop_in'),
                ],
                'option_icons' => [
                    'inherit' => 'settings',
                    'none' => 'ban',
                    'fade-up' => 'arrow-up',
                    'slide-left' => 'arrow-left',
                    'slide-right' => 'arrow-right',
                    'zoom-in' => 'zoom-in',
                    'clip-reveal' => 'scan',
                    'blur-up' => 'blur',
                    'pop-in' => 'sparkles',
                ],
                'help' => __('blox_home_banner_content_motion_help'),
            ],
            [
                'key' => 'media_type',
                'type' => 'select',
                'label' => __('blox_banner_media_type'),
                'default' => 'image',
                'options' => [
                    'image' => __('media_type_image'),
                    'video' => __('media_type_video'),
                ],
                'option_icons' => [
                    'image' => 'photo',
                    'video' => 'video',
                ],
            ],
            ['key' => 'video', 'type' => 'video_url', 'label' => __('blox_banner_video'), 'default' => '', 'placeholder' => '/uploads/videos/banner.mp4'],
            ['key' => 'image', 'type' => 'image', 'label' => __('blox_banner_image_or_poster'), 'default' => ''],
            ['key' => 'image_mobile', 'type' => 'image', 'label' => __('bn_mobile_image'), 'default' => ''],
            [
                'key' => 'video_mobile_mode',
                'type' => 'select',
                'label' => __('blox_banner_video_mobile_mode'),
                'default' => 'poster',
                'options' => [
                    'poster' => __('blox_banner_video_mobile_poster'),
                    'video' => __('blox_banner_video_mobile_play'),
                ],
                'option_icons' => [
                    'poster' => 'photo',
                    'video' => 'player-play',
                ],
                'help' => __('blox_banner_video_mobile_help'),
            ],
            [
                'key' => 'background_motion',
                'type' => 'select',
                'label' => __('blox_banner_background_motion'),
                'default' => 'inherit',
                'options' => [
                    'inherit' => __('blox_banner_motion_inherit'),
                    'none' => __('blox_banner_motion_none'),
                    'zoom-in' => __('blox_banner_background_zoom_in'),
                    'zoom-out' => __('blox_banner_background_zoom_out'),
                ],
                'option_icons' => [
                    'inherit' => 'settings',
                    'none' => 'ban',
                    'zoom-in' => 'zoom-in',
                    'zoom-out' => 'zoom-out',
                ],
            ],
            ['key' => 'btn1_text', 'type' => 'text', 'label' => __('blox_home_banner_primary_text'), 'default' => ''],
            ['key' => 'btn1_url', 'type' => 'url', 'label' => __('blox_home_banner_primary_url'), 'default' => ''],
            ['key' => 'btn2_text', 'type' => 'text', 'label' => __('blox_home_banner_secondary_text'), 'default' => ''],
            ['key' => 'btn2_url', 'type' => 'url', 'label' => __('blox_home_banner_secondary_url'), 'default' => ''],
            ['key' => 'link_url', 'type' => 'url', 'label' => __('blox_home_banner_image_url'), 'default' => ''],
            [
                'key' => 'link_target',
                'type' => 'select',
                'label' => __('blox_home_banner_target'),
                'default' => '_self',
                'options' => [
                    '_self' => __('blox_home_banner_target_self'),
                    '_blank' => __('blox_home_banner_target_blank'),
                ],
            ],
        ];
    }

    public function render(array $data, string $children = ''): string
    {
        $item = self::normalize($data);
        $image = $item['image'] !== ''
            ? '<img ' . responsiveImageAttributes(
                $item['image'],
                'medium',
                '(min-width: 1024px) 33vw, 100vw'
            ) . ' alt="' . e($item['title']) . '" decoding="async" class="w-full aspect-video object-cover">'
            : '<div class="w-full aspect-video bg-gray-100"></div>';
        return '<article class="overflow-hidden border border-gray-200 rounded-lg">' . $image
            . '<div class="p-4"><h3 class="font-semibold">' . e($item['title']) . '</h3>'
            . '<p class="text-sm text-gray-500 mt-1">' . e($item['subtitle']) . '</p></div></article>';
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function normalize(array $data): array
    {
        $item = BannerContentLayout::normalize($data);
        foreach (['title' => 200, 'subtitle' => 500, 'btn1_text' => 100, 'btn2_text' => 100] as $key => $limit) {
            $item[$key] = mb_substr(trim(strip_tags((string) ($data[$key] ?? ''))), 0, $limit);
        }
        $item['image'] = self::safeUrl((string) ($data['image'] ?? ''), false);
        $item['image_mobile'] = self::safeUrl((string) ($data['image_mobile'] ?? ''), false);
        $item['video'] = self::backgroundVideoUrl(['bg_video' => $data['video'] ?? '']);
        $item['media_type'] = ($data['media_type'] ?? 'image') === 'video' ? 'video' : 'image';
        $item['video_mobile_mode'] = ($data['video_mobile_mode'] ?? 'poster') === 'video' ? 'video' : 'poster';
        foreach (['btn1_url', 'btn2_url', 'link_url'] as $key) {
            $item[$key] = self::safeUrl((string) ($data[$key] ?? ''), true);
        }
        $item['link_target'] = ($data['link_target'] ?? '_self') === '_blank' ? '_blank' : '_self';
        $item['content_motion'] = self::contentMotion($data);
        $item['background_motion'] = self::backgroundMotion($data);
        $item['source_banner_id'] = max(0, (int) ($data['source_banner_id'] ?? $data['id'] ?? 0));
        $item['translation_group_id'] = max(0, (int) ($data['translation_group_id'] ?? 0));
        if ($item['translation_group_id'] === 0) {
            $item['translation_group_id'] = $item['source_banner_id'];
        }
        $item['lang'] = mb_substr(trim(strip_tags((string) ($data['lang'] ?? ''))), 0, 20);
        return $item;
    }

    /** @param array<string, mixed> $data */
    public static function contentMotion(array $data): string
    {
        $motion = (string) ($data['content_motion'] ?? 'inherit');
        return in_array($motion, self::CONTENT_MOTIONS, true) ? $motion : 'inherit';
    }

    /** @param array<string, mixed> $data */
    public static function contentMotionAttribute(array $data): string
    {
        $motion = self::contentMotion($data);
        return $motion === 'inherit'
            ? ''
            : ' data-blox-slide-content-motion="' . htmlspecialchars($motion, ENT_QUOTES) . '"';
    }

    /** @param array<string, mixed> $data */
    public static function backgroundMotion(array $data): string
    {
        $motion = (string) ($data['background_motion'] ?? 'inherit');
        return in_array($motion, self::BACKGROUND_MOTIONS, true) ? $motion : 'inherit';
    }

    /** @param array<string, mixed> $data */
    public static function backgroundMotionAttribute(array $data): string
    {
        $motion = self::backgroundMotion($data);
        return $motion === 'inherit'
            ? ''
            : ' data-blox-slide-background-motion="' . htmlspecialchars($motion, ENT_QUOTES) . '"';
    }

    /** @param array<string, mixed> $data */
    public static function motionAttributes(array $data): string
    {
        return self::contentMotionAttribute($data) . self::backgroundMotionAttribute($data);
    }

    /** @param array<string, mixed> $data */
    public static function responsiveImageHtml(array $data, string $class = 'w-full h-full object-cover'): string
    {
        $item = self::normalize($data);
        if ($item['image'] === '') {
            return '';
        }

        $class = htmlspecialchars($class, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $alt = htmlspecialchars($item['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<picture class="block w-full h-full" data-blox-banner-bg>';
        if ($item['image_mobile'] !== '') {
            $mobile = responsiveImageData($item['image_mobile'], 'medium');
            $mobileWebpSrcset = $mobile['webp_srcset'] !== '' ? $mobile['webp_srcset'] : $mobile['webp_src'];
            if ($mobileWebpSrcset !== '') {
                $html .= '<source media="(max-width: 767px)" type="image/webp" srcset="'
                    . htmlspecialchars($mobileWebpSrcset, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '" sizes="100vw">';
            }
            $mobileSrcset = $mobile['srcset'] !== '' ? $mobile['srcset'] : $mobile['src'];
            $html .= '<source media="(max-width: 767px)" srcset="'
                . htmlspecialchars($mobileSrcset, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" sizes="100vw">';
        }
        $desktop = responsiveImageData($item['image'], 'medium');
        $desktopWebpSrcset = $desktop['webp_srcset'] !== '' ? $desktop['webp_srcset'] : $desktop['webp_src'];
        if ($desktopWebpSrcset !== '') {
            $html .= '<source type="image/webp" srcset="'
                . htmlspecialchars($desktopWebpSrcset, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" sizes="100vw">';
        }
        $html .= '<img ' . responsiveImageAttributes($item['image'], 'medium', '100vw')
            . ' alt="' . $alt . '" decoding="async" class="' . $class . '"></picture>';

        return $html;
    }

    /**
     * @param array<string, mixed> $data
     * @psalm-api Theme template entry point.
     */
    public static function responsiveMediaHtml(array $data): string
    {
        $item = self::normalize($data);
        $poster = self::responsiveImageHtml(
            $item,
            'absolute inset-0 w-full h-full object-cover'
        );
        if ($poster === '') {
            $poster = '<div class="absolute inset-0 bg-gradient-to-r from-gray-800 via-gray-700 to-gray-900" data-blox-banner-poster></div>';
        } else {
            $poster = str_replace(' data-blox-banner-bg>', ' data-blox-banner-bg data-blox-banner-poster>', $poster);
        }

        if ($item['media_type'] !== 'video' || $item['video'] === '') {
            return $poster;
        }

        $posterUrl = $item['image'] !== ''
            ? ' poster="' . htmlspecialchars($item['image'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            : '';
        return $poster
            . '<video data-blox-banner-video data-blox-banner-bg data-blox-mobile-video="'
            . htmlspecialchars($item['video_mobile_mode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" muted playsinline preload="none" aria-hidden="true" tabindex="-1"'
            . $posterUrl . ' data-blox-video-src="'
            . htmlspecialchars($item['video'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '"></video>';
    }

    /** @param array<string, mixed> $data */
    public static function responsiveLinkedMediaHtml(array $data): string
    {
        $item = self::normalize($data);
        $media = self::responsiveMediaHtml($item);
        if ($item['link_url'] === '') {
            return $media;
        }

        return '<a href="' . htmlspecialchars($item['link_url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" target="' . htmlspecialchars($item['link_target'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '" class="block w-full h-full">' . $media . '</a>';
    }

    /** @psalm-api Theme template entry point. */
    public static function registerRuntimeAssets(): void
    {
        BloxAssetCollector::addStyle('/assets/css/blox-banner.css');
        BloxAssetCollector::addScript('/assets/js/blox-video-policy.js');
        BloxAssetCollector::addScript('/assets/js/blox-banner.js');
    }

    /** @param array<string, mixed> $banner @return array<string, mixed> */
    public static function fromLegacy(array $banner): array
    {
        return self::normalize($banner);
    }

    /**
     * 只替换语言相关内容，保留 Blox 中设置的图片和逐张动效。
     * 新文档按翻译组匹配；没有来源标识的旧文档按原顺序回退。
     *
     * @param array<int, array<string, mixed>> $customItems
     * @param array<int, array<string, mixed>> $localizedBanners
     * @return array<int, array<string, mixed>>
     */
    public static function applyLocalizedContent(array $customItems, array $localizedBanners, ?string $language = null): array
    {
        $language ??= siteLang();
        $localizedItems = [];
        $localizedByGroup = [];
        foreach ($localizedBanners as $banner) {
            if (!is_array($banner)) {
                continue;
            }
            $item = self::fromLegacy($banner);
            $localizedItems[] = $item;
            $groupId = (int) ($item['translation_group_id'] ?? 0);
            if ($groupId > 0) {
                $localizedByGroup[$groupId] = $item;
            }
        }

        foreach ($customItems as $index => &$customItem) {
            if (!is_array($customItem)) {
                continue;
            }
            $overrides = $customItem[self::I18N_KEY][$language] ?? null;
            $overrides = is_array($overrides) ? $overrides : [];
            $edit = $customItem[self::EDIT_KEY] ?? null;
            $shown = is_array($edit) && ($edit['lang'] ?? '') === $language && is_array($edit['shown'] ?? null) ? $edit['shown'] : [];
            unset($customItem[self::I18N_KEY], $customItem[self::EDIT_KEY]);
            $groupId = (int) ($customItem['translation_group_id'] ?? 0);
            $localizedItem = $groupId > 0
                ? ($localizedByGroup[$groupId] ?? null)
                : ($localizedItems[$index] ?? null);
            foreach (self::LOCALIZED_FIELDS as $field) {
                if (array_key_exists($field, $shown) && is_scalar($shown[$field])
                    && $customItem[$field] !== self::normalize([$field => (string) $shown[$field]])[$field]) {
                    continue; // 编辑器里刚改、尚未保存：画布显示正在编辑的值
                }
                if (is_string($overrides[$field] ?? null)) {
                    $customItem[$field] = self::normalize([$field => $overrides[$field]])[$field];
                } elseif (is_array($localizedItem)) {
                    $customItem[$field] = $localizedItem[$field];
                }
            }
        }
        unset($customItem);

        return $customItems;
    }

    /**
     * @param array<int, mixed> $children
     * @return array<int, array<string, mixed>>
     */
    /**
     * inherit 模式下给轮播图记录补上逐条动效。
     *
     * inherit 的分工是：文字、图片、按钮按语言取自 banners 表；每一张的入场/背景动效
     * 则由首页文档里同位置的子项决定——编辑器在 inherit 模式下也一直按位置展示并允许
     * 修改这些子项的动效。前台过去只拿表里的行，而表里根本没有动效字段，于是编辑器
     * 里设好的逐条动效在前台全部丢失，只剩分组级的统一设置（新装站默认 none）。
     *
     * 只补表行没有的动效；子项写 inherit 的不覆盖，照旧跟随分组设置。
     *
     * @param list<array<string, mixed>> $banners
     * @param array<int, mixed> $children
     * @return list<array<string, mixed>>
     */
    public static function applyChildMotions(array $banners, array $children): array
    {
        $items = self::normalizeChildren($children);
        foreach ($banners as $position => $banner) {
            if (!isset($items[$position])) {
                break;
            }
            foreach (['content_motion', 'background_motion'] as $key) {
                $motion = (string) ($items[$position][$key] ?? 'inherit');
                $current = (string) ($banner[$key] ?? 'inherit');
                if ($motion !== 'inherit' && $current === 'inherit') {
                    $banners[$position][$key] = $motion;
                }
            }
        }
        return $banners;
    }

    public static function normalizeChildren(array $children, string $parentPath = ''): array
    {
        $items = [];
        foreach ($children as $index => $child) {
            if (!is_array($child) || ($child['type'] ?? '') !== 'home-banner-item') {
                continue;
            }
            $data = is_array($child['data'] ?? null) ? $child['data'] : [];
            $item = self::normalize($data);
            foreach ([self::I18N_KEY, self::EDIT_KEY] as $key) {
                if (is_array($data[$key] ?? null)) {
                    $item[$key] = $data[$key];
                }
            }
            if ($parentPath !== '') {
                $item['_blox_path'] = $parentPath . '.' . (int) $index;
            }
            $items[] = $item;
        }
        return $items;
    }

    /**
     * 首页编辑器：轮播文字与画布一致（见 HomeBloxRenderContext）。
     * 继承模式始终显示该语言的轮播图记录；自定义模式只有非默认语言才替换。
     * 非默认语言加编辑标记，保存时改动写入该语言覆盖；默认语言的改动就是共享内容本身。
     * @param array<int,mixed> $sections @param array<int,array<string,mixed>> $localizedBanners @return array<int,mixed>
     */
    public static function forEditor(array $sections, array $localizedBanners, string $language, bool $isDefaultLanguage = false): array
    {
        return self::walkBannerChildren($sections, static function (array $children, array $host) use ($localizedBanners, $language, $isDefaultLanguage): array {
            $custom = ($host['items_mode'] ?? 'inherit') === 'custom';
            if ($custom && $isDefaultLanguage) {
                return $children;
            }
            $indexes = [];
            foreach ($children as $index => $child) {
                if (is_array($child) && ($child['type'] ?? '') === 'home-banner-item') {
                    $indexes[] = $index;
                }
            }
            $items = self::normalizeChildren($children);
            $localized = self::applyLocalizedContent($items, $localizedBanners, $language);
            foreach ($indexes as $position => $index) {
                $data = is_array($children[$index]['data'] ?? null) ? $children[$index]['data'] : [];
                $shown = [];
                $source = [];
                foreach (self::LOCALIZED_FIELDS as $field) {
                    if (($localized[$position][$field] ?? null) === ($items[$position][$field] ?? null)) {
                        continue;
                    }
                    $source[$field] = array_key_exists($field, $data) ? $data[$field] : null;
                    $shown[$field] = $localized[$position][$field];
                    $data[$field] = $shown[$field];
                }
                if ($shown !== []) {
                    if (!$isDefaultLanguage) {
                        $data[self::EDIT_KEY] = ['lang' => $language, 'shown' => $shown, 'source' => $source];
                    }
                    $children[$index]['data'] = $data;
                }
            }
            return $children;
        });
    }

    /** 保存管线之前：比对原始输入与显示值，记下真正改过的字段。 @param array<int,mixed> $sections @return array<int,mixed> */
    public static function markEditorChanges(array $sections): array
    {
        return self::walkBannerChildren($sections, static function (array $children): array {
            foreach ($children as $index => $child) {
                $edit = is_array($child) ? ($child['data'][self::EDIT_KEY] ?? null) : null;
                if (!is_array($edit) || !is_array($edit['shown'] ?? null)) {
                    continue;
                }
                $edit['changed'] = [];
                foreach ($edit['shown'] as $field => $value) {
                    if (($child['data'][$field] ?? null) !== $value) {
                        $edit['changed'][] = (string) $field;
                    }
                }
                unset($edit['shown']);
                $children[$index]['data'][self::EDIT_KEY] = $edit;
            }
            return $children;
        });
    }

    /** 保存管线之后：改过的字段（已净化）写入该语言覆盖，共享文档还原为原文。 @param array<int,mixed> $sections @return array<int,mixed> */
    public static function fromEditor(array $sections): array
    {
        return self::walkBannerChildren($sections, static function (array $children): array {
            foreach ($children as $index => $child) {
                if (!is_array($child) || !is_array($child['data'] ?? null) || !array_key_exists(self::EDIT_KEY, $child['data'])) {
                    continue;
                }
                $data = $child['data'];
                $edit = $data[self::EDIT_KEY];
                unset($data[self::EDIT_KEY]);
                $language = is_array($edit) && is_string($edit['lang'] ?? null) ? $edit['lang'] : '';
                if (preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/D', $language) && is_array($edit['source'] ?? null)) {
                    $changed = (array) ($edit['changed'] ?? []);
                    foreach ($edit['source'] as $field => $original) {
                        if (!in_array($field, self::LOCALIZED_FIELDS, true)) {
                            continue;
                        }
                        if (in_array($field, $changed, true) && is_string($data[$field] ?? null)) {
                            $data[self::I18N_KEY][$language][$field] = $data[$field];
                        }
                        if ($original === null) {
                            unset($data[$field]);
                        } else {
                            $data[$field] = (string) $original;
                        }
                    }
                }
                $children[$index]['data'] = $data;
            }
            return $children;
        });
    }

    /** @param array<int,mixed> $sections @param callable(array<int,mixed>,array<string,mixed>):array<int,mixed> $visit @return array<int,mixed> */
    private static function walkBannerChildren(array $sections, callable $visit): array
    {
        foreach ($sections as $si => $section) {
            foreach (is_array($section['columns'] ?? null) ? $section['columns'] : [] as $ci => $column) {
                foreach (is_array($column['elements'] ?? null) ? $column['elements'] : [] as $ei => $element) {
                    if (!is_array($element) || ($element['type'] ?? '') !== 'home-block'
                        || ($element['data']['block_type'] ?? '') !== 'banner' || !is_array($element['data']['children'] ?? null)) {
                        continue;
                    }
                    $sections[$si]['columns'][$ci]['elements'][$ei]['data']['children'] = $visit($element['data']['children'], $element['data']);
                }
            }
        }
        return $sections;
    }

    private static function safeUrl(string $value, bool $allowActionSchemes): string
    {
        // v1.18.6 起委托 UrlPolicy；strip_tags 前处理保留（banner 字段可能贴入富文本）
        return UrlPolicy::href(strip_tags($value), $allowActionSchemes);
    }
}
