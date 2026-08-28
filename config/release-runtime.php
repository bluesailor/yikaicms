<?php

declare(strict_types=1);

/**
 * Production artifact contract. Paths are relative to the extracted package root.
 * Keep runtime dependencies here instead of relying on broad directory-copy rules.
 *
 * @return array{required_files:list<string>,forbidden_paths:list<string>}
 */
return [
    'required_files' => [
        'index.php',
        'admin/index.php',
        'config/config.sample.php',
        'config/database.php',
        'config/release-runtime.php',
        'config/version.php',
        'includes/init.php',
        'includes/functions.php',
        'includes/frontend_preview.php',
        'includes/ThemeRuntime.php',
        'includes/security.php',
        'includes/AdminLogSanitizer.php',
        'includes/FormSubmissionToken.php',
        'includes/LegacyInstallCleanup.php',
        'includes/SiteHealth.php',
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
        'deploy/nginx-server.conf',
        'deploy/nginx-baota.conf',
        'deploy/aliyun-nginx-minimal.txt',
        'assets/css/tailwind.css',
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
