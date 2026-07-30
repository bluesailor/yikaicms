<?php
/**
 * 老站结构补齐：install SQL 有、但从无配套迁移的"孤儿列"。
 *
 * 安装 SQL 多次演进时漏配迁移，老站（≤1.9 时代装机）升级后缺列：
 * 构建器保存、产品保存（material/scene）、案例四字段、排序均会报
 * Unknown column（cile.cn 1.9.2→1.13.2 升级首次暴露，共 9 列）。
 * 逐列判存在、缺哪列补哪列，幂等可重跑。
 */

declare(strict_types=1);

$__cols = [
    'contents' => [
        'content_type'  => "varchar(10) NOT NULL DEFAULT 'html' COMMENT '内容类型：html/blocks'",
        'blocks_data'   => "longtext COMMENT '排版模式JSON数据'",
        'sort_order'    => "int(11) NOT NULL DEFAULT 0 COMMENT '排序'",
        'client_name'   => "varchar(100) NOT NULL DEFAULT '' COMMENT '客户名称（案例）'",
        'industry'      => "varchar(50) NOT NULL DEFAULT '' COMMENT '所属行业（案例）'",
        'duration'      => "varchar(50) NOT NULL DEFAULT '' COMMENT '项目周期（案例）'",
        'result_metric' => "varchar(100) NOT NULL DEFAULT '' COMMENT '成果指标（案例）'",
    ],
    'products' => [
        'material' => "varchar(100) NOT NULL DEFAULT '' COMMENT '材质'",
        'scene'    => "varchar(100) NOT NULL DEFAULT '' COMMENT '适用场景'",
    ],
];

return [
    'id'    => '20260729_builder_columns',
    'title' => '老站结构补齐：contents/products 共 9 个缺失列',
    'desc'  => '补齐 install SQL 有、但从无配套迁移的列：contents 的 content_type/blocks_data（构建器）、sort_order、案例四字段；products 的 material/scene。全新安装已含，仅老站升级需要；逐列判断，幂等。',
    'check' => function () use ($__cols): bool {
        foreach ($__cols as $t => $defs) {
            foreach (array_keys($defs) as $c) {
                if (!_columnExists($t, $c)) return false;
            }
        }
        return true;
    },
    'php' => function () use ($__cols): string {
        $added = [];
        foreach ($__cols as $t => $defs) {
            foreach ($defs as $c => $def) {
                // 走 _addColumn 而非直接拼 SQL：定义里的 COMMENT 在 SQLite 上是语法错
                if (_addColumn($t, $c, $def)) {
                    $added[] = "$t.$c";
                }
            }
        }
        return $added ? ('补列：' . implode(', ', $added)) : '无缺列';
    },
];
