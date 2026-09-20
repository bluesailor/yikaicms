<?php
/**
 * YikaiCMS 页面构建器引导：加载基类 + 元素 + 注册表 + 渲染器。
 * 从 includes/init.php require。设计见 yikaicms-docs/design-page-builder.md 与 plan-page-builder-p1.md。
 */

declare(strict_types=1);

require_once __DIR__ . '/../TagEngine.php';
require_once __DIR__ . '/../UrlPolicy.php';   // AbstractElement::safeHref/cssImageUrl 的权威实现
require_once __DIR__ . '/../HtmlPolicy.php';
require_once __DIR__ . '/../PageHeroStyleResolver.php';
require_once __DIR__ . '/../PageHeroDesignDraft.php';
require_once __DIR__ . '/BloxResponsiveValue.php';
require_once __DIR__ . '/BloxCssCompiler.php';
require_once __DIR__ . '/AbstractElement.php';
require_once __DIR__ . '/BloxHeaderStates.php';
require_once __DIR__ . '/BloxIcon.php';
require_once __DIR__ . '/BloxImageFraming.php';
require_once __DIR__ . '/../BloxNavIconMatcher.php';   // 语义词典在 includes/ 顶层（非 Blox UI 文案）
require_once __DIR__ . '/BloxPluginRegistry.php';
require_once __DIR__ . '/BloxAssetCollector.php';
require_once __DIR__ . '/DynamicListItemSchema.php';
require_once __DIR__ . '/DynamicSiteData.php';
require_once __DIR__ . '/BloxDynamicTags.php';   // 动态标签 {{provider.field}}（v1.24）
require_once __DIR__ . '/SiteCopyrightSettings.php';
require_once __DIR__ . '/ProductTemplateDocument.php';
require_once __DIR__ . '/DetailTemplateResolver.php';   // 详情模板条件判定（v2 纯判定层，自包含无依赖）
require_once __DIR__ . '/DetailTemplateProvider.php';   // 候选模板 + 内容上下文准备（唯一取数处）
require_once __DIR__ . '/DetailScopeSummary.php';       // 作用域只读摘要（后台展示用，不参与判定）
require_once __DIR__ . '/ArticleTemplateDocument.php';  // 文章详情模板渲染/起始布局
require_once __DIR__ . '/BloxQueryLoopPolicy.php';
require_once __DIR__ . '/BloxLoopQuery.php';   // 容器 Loop 查询（v1.25）：_query 归一/取数复用/分页参数
require_once __DIR__ . '/BloxElementPolicy.php';
require_once __DIR__ . '/BloxDisplayConditions.php';
require_once __DIR__ . '/BloxDesignSystem.php';
require_once __DIR__ . '/BloxGlobalClasses.php';   // 全局样式类（v1.23）：目录/归一/CSS 输出/用量索引
require_once __DIR__ . '/BloxDesignTheme.php';
require_once __DIR__ . '/BloxDesignDependencies.php';
require_once __DIR__ . '/HomeBloxBlockSchema.php';
require_once __DIR__ . '/ProductCatalogLayout.php';   // 产品目录排版设置解析（product-catalog 元素与渲染 partial 共用）

foreach (glob(__DIR__ . '/elements/*.php') ?: [] as $__elFile) {
    require_once $__elFile;
}

require_once __DIR__ . '/BuilderRegistry.php';
require_once __DIR__ . '/BloxDocumentValidator.php';
require_once __DIR__ . '/BloxValueSanitizer.php';
require_once __DIR__ . '/BloxUnknownKeys.php';
require_once __DIR__ . '/BloxDocumentPipeline.php';
require_once __DIR__ . '/BloxAreaDocument.php';
require_once __DIR__ . '/BloxThemeHeaderDocument.php';
require_once __DIR__ . '/BloxPopupDocument.php';
require_once __DIR__ . '/BloxTemplateImporter.php';
require_once __DIR__ . '/BloxTemplateEditPolicy.php';
require_once __DIR__ . '/BloxAreaTemplatePresets.php';
require_once __DIR__ . '/BloxSectionMetadata.php';
require_once __DIR__ . '/BloxBuiltinTemplateProvider.php';
require_once __DIR__ . '/BloxEditorContentDefaults.php';
require_once __DIR__ . '/BloxRemoteTemplateProvider.php';
require_once __DIR__ . '/BloxImportReview.php';
require_once __DIR__ . '/BloxRemoteTemplateInstaller.php';
require_once __DIR__ . '/BloxTemplateCatalog.php';
require_once __DIR__ . '/BloxAreaResolver.php';
require_once __DIR__ . '/BloxAreaAssignmentManager.php';
require_once __DIR__ . '/BloxAreaAssignmentMatrix.php';
require_once __DIR__ . '/BloxAreaLanguageManager.php';
require_once __DIR__ . '/BloxAreaEditorLanguageLinks.php';
require_once __DIR__ . '/BloxAreaEditorTarget.php';
require_once __DIR__ . '/BloxAreaConditions.php';
require_once __DIR__ . '/DynamicLoopTemplateRenderer.php';
require_once __DIR__ . '/BlocksLibrary.php';
require_once __DIR__ . '/BloxDocumentWriteLock.php';
require_once __DIR__ . '/HomeBloxDocument.php';
require_once __DIR__ . '/PageBloxDocument.php';
require_once __DIR__ . '/BloxDotNav.php';
require_once __DIR__ . '/ChannelBloxDocument.php';
require_once __DIR__ . '/BloxPublicationStatus.php';
require_once __DIR__ . '/HomeAboutContent.php';
require_once __DIR__ . '/HomeAboutLocalization.php';
require_once __DIR__ . '/HomeFaqContent.php';
require_once __DIR__ . '/HomeItemListLocalization.php';
require_once __DIR__ . '/HomeTestimonialsContent.php';
require_once __DIR__ . '/HomeLayoutDocument.php';
require_once __DIR__ . '/HomeBloxRenderContext.php';
require_once __DIR__ . '/BloxFrontendEditTarget.php';
require_once __DIR__ . '/BlockRenderer.php';
require_once __DIR__ . '/HomeBloxRenderer.php';
require_once __DIR__ . '/BloxPopupRuntime.php';

BloxAssetCollector::bootstrap();
BloxDesignSystem::bootstrap();
BloxGlobalClasses::bootstrap();
BloxPopupRuntime::bootstrap();
