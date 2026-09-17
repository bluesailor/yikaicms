<?php
/** 免费期内随核心包提供易开网页构建器 Pro 作者端模块（插件 ID yikai-builder）：为已有站点补登记并启用。 */

declare(strict_types=1);

return [
    'id' => '20260914_enable_blox_pro_editor_modules',
    'title' => '默认启用易开网页构建器 Pro 作者端模块',
    'desc' => '显示条件等作者端面板迁入 yikai-builder 插件；仅为尚未登记的已有站点补安装并启用，不覆盖管理员已停用的状态。开发期曾以 blox-pro 登记的站点改为新 ID 并保留启用状态。',
    'title_en' => 'Enable Yikai Builder Pro authoring modules by default',
    'title_ja' => 'Yikai ビルダー Pro 編集モジュールを既定で有効化',
    'desc_en' => 'Authoring panels such as display conditions now live in the yikai-builder plugin; it is registered and enabled only when missing, and an administrator-disabled state is preserved. Sites registered under the earlier blox-pro ID are moved to the new ID with their state kept.',
    'desc_ja' => '表示条件などの編集パネルは yikai-builder プラグインに移りました。未登録の場合のみ登録・有効化し、管理者が無効化した状態は保持します。以前の blox-pro ID で登録済みのサイトは状態を保ったまま新しい ID に移行します。',
    'check' => static function (): bool {
        try {
            if (!db()->tableExists('plugins')) {
                return true;
            }
            return pluginModel()->findBySlug('yikai-builder') !== null
                && pluginModel()->findBySlug('blox-pro') === null;
        } catch (Throwable) {
            return false;
        }
    },
    'php' => static function (): string {
        if (!db()->tableExists('plugins')) {
            return '插件表尚未创建，已跳过易开网页构建器 Pro 作者端模块登记。';
        }

        $legacy = pluginModel()->findBySlug('blox-pro');
        $current = pluginModel()->findBySlug('yikai-builder');
        if ($legacy !== null) {
            if ($current === null) {
                db()->update('plugins', ['slug' => 'yikai-builder'], 'slug = ?', ['blox-pro']);
                return '插件 ID 已由 blox-pro 改为 yikai-builder，保留原启用状态。';
            }
            pluginModel()->deleteBySlug('blox-pro');
            return '已移除旧插件 ID blox-pro，保留 yikai-builder 现有状态。';
        }

        if ($current !== null) {
            return '易开网页构建器 Pro 作者端模块已登记，保留现有启用状态。';
        }

        pluginModel()->activate('yikai-builder');
        return '易开网页构建器 Pro 作者端模块已安装并启用。';
    },
];
