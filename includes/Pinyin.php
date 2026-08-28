<?php
/**
 * Yikai CMS - 汉字转拼音
 *
 * 只服务一个场景：把标题转成 URL slug。因此输出一律是无声调小写拼音，
 * 不提供声调、首字母缩写等用不到的能力。
 *
 * 词库（includes/pinyin/）派生自 rime/rime-pinyin-simp（Apache-2.0，源自 AOSP
 * PinyinIME），由 tools/build_pinyin_dict.php 生成：
 *   chars.php   音节 => 汉字串（反向索引，409 个音节覆盖 16472 字）
 *   phrases.php 词 => 音节串（仅保留与逐字默认读音不同的条目，用于多音字）
 *
 * 多音字靠词组表纠正：「重庆」不会读成 zhong-qing，「银行」不会读成 yin-xing。
 * 词组按最长优先匹配，未命中再退回单字。
 *
 * PHP 8.0+，无外部依赖。
 */
declare(strict_types=1);

final class Pinyin
{
    /** 词组表里最长的词条字数，决定最长匹配的起始窗口 */
    private const MAX_PHRASE = 4;

    /** @var array<string,string>|null 汉字 => 音节 */
    private static ?array $chars = null;

    /** @var array<string,string>|null 词 => 空格分隔音节 */
    private static ?array $phrases = null;

    /**
     * 转成 slug：无声调拼音，用 $delimiter 连接。
     * 无法识别的字符被丢弃；连续的 ASCII 字母数字保留为一个整体（"CNC" => "cnc"）。
     */
    public static function slug(string $text, string $delimiter = '-'): string
    {
        $tokens = self::convert($text);
        if ($tokens === []) {
            return '';
        }
        $slug = implode($delimiter, $tokens);
        if ($delimiter === '') {
            return (string) preg_replace('/[^a-z0-9]/', '', $slug);
        }
        // 分隔符自身若非 [a-z0-9]，统一清掉非法字符后再收尾
        $safe = preg_quote($delimiter, '/');
        $slug = (string) preg_replace('/[^a-z0-9' . $safe . ']/', '', $slug);
        $slug = (string) preg_replace('/(?:' . $safe . '){2,}/', $delimiter, $slug);
        return trim($slug, $delimiter);
    }

    /**
     * 转成 token 数组：每个汉字一个音节，连续 ASCII 字母数字合为一个 token。
     * @return list<string>
     */
    public static function convert(string $text): array
    {
        self::load();
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false || $chars === []) {
            return [];
        }

        $tokens = [];
        $count = count($chars);
        $i = 0;
        while ($i < $count) {
            // 1) 词组最长优先——多音字全靠这一步纠正
            $matched = false;
            $window = min(self::MAX_PHRASE, $count - $i);
            for ($len = $window; $len >= 2; $len--) {
                $word = implode('', array_slice($chars, $i, $len));
                if (isset(self::$phrases[$word])) {
                    foreach (explode(' ', self::$phrases[$word]) as $syllable) {
                        if ($syllable !== '') {
                            $tokens[] = $syllable;
                        }
                    }
                    $i += $len;
                    $matched = true;
                    break;
                }
            }
            if ($matched) {
                continue;
            }

            $char = $chars[$i];

            // 2) 单字表
            if (isset(self::$chars[$char])) {
                $tokens[] = self::$chars[$char];
                $i++;
                continue;
            }

            // 3) 连续 ASCII 字母数字整体保留（型号、缩写、年份）
            if (preg_match('/[A-Za-z0-9]/', $char) === 1) {
                $run = '';
                while ($i < $count && preg_match('/^[A-Za-z0-9]$/', $chars[$i]) === 1) {
                    $run .= $chars[$i];
                    $i++;
                }
                $tokens[] = strtolower($run);
                continue;
            }

            // 4) 其余（标点、空格、未收录字符）丢弃
            $i++;
        }

        return $tokens;
    }

    /**
     * 词库是否可用。缺文件时 slug() 返回空串，由调用方兜底，绝不致命。
     * @psalm-suppress PossiblyUnusedMethod 词库健康自检 API（测试与后台诊断消费）
     */
    public static function available(): bool
    {
        self::load();
        return self::$chars !== [] && self::$chars !== null;
    }

    private static function load(): void
    {
        if (self::$chars !== null) {
            return;
        }
        self::$chars = [];
        self::$phrases = [];

        $dir = (defined('ROOT_PATH') ? (string) ROOT_PATH : dirname(__DIR__)) . '/includes/pinyin';

        $index = @include $dir . '/chars.php';
        if (is_array($index)) {
            // 反向索引展开：音节 => 汉字串  ==>  汉字 => 音节
            foreach ($index as $syllable => $group) {
                $list = preg_split('//u', (string) $group, -1, PREG_SPLIT_NO_EMPTY);
                if ($list === false) {
                    continue;
                }
                foreach ($list as $char) {
                    self::$chars[$char] = (string) $syllable;
                }
            }
        }

        $phrases = @include $dir . '/phrases.php';
        if (is_array($phrases)) {
            self::$phrases = $phrases;
        }

        // 人工修正表最后合入，优先级最高；单字条目直接改写单字表
        $overrides = @include $dir . '/overrides.php';
        if (is_array($overrides)) {
            foreach ($overrides as $word => $reading) {
                $word = (string) $word;
                $reading = trim((string) $reading);
                if ($word === '' || $reading === '') {
                    continue;
                }
                if (mb_strlen($word) === 1) {
                    self::$chars[$word] = $reading;
                } else {
                    self::$phrases[$word] = $reading;
                }
            }
        }
    }

    /**
     * 仅供测试：丢弃已加载的词库
     * @psalm-suppress PossiblyUnusedMethod 测试用，重置静态词库缓存
     */
    public static function flush(): void
    {
        self::$chars = null;
        self::$phrases = null;
    }
}
