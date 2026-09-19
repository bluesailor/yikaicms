<?php
/**
 * 易开网页构建器 Pro 旧插件 ID（开发期 blox-pro → yikai-builder）改名。
 *
 * v1.20.0 免费期曾在这里为已有站点补登记并启用 yikai-builder；v1.20.1 起它改为授权插件、
 * 不随核心包分发，升级时再登记只会留下没有目录的孤儿记录，所以只保留改名。迁移 ID 不变，
 * 已跑过的站点不会重跑（v1.20.0 无站点安装记录，2026-09-19 确认）。
 */

declare(strict_types=1);

return [
    'id' => '20260914_enable_blox_pro_editor_modules',
    'title' => '迁移易开网页构建器 Pro 旧插件 ID',
    'desc' => '开发期曾以 blox-pro 登记的站点改为新 ID yikai-builder，并保留原启用状态；未登记的站点不做任何改动。',
    'title_en' => 'Move the legacy Yikai Builder Pro plugin ID',
    'title_ja' => 'Yikai ビルダー Pro の旧プラグイン ID を移行',
    'desc_en' => 'Sites registered under the earlier blox-pro ID are moved to yikai-builder with their state kept; other sites are left unchanged.',
    'desc_ja' => '以前の blox-pro ID で登録済みのサイトは状態を保ったまま yikai-builder に移行します。それ以外のサイトは変更しません。',
    'check' => static function (): bool {
        try {
            if (!db()->tableExists('plugins')) {
                return true;
            }
            return pluginModel()->findBySlug('blox-pro') === null;
        } catch (Throwable) {
            return false;
        }
    },
    'php' => static function (): string {
        if (!db()->tableExists('plugins')) {
            return '插件表尚未创建，已跳过易开网页构建器 Pro 旧插件 ID 迁移。';
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

        return '没有旧插件 ID blox-pro，无需迁移。';
    },
];
