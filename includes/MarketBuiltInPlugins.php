<?php

declare(strict_types=1);

/**
 * 插件市场里「已并入主程序」的条目（2.3.1）。
 *
 * 更新服务器的目录按原样下发（旧客户端、2.3.0 以前的站点照常能装这些插件），
 * 由本站决定怎么显示：本站已内置的插件不给安装/升级按钮、不留下载信息，
 * 改成「已内置」并带上内置页面的地址。哪些插件已内置、新位置在哪，见 includes/plugin.php 的
 * retiredPlugins() / retiredPluginRedirect()；安装链（PluginMarketInstall::install）另有同一份拦截。
 */
final class MarketBuiltInPlugins
{
    /**
     * @param array<string,mixed> $item 市场目录的一条
     * @param list<string> $retired retiredPlugins()
     * @param null|string $url 内置页面的站内地址（已带子目录前缀）；当前账号进不去时传 null，只显示「已内置」
     * @return array<string,mixed>
     */
    public static function decorate(array $item, array $retired, ?string $url): array
    {
        if (!self::matches($item, $retired)) return $item;
        $item['built_in'] = true;
        $item['built_in_url'] = $url ?? '';
        $item['download_url'] = '';
        // 本站不会再下载它：不留包名、校验值与大小，也不显示下载受限的提示
        unset($item['package'], $item['hash'], $item['sig'], $item['size_kb'], $item['locked_reason']);
        return $item;
    }

    /** @param array<string,mixed> $item @param list<string> $retired */
    public static function matches(array $item, array $retired): bool
    {
        $slug = $item['slug'] ?? null;
        return is_string($slug) && in_array($slug, $retired, true);
    }
}
