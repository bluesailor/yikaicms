<?php

declare(strict_types=1);

/**
 * 控制台「推荐安装」插件清单。
 *
 * 由来（2026-08-22）：logo-maker 的图标库有 7618 个 SVG，占发行包 91% 的文件数，
 * 而多数站点用不到。共享主机升级是逐文件写入，文件数直接决定耗时与失败概率，
 * 所以它移出核心包改走插件市场——但「不预装」不等于「找不到」：登录后由控制台
 * 推荐卡引导安装，一键跳到市场对应条目。
 *
 * 清单是产品决策，不是配置项：新拆出的插件在这里登记即可，卡片与忽略逻辑通用。
 * 每条只登记 slug 与展示用的语言键；名称/描述以市场返回的为准，这里只做引导。
 */
final class RecommendedPlugins
{
    /** 忽略记录存这个设置键（JSON 数组，元素为 slug） */
    private const DISMISS_KEY = 'recommended_plugins_dismissed';

    /** @var array<int, array{slug: string, icon: string, label: string, desc: string}> */
    private const ITEMS = [
        [
            'slug' => 'logo-maker',
            'icon' => 'ti-palette',
            'label' => 'rec_plugin_logo_maker',
            'desc' => 'rec_plugin_logo_maker_desc',
        ],
        [
            'slug' => 'seo',
            'icon' => 'ti-chart-arcs',
            'label' => 'rec_plugin_seo',
            'desc' => 'rec_plugin_seo_desc',
        ],
    ];

    /**
     * 待推荐项：未安装 + 未被忽略。已安装的插件永远不再推荐。
     *
     * @return array<int, array{slug: string, icon: string, label: string, desc: string}>
     */
    public static function pending(): array
    {
        $dismissed = self::dismissed();
        $out = [];
        foreach (self::ITEMS as $item) {
            if (in_array($item['slug'], $dismissed, true)) {
                continue;
            }
            if (self::isInstalled($item['slug'])) {
                continue;
            }
            $out[] = $item;
        }
        return $out;
    }

    /** 插件目录存在即视为已安装（与 admin/plugin.php 的判定口径一致）。 */
    public static function isInstalled(string $slug): bool
    {
        if (preg_match('/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?$/', $slug) !== 1) {
            return false;
        }
        return is_dir(ROOT_PATH . '/plugins/' . $slug);
    }

    /** 记下「不再提示」。未知 slug 直接忽略，不写脏数据。 */
    public static function dismiss(string $slug): void
    {
        if (!in_array($slug, array_column(self::ITEMS, 'slug'), true)) {
            return;
        }
        $dismissed = self::dismissed();
        if (in_array($slug, $dismissed, true)) {
            return;
        }
        $dismissed[] = $slug;
        settingModel()->set(self::DISMISS_KEY, json_encode(array_values($dismissed)), 'system');
    }

    /** @return array<int, string> */
    private static function dismissed(): array
    {
        $raw = (string) config(self::DISMISS_KEY, '');
        if ($raw === '') {
            return [];
        }
        $list = json_decode($raw, true);
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }
}
