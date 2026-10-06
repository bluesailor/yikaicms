<?php
/**
 * Yikai CMS - 相册独立页（/albums/{slug}/ 等登记网址进入，按相册 id 渲染）
 *
 * 相册类栏目仍走 page.php；两处共用 includes/partials/album-photos.php 与 album-lightbox.php。
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

HtmlCache::start(600);

$albumId = getInt('id');
$albumData = $albumId > 0 ? albumModel()->findWhere(['id' => $albumId, 'status' => 1]) : null;
if (!$albumData || !empty($albumData['deleted_at'])) {
    header('HTTP/1.1 404 Not Found');
    render404(__('error_page_not_found'));
}
redirectToRegisteredUrl('album', (int) $albumData['id']);   // /album.php?id= 直接访问：301 到登记网址
// 2.1：hreflang / 语言切换按相册翻译组；请求的语言没有这本相册时 302 到它自己的语言版本
LocalizedUrl::enter('album', $albumData, isset($_GET['preview']));
$albumPhotos = albumPhotoModel()->where(['album_id' => (int) $albumData['id'], 'status' => 1]);

if (!isCleanFrontendPreview() && !empty($_SESSION['admin_id'])) {
    $GLOBALS['ik_edit_url'] = '/admin/album_photos.php?id=' . (int) $albumData['id'];
    $GLOBALS['ik_edit_label'] = 'ab_edit_album';
}

$pageTitle = (string) $albumData['name'];
$pageKeywords = configJsonLang('site_keywords');
$pageDescription = trim((string) ($albumData['description'] ?? '')) ?: configJsonLang('site_description');
if (!empty($albumData['cover'])) {
    $ogImage = $albumData['cover'];
}
$navChannels = getNavChannels();

require_once theme_path('layouts/header.php');

// page-hero 读 $channel：用相册的名称、简介、封面拼一个只读的展示栏目
$channel = ['id' => 0, 'parent_id' => 0, 'type' => 'album', 'name' => $albumData['name'],
    'description' => (string) ($albumData['description'] ?? ''), 'image' => (string) ($albumData['cover'] ?? '')];
$breadcrumbItems = [['name' => $albumData['name'], 'url' => '']];
require theme_path('partials/page-hero.php');
?>

<section class="py-12">
    <div class="container mx-auto px-4">
        <?php if (!empty($albumData['description'])): ?>
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <p class="text-gray-600"><?php echo e($albumData['description']); ?></p>
        </div>
        <?php endif; ?>

        <?php require ROOT_PATH . '/includes/partials/album-photos.php'; ?>
    </div>
</section>

<?php require ROOT_PATH . '/includes/partials/album-lightbox.php'; ?>

<?php require_once theme_path('layouts/footer.php'); ?>
