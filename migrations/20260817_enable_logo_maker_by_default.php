<?php
/**
 * 已退役：曾为已有站点补登记并默认启用随核心包提供的 LOGO 制作插件。
 *
 * logo-maker 自 2026-08-22 起不随核心包、改走插件市场按需安装，「默认启用」不再成立；
 * 继续登记只会给没有插件目录的站点留下孤儿记录（见 20260919_prune_orphan_plugin_rows）。
 * 保留迁移 ID，check 恒为已完成，已跑过与未跑过的站点都不再做任何改动。
 */

declare(strict_types=1);

return [
    'id' => '20260817_enable_logo_maker_by_default',
    'title' => '默认安装并启用 LOGO 制作（已退役）',
    'desc' => 'LOGO 制作已改为插件市场按需安装，本迁移不再登记或启用插件。',
    'title_en' => 'Install and enable Logo Maker by default (retired)',
    'title_ja' => 'Logo Maker を既定でインストールして有効化（廃止）',
    'desc_en' => 'Logo Maker is now installed on demand from the plugin market; this migration no longer registers or enables it.',
    'desc_ja' => 'Logo Maker はプラグインマーケットから必要に応じてインストールする方式に変わりました。この移行ではプラグインの登録・有効化を行いません。',
    'check' => static fn(): bool => true,
    'php' => static fn(): string => 'LOGO 制作已改为插件市场按需安装，无需处理。',
];
