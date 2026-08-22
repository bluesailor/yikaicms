<?php
/**
 * 首页 Blox 动态区块的数据契约。
 *
 * 当前只开放固定首页来源与栏目列表的安全查询子集。所有值在进入主题模板前
 * 都会归一化，避免把编辑器数据直接转换成 SQL 或任意模板路径。
 */

declare(strict_types=1);

require_once __DIR__ . '/HomeBlox/HomeBloxSchemaControlsTrait.php';
require_once __DIR__ . '/HomeBlox/HomeBloxCustomOverridesTrait.php';
require_once __DIR__ . '/HomeBlox/HomeBloxNormalizerTrait.php';
require_once __DIR__ . '/HomeBlox/HomeBloxRuntimeTrait.php';

final class HomeBloxBlockSchema
{
    use HomeBloxSchemaControlsTrait;
    use HomeBloxCustomOverridesTrait;
    use HomeBloxNormalizerTrait;
    use HomeBloxRuntimeTrait;

    public const MAX_ITEMS = 24;
    public const MAX_COLUMNS = 8;
}
