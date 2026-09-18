<?php

declare(strict_types=1);

/**
 * 首页「客户评价」轮播（testimonial-carousel 元素）的多语言绑定。
 *
 * 这个元素多半是从区块模板插进首页的普通元素，文案只存了一种语言；绑定里带上其它语言的
 * 译文，英文、日文首页才不会照样显示中文。机制见 HomeItemListLocalization。
 */
final class HomeTestimonialsContent extends HomeItemListLocalization
{
    public const KEY = '_home_testimonials_i18n';
    public const EDIT_KEY = '_home_testimonials_edit';

    protected static function bindingKey(): string
    {
        return self::KEY;
    }

    protected static function editKey(): string
    {
        return self::EDIT_KEY;
    }

    protected static function elementType(): string
    {
        return 'testimonial-carousel';
    }

    /** 头像、评分是跨语言共用的，只翻译姓名、职务与评价正文。 */
    protected static function itemFields(): array
    {
        return ['name', 'role', 'content'];
    }
}
