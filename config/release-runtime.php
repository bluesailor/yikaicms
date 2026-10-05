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
        'form_submit.php',
        'form_nonce.php',
        'admin/index.php',
        'admin/form_file.php',
        'config/config.sample.php',
        'config/database.php',
        'config/product.php',
        'config/release-runtime.php',
        'config/version.php',
        'includes/init.php',
        'includes/php_guard.php',           // index.php / install/index.php / init.php 最先 require：PHP 版本底线
        'includes/BasePath.php',            // index.php / init.php / functions.php 无条件 require：子目录部署的挂载点
        'includes/SessionStorage.php',      // functions.php 无条件 require：默认会话目录不可写时的兜底
        'includes/RewriteProbe.php',
        'includes/CompatibleLinks.php',
        'includes/Dispatcher.php',
        'includes/i18n/LanguageRegistry.php',   // Dispatcher / functions.php / lang_url.php 无条件 require：语言注册表
        'includes/i18n/LanguageDomains.php',    // functions.php 无条件 require：语言域名模式
        'includes/i18n/LanguageRouting.php',    // functions.php 无条件 require：语言前缀探针与 .htaccess 一键更新
        'includes/i18n/TextDirection.php',      // functions.php 与 Blox 渲染无条件 require：左右按起始/结束输出
        'includes/HtmlTagRewriter.php',
        'assets/js/rewrite-probe.js',
        'includes/functions.php',
        'includes/HomeSettingsLanguageDefaults.php',
        'includes/http_response.php',
        'includes/language_request.php',
        'includes/lang_url.php',
        'includes/product_routes.php',
        'includes/Redirects.php',
        'includes/LegacyUrls.php',
        'includes/ExtFields.php',
        'includes/media/MediaAlt.php',
        'includes/media/ImageEditPlan.php',
        'includes/media/ImageEditor.php',
        'includes/ProductCatalogRequest.php',
        'includes/FooterNavigation.php',
        'includes/ProductIdentity.php',
        'includes/frontend_preview.php',
        'includes/ThemeRuntime.php',
        'includes/ThemeSettings.php',
        'includes/builder/BloxPageLayout.php',
        'includes/builder/BloxMotion.php',
        'includes/ThemeContent.php',
        'includes/SiteSetup.php',
        'includes/SiteTemplateData.php',
        'config/site-template-schema.php',
        'includes/SiteTemplatePluginData.php',
        'includes/SiteTemplateArchive.php',
        'includes/SiteTemplateService.php',
        'includes/DefaultLangShadow.php',  // SiteTemplateService 与迁移 20260810 共用的默认语言归位规则
        'includes/UploadReferences.php',
        'includes/SiteTemplateMarket.php',
        'includes/TemplateCategories.php',      // ThemeValidator / SiteTemplateMarket 无条件 require：行业分类
        'config/template-categories.php',       // 行业分类数据（主题、整站模板市场、官网共用）
        'includes/SiteTemplateLanguages.php',
        'includes/SensitiveSettings.php',
        'includes/SiteContentChecks.php',
        'includes/SiteImportReport.php',
        'admin/site_setup.php',
        'admin/site_templates.php',
        'admin/site_template_market.php',
        'admin/theme_content.php',
        'admin/site_content_check.php',
        'includes/ThemePalette.php',
        'includes/ThemeMarket.php',
        'includes/SupportAccess.php',           // admin/includes/auth.php 无条件 require：技术支持临时访问
        'includes/RemoteRepair.php',            // admin/upgrade.php 无条件 require：远程修复记录
        'includes/PluginIcons.php',             // admin/plugin.php 无条件 require：插件卡片图标
        'includes/ScheduledPublish.php',        // includes/init.php 每次访问限流调用：定时发布 / 定时上架
        'admin/support_access.php',
        'admin/support_login.php',
        'includes/ThemeValidator.php',
        'includes/ThemeInstaller.php',
        'includes/security.php',
        'includes/AdminLogSanitizer.php',
        'includes/FormSubmissionToken.php',
        'includes/FormSubmissionNonce.php',
        'includes/FormSubmissionLifecycle.php',
        'includes/FormFieldContract.php',
        'includes/FormDecimal.php',
        'includes/FormSpamGuard.php',
        'includes/FormUploadService.php',
        'includes/LegacyInstallCleanup.php',
        'includes/SiteHealth.php',
        'includes/SiteAddress.php',         // SiteHealth 顶部无条件 require；站点URL 与实际访问地址的比对
        'admin/includes/site_url_hint.php', // setting.php 渲染「站点URL」时 require
        'includes/AccessibilityAudit.php',
        'includes/RuntimeRequirements.php',   // SiteHealth 顶部 require：环境要求的唯一来源
        'includes/SiteAsset.php',
        'includes/ErrorHandler.php',
        'includes/Slug.php',
        'includes/ColorContrast.php',
        'includes/i18n/LocalizedUrl.php',   // functions.php 顶部 require：条目各语言网址（2.0.5）
        'includes/admin_article_categories.php',   // functions.php 顶部 require：文章分类可由插件接管
        'includes/Pinyin.php',
        'includes/pinyin/chars.php',
        'includes/pinyin/phrases.php',
        'includes/pinyin/overrides.php',
        'includes/pinyin/LICENSE.txt',
        'includes/pinyin/AUTHORS.txt',
        'includes/image.php',
        'includes/permissions.php',
        'includes/catalog_pagination.php',
        'includes/UrlPolicy.php',
        'includes/HtmlPolicy.php',
        'includes/builder/bootstrap.php',
        'includes/builder/BuilderRegistry.php',
        'includes/builder/BloxDocumentPipeline.php',
        'includes/builder/BloxValueSanitizer.php',
        'includes/builder/BloxMaintenanceMode.php',
        'includes/builder/BloxEmptyBinding.php',
        'includes/builder/BloxEdgeSamples.php',
        'includes/builder/BloxLinkCatalog.php',   // 编辑器「选择页面」链接候选（link-picker-methods.php 无条件 require）
        'install/index.php',
        'install/validation.php',
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
        'assets/js/product-catalog-filter.js',
        'assets/icons/blox-icon-catalog.json',
        'assets/icons/site-icons.min.css',
        'assets/icons/site-icon-audit.json',
        'assets/icons/fonts/tabler-icons-site.woff2',
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
        // 内部文档与验证记录：含商业策略、开发机路径，任何时候都不能进发行包
        'docs',
        'AGENTS.md',
        'CLAUDE.md',
        'plugins/dologin/VERIFICATION.md',
        // 易登录插件不随核心预装（2026-09-17 产品决定），经插件市场安装
        'plugins/dologin',
    ],
];
