<?php
/** 语言包 zip 写入（build-lang-packs.php 与 sign-lang-packs.php 共用）。 */

declare(strict_types=1);

/** 确定性 zip：条目顺序固定、时间戳固定，同一份文件两次构建字节相同。 */
function langPackZip(string $path, string $code, string $php, array $meta): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('无法创建 ' . $path);
    }
    $entries = [
        'lang/' . $code . '.php' => $php,
        'pack.json' => json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
    ];
    foreach ($entries as $name => $data) {
        $zip->addFromString($name, $data);
        $zip->setMtimeName($name, 946684800);   // 2000-01-01，与构建时间无关
    }
    $zip->close();
}
