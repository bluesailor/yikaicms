<?php

/**
 * 发布渠道核对的唯一配置来源（R1）。
 *
 * 为什么要显式配置而不是从源码目录推导：源码里有 4 套市场主题，线上只批准上架 2 套；
 * 从目录推导会把已下架的 Aurora/Trade 当成「应上架但缺失」而误报，或反过来把下架
 * 当成同步失败。批准清单是产品决定，只能由人写在这里。
 *
 * 目录一律允许用环境变量覆盖；**找不到就报错，不允许跳过**——静默跳过正是
 * 「发布记为完成、渠道其实没同步」的成因。
 *
 * @return array<string,mixed>
 */

declare(strict_types=1);

return [
    'schema' => 1,

    // 官网。six pages：三语首页 + 三语更新日志，缺一不可。
    'website' => [
        'base_url' => 'https://www.yikaicms.com',
        // 相对 workspace（仓库父目录）；YK_WEBSITE_DIR 可覆盖为绝对路径。
        'dir_env' => 'YK_WEBSITE_DIR',
        'dir_default' => 'yikaicms.com.yikai',
        'home_pages' => [
            'zh-CN' => 'index.php',
            'en' => 'en/index.php',
            'ja' => 'ja/index.php',
        ],
        'changelog_pages' => [
            'zh-CN' => 'changelog.php',
            'en' => 'en/changelog.php',
            'ja' => 'ja/changelog.php',
        ],
        // 首页下载入口必须指向本版正式完整包（官网下载走 down.yikai.cn 的 OSS 直链，与升级服务器的包同一哈希）。
        'download_url' => 'https://down.yikai.cn/soft/yikaicms/yikaicms-v{version}.zip',
    ],

    // 本地归档。正式完整包、校验文件、构建证据、以及实际发布过的增量包。
    'archive' => [
        'dir_env' => 'YK_RELEASE_DIR',
        'dir_default' => 'yikaicms.yikai/releases',
        'package' => 'yikaicms-v{version}.zip',
        'checksum' => 'yikaicms-v{version}.sha256',
        'evidence' => 'yikaicms-v{version}.evidence.json',
        // 增量包清单：以 deltas-v{version}.json 为准；没有该文件时视为本版不发增量。
        'delta_manifest' => 'deltas-v{version}.json',
    ],

    // 升级服务器。复用既有 ReleaseUploadGuard，不另造一套判定。
    'update_server' => [
        'package_url' => 'https://update.yikaicms.com/packages/{package}',
        // 数据目录禁止公开访问。只允许运维配置专用只读元数据地址；未配置不能证明线上目录已同步。
        'catalog_url' => getenv('YK_RELEASE_CATALOG_URL') ?: '',
        'dir_env' => 'YK_UPDATE_ROOT',
        'dir_default' => 'update.yikaicms',
        'catalog' => 'data/releases.json',
        'registry' => 'data/release-registry.json',
    ],

    // 模板市场。approved 是「当前批准上架清单」，不是「源码里有哪些主题」。
    // default 随核心分发，不在 approved 里；但自 2026-09-10 起以市场包发布新版
    // （Default 1.0.6+，只对带 protocol_version 的新客户端列出，旧版列表不出现），记在 capable_only。
    'market' => [
        'registry_url' => 'https://update.yikaicms.com/api/themes/list.php',
        'registry' => 'data/themes.json',
        'approved' => ['business', 'minimal'],
        // 注册表里有、但只对新协议客户端列出的主题：不算计划外上架，也不要求出现在旧版线上列表。
        'capable_only' => ['default'],
        // 明确记录已下架的 slug：它们**必须不在**注册表里，出现即失败。
        'delisted' => ['aurora', 'trade'],
        'package_url' => 'https://update.yikaicms.com/packages/themes/{package}',
    ],

    // 演示站 demo.yikaicms.com。
    //
    // 本地副本自 2026-07-30 起不再是部署源（线上走在线更新），所以**本地目录只作为
    // 上下文记录，不作为判据**；真正的判据是线上站点自己报出来的版本。
    //
    // 版本探针：前台资源查询串就是 CMS_VERSION（includes/functions.php:2878
    // 的 code-copy.js?v=<CMS_VERSION>）。它是公开可读且直连版本号的信号，
    // 比翻页面文案可靠——演示内容里本来就有一堆无关数字。
    'demo' => [
        'dir_env' => 'YK_DEMO_DIR',
        'dir_default' => 'demo.yikaicms.yikai',
        // 根目录现在是模板总览页（不是 CMS 站），探针打一个子站；各子站同批升级。
        'url' => 'https://demo.yikaicms.com/yikai-business/',
        'asset_version_pattern' => '/\/assets\/[^"\'>\s]+\?v=(\d+\.\d+\.\d+(?:\.\d+)?)/',
    ],

    // GitHub Release。发布后核对用。
    'github' => [
        'repo' => 'bluesailor/yikaicms',
        'release_url' => 'https://github.com/bluesailor/yikaicms/releases/tag/v{version}',
    ],

    // 候选阶段允许尚未同步的渠道。post-release 阶段这些一律必须「已验证」。
    'candidate_optional' => ['website', 'update_server', 'market', 'github', 'demo'],
];
