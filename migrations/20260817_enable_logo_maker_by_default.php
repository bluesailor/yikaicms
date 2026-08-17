<?php
/** 为已有站点补登记并默认启用随核心包提供的 LOGO 制作插件。 */

declare(strict_types=1);

return [
    'id' => '20260817_enable_logo_maker_by_default',
    'title' => '默认安装并启用 LOGO 制作',
    'desc' => '随核心包提供 logo-maker 插件；仅为尚未登记的已有站点补安装并启用，不覆盖管理员已停用的状态。',
    'title_en' => 'Install and enable Logo Maker by default',
    'title_ja' => 'Logo Maker を既定でインストールして有効化',
    'desc_en' => 'Registers and enables the bundled logo-maker plugin only when it is missing; an administrator-disabled state is preserved.',
    'desc_ja' => '同梱の logo-maker が未登録の場合のみ登録・有効化し、管理者が無効化した状態は保持します。',
    'check' => static function (): bool {
        try {
            if (!db()->tableExists('plugins')) {
                return true;
            }
            return pluginModel()->findBySlug('logo-maker') !== null;
        } catch (Throwable) {
            return false;
        }
    },
    'php' => static function (): string {
        if (!db()->tableExists('plugins')) {
            return '插件表尚未创建，已跳过 LOGO 制作插件登记。';
        }

        if (pluginModel()->findBySlug('logo-maker') !== null) {
            return 'LOGO 制作插件已登记，保留现有启用状态。';
        }

        pluginModel()->activate('logo-maker');
        return 'LOGO 制作插件已安装并启用。';
    },
];
