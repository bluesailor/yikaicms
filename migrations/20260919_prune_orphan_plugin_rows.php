<?php
/**
 * 清理插件表里没有目录的登记。
 *
 * 安装种子曾把 logo-maker、product-carousel 登记为启用，但这两个插件早已移出核心包、改走插件市场；
 * v1.20.0 的种子还登记了 yikai-builder（v1.20.1 起改为授权插件，不随包分发）。新装站点因此留下
 * 找不到目录的启用记录。只处理这三个 slug，并且只在插件目录确实不存在时删除——站点从插件市场
 * 装过的照常保留。
 */

declare(strict_types=1);

$orphanPluginSlugs = static function (): array {
    $orphans = [];
    foreach (['logo-maker', 'product-carousel', 'yikai-builder'] as $slug) {
        if (pluginModel()->findBySlug($slug) !== null && !is_file(ROOT_PATH . '/plugins/' . $slug . '/plugin.json')) {
            $orphans[] = $slug;
        }
    }
    return $orphans;
};

return [
    'id' => '20260919_prune_orphan_plugin_rows',
    'title' => '清理没有插件目录的插件登记',
    'desc' => '删除 logo-maker、product-carousel、yikai-builder 三个插件中目录不存在的登记记录（多为安装种子遗留）；已从插件市场安装的插件不受影响。',
    'title_en' => 'Remove plugin records without a plugin directory',
    'title_ja' => 'ディレクトリのないプラグイン登録を削除',
    'desc_en' => 'Deletes records for logo-maker, product-carousel and yikai-builder whose plugin directory is missing (mostly left by the install seed). Plugins installed from the market are not affected.',
    'desc_ja' => 'logo-maker、product-carousel、yikai-builder のうち、プラグインディレクトリが存在しない登録を削除します（主にインストール時の初期データの残り）。マーケットからインストールしたプラグインには影響しません。',
    'check' => static function () use ($orphanPluginSlugs): bool {
        try {
            return !db()->tableExists('plugins') || $orphanPluginSlugs() === [];
        } catch (Throwable) {
            return false;
        }
    },
    'php' => static function () use ($orphanPluginSlugs): string {
        if (!db()->tableExists('plugins')) {
            return '插件表尚未创建，已跳过。';
        }
        $orphans = $orphanPluginSlugs();
        foreach ($orphans as $slug) {
            pluginModel()->deleteBySlug($slug);
        }
        return $orphans === [] ? '没有需要清理的插件登记。' : '已删除没有目录的插件登记：' . implode('、', $orphans) . '。';
    },
];
