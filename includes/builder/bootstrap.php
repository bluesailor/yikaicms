<?php
/**
 * YikaiCMS 页面构建器引导：加载基类 + 元素 + 注册表 + 渲染器。
 * 从 includes/init.php require。设计见 yikaicms-docs/design-page-builder.md 与 plan-page-builder-p1.md。
 */

declare(strict_types=1);

require_once __DIR__ . '/../TagEngine.php';
require_once __DIR__ . '/../UrlPolicy.php';   // AbstractElement::safeHref/cssImageUrl 的权威实现
require_once __DIR__ . '/BloxResponsiveValue.php';
require_once __DIR__ . '/AbstractElement.php';
require_once __DIR__ . '/BloxHeaderStates.php';
require_once __DIR__ . '/BloxIcon.php';
require_once __DIR__ . '/../BloxNavIconMatcher.php';   // 语义词典在 includes/ 顶层（非 Blox UI 文案）
require_once __DIR__ . '/BloxPluginRegistry.php';
require_once __DIR__ . '/BloxAssetCollector.php';
require_once __DIR__ . '/DynamicListItemSchema.php';
require_once __DIR__ . '/DynamicSiteData.php';
require_once __DIR__ . '/BloxQueryLoopPolicy.php';
require_once __DIR__ . '/BloxElementPolicy.php';
require_once __DIR__ . '/BloxDisplayConditions.php';
require_once __DIR__ . '/BloxDesignSystem.php';
require_once __DIR__ . '/BloxDesignDependencies.php';
require_once __DIR__ . '/HomeBloxBlockSchema.php';

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
require_once __DIR__ . '/BloxAreaTemplatePresets.php';
require_once __DIR__ . '/BloxBuiltinTemplateProvider.php';
require_once __DIR__ . '/BloxRemoteTemplateProvider.php';
require_once __DIR__ . '/BloxTemplateCatalog.php';
require_once __DIR__ . '/BloxAreaResolver.php';
require_once __DIR__ . '/BloxAreaEditorTarget.php';
require_once __DIR__ . '/BloxAreaConditions.php';
require_once __DIR__ . '/DynamicLoopTemplateRenderer.php';
require_once __DIR__ . '/BlocksLibrary.php';
require_once __DIR__ . '/HomeBloxDocument.php';
require_once __DIR__ . '/PageBloxDocument.php';
require_once __DIR__ . '/ChannelBloxDocument.php';
require_once __DIR__ . '/HomeLayoutDocument.php';
require_once __DIR__ . '/HomeBloxRenderContext.php';
require_once __DIR__ . '/BloxFrontendEditTarget.php';
require_once __DIR__ . '/BlockRenderer.php';
require_once __DIR__ . '/HomeBloxRenderer.php';
require_once __DIR__ . '/BloxPopupRuntime.php';

BloxAssetCollector::bootstrap();
BloxDesignSystem::bootstrap();
BloxPopupRuntime::bootstrap();
