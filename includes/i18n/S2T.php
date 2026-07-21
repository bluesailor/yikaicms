<?php
/**
 * Yikai CMS - 简体→繁体（台湾用词）整页转换器
 *
 * 繁体中文（zh-TW）在本 CMS 中是简体（zh-CN）的「渲染视图」：
 * 底层内容与 UI 文案全部复用简体那一套，出页面前由本类对整页 HTML
 * 做一次简→繁转换（OpenCC 词库：STCharacters/STPhrases + TWPhrases/TWVariants*）。
 * 好处：站点零重复录入，简体改了繁体自动同步。
 *
 * 由 init.php 在 SITE_LANG==='zh-TW' 时 ob_start(['S2T','convertOutput']) 挂载。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class S2T
{
    /** @var array{p1: array<string,string>, p2: array<string,string>}|null */
    private static ?array $maps = null;

    /** 懒加载映射表（p1 简→繁，p2 繁→台湾用词） */
    private static function maps(): array
    {
        if (self::$maps === null) {
            $file = __DIR__ . '/s2t_maps.php';
            $m = is_file($file) ? require $file : null;
            self::$maps = (is_array($m) && isset($m['p1'], $m['p2']))
                ? $m : ['p1' => [], 'p2' => []];
        }
        return self::$maps;
    }

    /**
     * 转换一段文本：两趟 strtr（简→繁 → 繁→台湾用词）。
     * 保护 <script>/<style> 块内容不被转换（避免破坏 JS 字符串键/CSS）。
     */
    public static function convert(string $s): string
    {
        if ($s === '') return $s;
        $maps = self::maps();
        if ($maps['p1'] === []) return $s;

        $parts = preg_split(
            '#(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<!--.*?-->)#is',
            $s, -1, PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return strtr(strtr($s, $maps['p1']), $maps['p2']);
        }
        $out = '';
        foreach ($parts as $i => $seg) {
            // 奇数段是被捕获的 script/style/注释，原样保留
            $out .= ($i % 2 === 1) ? $seg : strtr(strtr($seg, $maps['p1']), $maps['p2']);
        }
        return $out;
    }

    /**
     * 输出缓冲回调：整页 HTML 转换入口。
     * 跳过明显的非 HTML 响应（JSON），避免误转接口数据。
     */
    public static function convertOutput(string $html): string
    {
        if ($html === '') return $html;
        $head = ltrim($html);
        if ($head !== '' && ($head[0] === '{' || $head[0] === '[')) {
            return $html;   // JSON 响应，不转
        }
        return self::convert($html);
    }
}
