<?php
/**
 * YikaiCMS 页面构建器引导：加载基类 + 元素 + 注册表 + 渲染器。
 * 从 includes/init.php require。设计见 yikaicms-docs/design-page-builder.md 与 plan-page-builder-p1.md。
 */

declare(strict_types=1);

require_once __DIR__ . '/../TagEngine.php';
require_once __DIR__ . '/AbstractElement.php';
require_once __DIR__ . '/BloxPluginRegistry.php';
require_once __DIR__ . '/BloxAssetCollector.php';
require_once __DIR__ . '/DynamicListItemSchema.php';
require_once __DIR__ . '/HomeBloxBlockSchema.php';

foreach (glob(__DIR__ . '/elements/*.php') ?: [] as $__elFile) {
    require_once $__elFile;
}

require_once __DIR__ . '/BuilderRegistry.php';
require_once __DIR__ . '/BloxDocumentValidator.php';
require_once __DIR__ . '/BloxDocumentPipeline.php';
require_once __DIR__ . '/BloxTemplateImporter.php';
require_once __DIR__ . '/BloxRemoteTemplateProvider.php';
require_once __DIR__ . '/BloxTemplateCatalog.php';
require_once __DIR__ . '/BloxAreaResolver.php';
require_once __DIR__ . '/DynamicLoopTemplateRenderer.php';
require_once __DIR__ . '/BlocksLibrary.php';
require_once __DIR__ . '/HomeBloxDocument.php';
require_once __DIR__ . '/HomeLayoutDocument.php';
require_once __DIR__ . '/HomeBloxRenderContext.php';
require_once __DIR__ . '/BlockRenderer.php';
require_once __DIR__ . '/HomeBloxRenderer.php';

BloxAssetCollector::bootstrap();
