<?php
/**
 * 预置组件（v2.1.0）：功能卡片、价格卡片、评价卡片、行动号召条、团队成员、常见问题条目、图标列表项、联系卡片。
 * 只补本站没有的（按组件 uuid），装过或作者改过的不动。全新安装由安装器跑迁移时装上。
 */

declare(strict_types=1);

require_once ROOT_PATH . '/includes/builder/bootstrap.php';

return [
    'id' => '20261011_starter_components',
    'title' => '添加预置组件',
    'desc' => '组件库补上 8 个可直接使用的组件（功能卡片、价格卡片、评价卡片、行动号召条、团队成员、常见问题条目、图标列表项、联系卡片），带中英日默认文字；已有的不覆盖。',
    'title_en' => 'Add starter components',
    'title_ja' => 'プリセットコンポーネントを追加',
    'desc_en' => 'Adds 8 ready-to-use components to the component library (feature card, pricing card, testimonial card, call-to-action bar, team member, FAQ item, icon list item, contact card) with Chinese, English and Japanese defaults. Existing ones are not overwritten.',
    'desc_ja' => 'コンポーネントライブラリに、すぐ使える8つのコンポーネント（機能カード、料金カード、お客様の声カード、CTAバー、チームメンバー、FAQ項目、アイコンリスト項目、連絡先カード）を中・英・日の初期文言付きで追加します。既存のものは上書きしません。',
    'check' => static fn (): bool => BloxComponentPresets::missing() === [],
    'php' => static function (): string {
        $installed = BloxComponentPresets::installMissing();
        if ($installed > 0 && class_exists('HtmlCache')) {
            HtmlCache::invalidate();
        }
        return 'installed ' . $installed;
    },
];
