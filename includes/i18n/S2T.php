<?php
/**
 * Yikai CMS - 简体→繁体（台湾用词）整页转换器
 *
 * 繁体中文（zh-TW）在本 CMS 中是简体（zh-CN）的「渲染视图」：
 * 底层内容与 UI 文案全部复用简体那一套，出页面前由本类对整页 HTML
 * 做一次简→繁转换（OpenCC 词库：STCharacters/STPhrases + TWPhrases/TWVariants*）。
 * 好处：站点零重复录入，简体改了繁体自动同步。
 *
 * 由 init.php 在 SITE_LANG==='zh-TW' 时 ob_start(['S2T','convertOutput']) 挂载（/api/ 不挂）。
 *
 * 转换范围（2.0.3 起）：正文、行内 <script>（结构化数据、界面文案字典、灯箱标题等都在里面）、
 * 前台 AJAX 的 JSON 响应（表单提交提示等）。映射只把汉字换成汉字，不会破坏 JS / JSON 语法；
 * 脚本里 \uXXXX 转义的汉字也一并处理。
 * 不转：<style>、HTML 注释、带 data-s2t="skip" 的 <script>（会原样回传给服务器比对的数据，
 * 如商城的大陆地区树），以及发了 `X-S2T: skip` 响应头的响应。
 * 页面上已转成繁体、又会提交回来的选项值，由 S2T::canonical() 映射回简体原值。
 *
 * PHP 8.0+
 */

declare(strict_types=1);

final class S2T
{
    /** @var array{p1: array<string,string>, p2: array<string,string>}|null */
    private static ?array $maps = null;

    /** 繁体语言包插件的目录名（插件市场 slug） */
    public const PACK_PLUGIN = 'zh-tw';

    /**
     * 映射表文件：2.0.4 前安装的站点核心目录里自带一份（升级不删）；之后的安装包不再自带，
     * 由「繁體中文語言包」插件提供。两处都没有时返回 null，繁体页面按简体原样输出。
     */
    public static function mapsFile(): ?string
    {
        $candidates = [__DIR__ . '/s2t_maps.php', dirname(__DIR__, 2) . '/plugins/' . self::PACK_PLUGIN . '/s2t_maps.php'];
        foreach ($candidates as $file) {
            if (is_file($file)) return $file;
        }
        return null;
    }

    /** 是否装有转换表（后台据此提示安装语言包） */
    public static function available(): bool
    {
        return self::mapsFile() !== null;
    }

    /** 懒加载映射表（p1 简→繁，p2 繁→台湾用词） */
    private static function maps(): array
    {
        if (self::$maps === null) {
            $file = self::mapsFile();
            $m = $file !== null ? require $file : null;
            /** @var array{p1: array<string, string>, p2: array<string, string>} $maps */
            $maps = (is_array($m) && isset($m['p1'], $m['p2']))
                ? $m : ['p1' => [], 'p2' => []];
            // 台湾用词里有几条结果包含原词（算法→演算法、虚拟机→虛擬機器）：已是繁体的文字再转一次会变成
            // 「演演算法」。给这些结果补一条原样映射，strtr 取最长匹配，转过的文字就不再变。
            foreach ($maps['p2'] as $from => $to) {
                if ($to !== $from && str_contains($to, (string) $from)) {
                    $maps['p2'][$to] ??= $to;
                }
            }
            self::$maps = $maps;
        }
        return self::$maps;
    }

    /** 纯文本两趟 strtr（简→繁 → 繁→台湾用词）。 */
    public static function text(string $s): string
    {
        if ($s === '') return $s;
        $maps = self::maps();
        if ($maps['p1'] === []) return $s;
        return strtr(strtr($s, $maps['p1']), $maps['p2']);
    }

    /**
     * 转换一段 HTML。<style> 与注释原样保留；<script> 按 convertScript() 处理。
     */
    public static function convert(string $s): string
    {
        if ($s === '') return $s;
        if (self::maps()['p1'] === []) return $s;

        $parts = preg_split(
            '#(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<!--.*?-->)#is',
            $s, -1, PREG_SPLIT_DELIM_CAPTURE
        );
        if ($parts === false) {
            return self::text($s);
        }
        $out = '';
        foreach ($parts as $i => $seg) {
            if ($i % 2 === 0) {
                $out .= self::text($seg);
            } elseif (preg_match('#^<script\b([^>]*)>(.*)</script>$#is', $seg, $m) === 1) {
                $out .= preg_match('/\bdata-s2t\s*=\s*["\']?skip\b/i', $m[1]) === 1
                    ? $seg
                    : '<script' . $m[1] . '>' . self::convertScript($m[2]) . '</script>';
            } else {
                $out .= $seg;   // <style>、注释
            }
        }
        return $out;
    }

    /** 脚本 / JSON 文本：直接出现的汉字与 \uXXXX 转义的汉字都转换（转义的转完仍写成转义）。 */
    public static function convertScript(string $code): string
    {
        if ($code === '') return $code;
        $code = self::text($code);
        return (string) preg_replace_callback('/(?:\\\\u(?:[34][0-9a-fA-F]{3}|[5-9][0-9a-fA-F]{3}))+/', static function (array $m): string {
            $decoded = json_decode('"' . $m[0] . '"');
            if (!is_string($decoded)) return $m[0];
            $converted = self::text($decoded);
            if ($converted === $decoded) return $m[0];
            return substr((string) json_encode($converted), 1, -1);
        }, $code);
    }

    /** @var array{tw: array<string,string>, trad: array<string,string>}|null 繁→简单字反查表（由 p2、p1 的单字条目反转而来） */
    private static ?array $reverse = null;

    /**
     * 繁体（含台湾用字）→ 简体，按单字反查。只用于把访客输入的搜索词对到简体内容上，
     * 不追求词组级精确（如「軟體」得到「软体」而不是「软件」）。
     */
    public static function toSimplified(string $s): string
    {
        if ($s === '') return $s;
        if (self::$reverse === null) {
            $maps = self::maps();
            $tw = [];
            foreach ($maps['p2'] as $from => $to) {
                if (mb_strlen($from) === 1 && mb_strlen($to) === 1 && $from !== $to) $tw[$to] = $from;
            }
            $trad = [];
            foreach ($maps['p1'] as $from => $to) {
                if (mb_strlen($from) === 1 && mb_strlen($to) === 1 && $from !== $to && !isset($trad[$to])) $trad[$to] = $from;
            }
            // 只在词组里出现的繁体字（如「聯繫」的「繫」）：按等长词组逐字补上，单字条目优先
            foreach ($maps['p1'] as $from => $to) {
                $len = mb_strlen($from);
                if ($len < 2 || $len !== mb_strlen($to)) continue;
                $f = mb_str_split($from);
                $t = mb_str_split($to);
                foreach ($t as $i => $char) {
                    if ($char !== $f[$i] && !isset($trad[$char])) $trad[$char] = $f[$i];
                }
            }
            self::$reverse = ['tw' => $tw, 'trad' => $trad];
        }
        return strtr(strtr($s, self::$reverse['tw']), self::$reverse['trad']);
    }

    /**
     * 提交回来的值如果是页面上某个选项的繁体形式，映射回简体原值；否则原样返回。
     *
     * @param list<string> $allowed 服务器端（简体）的合法值
     */
    public static function canonical(string $value, array $allowed): string
    {
        if ($value === '' || in_array($value, $allowed, true)) return $value;
        foreach ($allowed as $option) {
            if (self::text($option) === $value) return $option;
        }
        return $value;
    }

    /**
     * 输出缓冲回调：整页 HTML 或前台 JSON 响应转换入口。
     *
     * @psalm-suppress PossiblyUnusedMethod init.php 以 ob_start(['S2T', 'convertOutput']) 挂载
     */
    public static function convertOutput(string $html): string
    {
        if ($html === '') return $html;
        foreach (headers_list() as $header) {
            if (stripos($header, 'X-S2T:') === 0 && stripos($header, 'skip') !== false) {
                return $html;
            }
        }
        $head = ltrim($html);
        if ($head !== '' && ($head[0] === '{' || $head[0] === '[')) {
            return self::convertScript($html);   // JSON：汉字换汉字，语法不变
        }
        return self::convert($html);
    }
}
