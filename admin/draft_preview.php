<?php
/** Authenticated, read-only rendering through the actual frontend templates. */
declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';
checkLogin();
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

$previewType = (string) get('type', '');
$previewModels = [
    'contents' => contentModel(), 'products' => productModel(),
    'channels' => channelModel(), 'product_categories' => productCategoryModel(),
    'jobs' => jobModel(), 'downloads' => downloadModel(),
];
$previewRow = isset($previewModels[$previewType]) ? $previewModels[$previewType]->find(getInt('id')) : null;
if (!$previewRow || !empty($previewRow['deleted_at']) || !isset(availableLanguages()[(string) $previewRow['lang']])) {
    http_response_code(404);
    exit(e(__('error_content_not_found')));
}
if ($previewType === 'channels' && ($previewRow['type'] ?? '') === 'page') requirePermission('edit_page');
else requireTranslationPermission($previewType, $previewRow);
define('SITE_LANG', (string) $previewRow['lang']);
$previewContext = ['table' => $previewType, 'row' => $previewRow];
$GLOBALS['_LANG_DATA'] = null;
$_GET = array_merge(array_intersect_key($_GET, array_flip(['sort', 'keyword', 'page', 'brand', 'tag', 'pmin', 'pmax'])), ['id' => (int) $previewRow['id'], 'preview' => 'admin']);

$previewEntry = match ($previewType) {
    'products' => 'product.php', 'jobs' => 'job_detail.php',
    'contents' => ($previewRow['type'] ?? '') === 'article' ? 'article.php' : 'detail.php',
    'channels' => ($previewRow['type'] ?? '') === 'page' ? 'page.php' : 'list.php',
    default => 'list.php',
};
// Categories and downloads are rendered in their real list layout, without exposing attachments.
if (in_array($previewType, ['product_categories', 'downloads'], true)) {
    $layoutType = $previewType === 'downloads' ? 'download' : 'product';
    $previewChannel = channelModel()->findWhere(['type' => $layoutType, 'lang' => SITE_LANG, 'parent_id' => 0])
        ?: channelModel()->findWhere(['type' => $layoutType, 'lang' => config('site_lang', 'zh-CN'), 'status' => 1, 'parent_id' => 0]);
    if (!$previewChannel) {
        http_response_code(404);
        exit(e(__('error_channel_not_found')));
    }
    $_GET['id'] = (int) $previewChannel['id'];
    $previewContext['channel'] = $previewChannel;
}
define('YK_ADMIN_DRAFT_PREVIEW', $previewContext);
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: same-origin');
ob_start(static function (string $html): string {
    $banner = '<div role="status" data-testid="admin-draft-preview" class="border-b bg-gray-50 px-4 py-3 text-sm text-gray-700">'
        . e(__('admin_draft_preview_private')) . '</div>';
    return preg_replace('/(<body\b[^>]*>)/i', '$1' . $banner, $html, 1) ?? $html;
});
require ROOT_PATH . '/' . $previewEntry;
