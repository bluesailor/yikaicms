<?php

declare(strict_types=1);

final class HomeAboutContent
{
    /**
     * Resolve the same values for the theme, editor hints and legacy import.
     * Runtime block overrides are already applied by HomeBloxRenderContext.
     * @param array<string, mixed>|null $aboutChannel
     * @return array<string, string>
     */
    public static function resolve(?array $aboutChannel = null): array
    {
        $title = trim((string) (configJsonLang('home_about_title') ?: config('home_about_title', '')));
        $link = (string) config('home_about_link', '');
        return [
            'override_title' => $title !== '' ? $title : homeAboutDefaultTitle(),
            'override_content' => configLang('home_about_content', 'home_about_default'),
            'override_image' => (string) config('home_about_image', '/assets/images/demo/about-office.jpg'),
            'override_tag_title' => (string) (configJsonLang('home_about_tag_title') ?: config('home_about_tag_title', '')),
            'override_tag_description' => (string) (configJsonLang('home_about_tag_desc') ?: config('home_about_tag_desc', '')),
            'override_button_text' => (string) (config('home_about_button', '') ?: __('home_learn_more')),
            'override_button_url' => $aboutChannel ? ($link ?: channelUrl($aboutChannel)) : $link,
        ];
    }
}
