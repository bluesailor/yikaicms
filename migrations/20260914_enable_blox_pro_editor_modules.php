<?php
/** 免费期内随核心包提供 BLOX 专业作者端模块：为已有站点补登记并启用。 */

declare(strict_types=1);

return [
    'id' => '20260914_enable_blox_pro_editor_modules',
    'title' => '默认启用 BLOX 专业作者端模块',
    'desc' => '显示条件等作者端面板迁入 blox-pro 插件；仅为尚未登记的已有站点补安装并启用，不覆盖管理员已停用的状态。',
    'title_en' => 'Enable BLOX Pro authoring modules by default',
    'title_ja' => 'BLOX Pro 編集モジュールを既定で有効化',
    'desc_en' => 'Authoring panels such as display conditions now live in the blox-pro plugin; it is registered and enabled only when missing, and an administrator-disabled state is preserved.',
    'desc_ja' => '表示条件などの編集パネルは blox-pro プラグインに移りました。未登録の場合のみ登録・有効化し、管理者が無効化した状態は保持します。',
    'check' => static function (): bool {
        try {
            if (!db()->tableExists('plugins')) {
                return true;
            }
            return pluginModel()->findBySlug('blox-pro') !== null;
        } catch (Throwable) {
            return false;
        }
    },
    'php' => static function (): string {
        if (!db()->tableExists('plugins')) {
            return '插件表尚未创建，已跳过 BLOX 专业作者端模块登记。';
        }

        if (pluginModel()->findBySlug('blox-pro') !== null) {
            return 'BLOX 专业作者端模块已登记，保留现有启用状态。';
        }

        pluginModel()->activate('blox-pro');
        return 'BLOX 专业作者端模块已安装并启用。';
    },
];
