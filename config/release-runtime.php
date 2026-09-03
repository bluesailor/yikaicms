<?php

declare(strict_types=1);

/**
 * Production artifact contract. Paths are relative to the extracted package root.
 * Keep runtime dependencies here instead of relying on broad directory-copy rules.
 *
 * @return array{required_files:list<string>,generated_files:list<string>,forbidden_paths:list<string>}
 */
return [
    'required_files' => [
        'index.php',
        'admin/index.php',
        'config/config.sample.php',
        'config/database.php',
        'config/product.php',
        'config/release-runtime.php',
        'config/version.php',
        'includes/init.php',
        'includes/functions.php',
        'includes/FooterNavigation.php',
        'includes/ProductIdentity.php',
        'includes/frontend_preview.php',
        'includes/ThemeRuntime.php',
        'includes/ThemeSettings.php',
        'includes/ThemePalette.php',
        'includes/ThemeMarket.php',
        'includes/ThemeValidator.php',
        'includes/ThemeInstaller.php',
        'includes/security.php',
        'includes/AdminLogSanitizer.php',
        'includes/FormSubmissionToken.php',
        'includes/LegacyInstallCleanup.php',
        'includes/SiteHealth.php',
        'includes/RuntimeRequirements.php',   // SiteHealth 顶部 require：环境要求的唯一来源
        'includes/SiteAsset.php',
        'includes/ErrorHandler.php',
        'includes/Pinyin.php',
        'includes/pinyin/chars.php',
        'includes/pinyin/phrases.php',
        'includes/pinyin/overrides.php',
        'includes/pinyin/LICENSE.txt',
        'includes/pinyin/AUTHORS.txt',
        'includes/image.php',
        'includes/permissions.php',
        'includes/UrlPolicy.php',
        'includes/HtmlPolicy.php',
        'includes/builder/bootstrap.php',
        'includes/builder/BuilderRegistry.php',
        'includes/builder/BloxDocumentPipeline.php',
        'includes/builder/BloxValueSanitizer.php',
        'install/index.php',
        'install/sql/mysql.sql',
        'install/sql/sqlite.sql',
        'themes/default/layouts/header.php',
        'themes/default/layouts/footer.php',
        'plugins/.htaccess',
        'migrations/_inline_upgrades.php',
        // ── 在线升级链路（v1.19.6 补登记）────────────────────────────
        // 这条链路此前只有 _inline_upgrades.php 一项进清单，等于没守。它的后果比前台缺文件重：
        // 前台缺文件是页面报错，升级链路缺文件会让站点卡在「升到一半」——新入口已落盘、
        // 依赖还没到，且第二轮请求再也起不来（v1.19.4 事故形态）。
        // 实际事故：① v1.19.5 的 UpgradeEntryOrder.php 在 PHP 8.0 上 T_ENUM 致命，
        // 清单没守住所以审包与产物冒烟都没报；② 演示站曾实测缺失 StaticHtmlUrlPolicy.php
        // 导致后台致命错误。两者都是「文件清单查不出、装上才知道」的形态（铁律 8 的原意）。
        'admin/upgrade_online.php',
        'includes/UpgradeRunner.php',
        'includes/UpgradeEntryOrder.php',
        'includes/UpgradeDatabaseRollback.php',
        'includes/UpgradeHealth.php',
        'includes/UpdateChannel.php',
        'includes/UpdatePackageSignature.php',
        'includes/Migrator.php',
        'includes/Backup.php',
        'includes/StaticHtmlUrlPolicy.php',
        'deploy/nginx-server.conf',
        'deploy/nginx-baota.conf',
        'deploy/aliyun-nginx-minimal.txt',
        'assets/css/tailwind.css',
    ],
    'generated_files' => [
        'config/build.php',
        'config/provenance.php',
    ],
    'forbidden_paths' => [
        '.git',
        '.github',
        'tests',
        'tools',
        'releases',
        'marketplace',
        'config/config.php',
        'config/installed.lock',
        'installed.lock',
        'install/upgrade.php',
        'install/run_upgrade.php',
        'phpunit.xml',
        'psalm.xml',
        'composer.json',
        'composer.lock',
        'vendor',
    ],
];
