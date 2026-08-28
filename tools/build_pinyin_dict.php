<?php
/**
 * 拼音词库生成器（一次性/可重跑）。
 *
 * 数据源：rime/rime-pinyin-simp 的 pinyin_simp.dict.yaml（Apache-2.0，源自 AOSP PinyinIME）。
 * 产物：includes/pinyin/chars.php（音节→汉字反向索引）
 *       includes/pinyin/phrases.php（多音字词组表，仅保留与逐字默认读音不同的条目）
 *
 * 用法：php tools/build_pinyin_dict.php <pinyin_simp.dict.yaml> [max_phrase_len]
 */
declare(strict_types=1);

const PINYIN_SOURCE_SHA256 = 'E341598343A0F0F2035BB1AAFC34A7F3BB7887DEEECB3F60796262AAA2983E6B';

$src = $argv[1] ?? '';
$maxLen = (int) ($argv[2] ?? 4);
if ($src === '' || !is_file($src)) {
    fwrite(STDERR, "用法: php tools/build_pinyin_dict.php <pinyin_simp.dict.yaml> [max_phrase_len]\n");
    exit(1);
}
$sourceHash = strtoupper((string) hash_file('sha256', $src));
if (!hash_equals(PINYIN_SOURCE_SHA256, $sourceHash)) {
    fwrite(STDERR, "词库 SHA256 不匹配，拒绝生成。\n期望: " . PINYIN_SOURCE_SHA256 . "\n实际: {$sourceHash}\n");
    exit(1);
}

$charBest = [];   // 汉字 => [音节, 权重]
$phraseBest = []; // 词 => [音节数组, 权重]

$fh = fopen($src, 'rb');
if ($fh === false) {
    fwrite(STDERR, "无法读取: {$src}\n");
    exit(1);
}
while (($line = fgets($fh)) !== false) {
    $line = rtrim($line, "\r\n");
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $cols = explode("\t", $line);
    if (count($cols) < 2) {
        continue;
    }
    $text = $cols[0];
    $code = trim($cols[1]);
    $weight = isset($cols[2]) ? (int) $cols[2] : 0;
    if ($code === '' || preg_match('/^[a-z ]+$/', $code) !== 1) {
        continue;
    }
    $len = mb_strlen($text);
    if ($len === 1) {
        if (!isset($charBest[$text]) || $weight > $charBest[$text][1]) {
            $charBest[$text] = [$code, $weight];
        }
        continue;
    }
    if ($len < 2 || $len > $maxLen) {
        continue;
    }
    $syllables = explode(' ', $code);
    if (count($syllables) !== $len) {
        continue; // 音节数与字数不符（儿化/异体），跳过
    }
    if (!isset($phraseBest[$text]) || $weight > $phraseBest[$text][1]) {
        $phraseBest[$text] = [$syllables, $weight];
    }
}
fclose($fh);

$chars = [];
foreach ($charBest as $ch => $pair) {
    $chars[$ch] = $pair[0];
}
ksort($chars);

// 只保留「与逐字默认读音不同」的词组——其余是冗余的
$phrases = [];
foreach ($phraseBest as $word => $pair) {
    $syllables = $pair[0];
    $cs = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $same = true;
    foreach ($cs as $i => $c) {
        if (($chars[$c] ?? null) !== $syllables[$i]) {
            $same = false;
            break;
        }
    }
    if (!$same) {
        $phrases[$word] = implode(' ', $syllables);
    }
}
ksort($phrases);

// 单字表压成「音节 => 汉字串」反向索引：411 个音节远少于 1.6 万汉字
$index = [];
foreach ($chars as $ch => $syllable) {
    $index[$syllable] = ($index[$syllable] ?? '') . $ch;
}
ksort($index);

$header = <<<'PHPDOC'
<?php
/**
 * 自动生成，请勿手改。重新生成： php tools/build_pinyin_dict.php <pinyin_simp.dict.yaml>
 *
 * 数据来源：rime/rime-pinyin-simp（Apache-2.0，派生自 AOSP PinyinIME）。
 * 许可与出处登记见 THIRD-PARTY-NOTICES.md。
 */
declare(strict_types=1);

return
PHPDOC;

$export = static function (array $data): string {
    $parts = [];
    foreach ($data as $k => $v) {
        $parts[] = var_export((string) $k, true) . '=>' . var_export((string) $v, true);
    }
    return '[' . implode(',', $parts) . '];' . "\n";
};

file_put_contents(__DIR__ . '/../includes/pinyin/chars.php', $header . $export($index));
file_put_contents(__DIR__ . '/../includes/pinyin/phrases.php', $header . $export($phrases));

printf(
    "单字 %d 字 / %d 音节  →  chars.php %.0f KB\n词组 %d 条（已剔除 %d 条冗余）  →  phrases.php %.0f KB\n",
    count($chars),
    count($index),
    filesize(__DIR__ . '/../includes/pinyin/chars.php') / 1024,
    count($phrases),
    count($phraseBest) - count($phrases),
    filesize(__DIR__ . '/../includes/pinyin/phrases.php') / 1024
);
