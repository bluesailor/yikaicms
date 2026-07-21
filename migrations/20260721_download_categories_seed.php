<?php
/**
 * 下载分类统一：为空的 download_categories 补 3 个默认分类。
 *
 * 背景：下载记录只有 category_id（指向 download_categories），无 channel_id。
 * 早期种子只造了 type=download 的子栏目（软件/文档/驱动）喂给右侧边栏，
 * 而 download_categories 种子为空 → 后台无分类可选、前端侧边栏(子栏目)与
 * 后台分类不符。现前端统一改用 download_categories，这里给老站补默认分类。
 * 仅当表存在且为空时执行（幂等），不覆盖用户已建分类。
 */

declare(strict_types=1);

return [
    'id'    => '20260721_download_categories_seed',
    'title' => '下载分类：为空的 download_categories 补默认分类',
    'desc'  => '下载分类统一到 download_categories（前端侧边栏/筛选/后台编辑同一套）。为空的下载分类表补入 软件下载/文档资料/驱动程序 三项；已有分类的站点跳过。',
    'check' => function (): bool {
        try {
            if (!db()->tableExists('download_categories')) return true; // 无此表 → 跳过
            $row = db()->fetchOne("SELECT COUNT(*) AS c FROM " . DB_PREFIX . "download_categories");
            return (int) ($row['c'] ?? 0) > 0;                          // 已有分类 → 跳过
        } catch (\Throwable $e) {
            return true;                                                // 出错 → 跳过，避免误插
        }
    },
    'sqls' => [
        "INSERT INTO `" . DB_PREFIX . "download_categories` (`name`, `description`, `sort_order`, `status`, `created_at`) VALUES "
            . "('软件下载','',1,1,1776654080),"
            . "('文档资料','',2,1,1776654080),"
            . "('驱动程序','',3,1,1776654080)",
    ],
];
