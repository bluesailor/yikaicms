<?php
/** Read-only native article layout, never an anonymous override or cache variant. */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
requireBloxTemplateTypePermission('article-detail');
if (!bloxPageEditorEnabled() || !bloxAdvancedFeaturesEnabled()) error(__('blox_feature_disabled'));

// 只读预览：与前台同一渲染路径，但显式禁用模板套用与整页缓存，避免"预览成功其实走了模板"
$previewArticle = contentModel()->getPublished(getInt('id'));
if (!$previewArticle
    || (string) ($previewArticle['type'] ?? '') !== 'article'
    || !isset(availableLanguages()[(string) ($previewArticle['lang'] ?? '')])) {
    http_response_code(404);
    exit(e(__('error_article_not_found')));
}
define('SITE_LANG', (string) $previewArticle['lang']);
define('YK_ARTICLE_NATIVE_PREVIEW', true);
$GLOBALS['_LANG_DATA'] = null;
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
require ROOT_PATH . '/article.php';
