<?php
/**
 * 人工修正表（自建，可手改）——优先级高于自动生成的 phrases.php / chars.php。
 *
 * 用途：自动词库按词频挑默认读音，个别词会挑错；这里逐条纠正。
 * 格式：'词' => '空格分隔的无声调音节'，音节数必须与字数一致。
 * 词长上限同 Pinyin::MAX_PHRASE（4 字）；单字也可写在这里，覆盖单字表。
 *
 * 改完请跑 tests/Unit/PinyinTest.php 确认没写坏。
 */
declare(strict_types=1);

return [
    // 模：mó（模式/模型）与 mú（模板/模具/模样）两读，词库默认取了 mó
    '模板' => 'mu ban',
    '模具' => 'mu ju',
    '模样' => 'mu yang',
    // 常见于企业站、词库默认易错的词
    '重庆' => 'chong qing',
    '重置' => 'chong zhi',
    '解数' => 'xie shu',
    '会计' => 'kuai ji',
    '巷道' => 'hang dao',
];
