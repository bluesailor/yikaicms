<?php
/**
 * Yikai CMS - 联系我们
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/contact_parts.php';

// 加载栏目数据
$channel = getChannelBySlug('contact', true);
$currentChannelId = $channel ? (int)$channel['id'] : 0;

// 页面信息（优先使用栏目SEO设置）
$pageTitle = ($channel && $channel['seo_title']) ? $channel['seo_title'] : __('contact_title');
$pageKeywords = ($channel && $channel['seo_keywords']) ? $channel['seo_keywords'] : configJsonLang('site_keywords');
$pageDescription = ($channel && $channel['seo_description']) ? $channel['seo_description'] : configJsonLang('site_description');

// 获取导航
$navChannels = getNavChannels();

// 前台就地编辑：各可编辑区域标记 + 管理浮条「编辑此页」
$__ykEdit = static function (string $url, string $label): string {
    return empty($_SESSION['admin_id']) ? ''
        : ' data-yk-edit="' . e($url) . '" data-yk-edit-label="' . e($label) . '"';
};
if (!empty($_SESSION['admin_id'])) {
    $GLOBALS['ik_edit_url'] = '/admin/setting_contact.php';
}

// 图标SVG路径映射
$iconPaths = contactIconPaths();

// 联系页主体：保存排版后由区块完整接管卡片、表单、地图及其它自定义内容；
// 尚未排版的老站继续走下方固定版式。
$contactBlocksHtml = '';
if ($currentChannelId > 0) {
    $contactDraftPreview = BloxPublicationStatus::pageDraftPreview($currentChannelId);
    $__cRow = $contactDraftPreview !== null
        ? ['content_type' => 'blocks', 'blocks_data' => $contactDraftPreview, 'content' => '']
        : contentModel()->queryOne(
            'SELECT * FROM ' . contentModel()->tableName() . ' WHERE channel_id = ? AND status = 1 AND deleted_at IS NULL ORDER BY is_top DESC, id DESC LIMIT 1',
            [$currentChannelId]
        );
    if ($__cRow && ($__cRow['content_type'] ?? '') === 'blocks' && !empty($__cRow['blocks_data'])) {
        if (!isCleanFrontendPreview() && !empty($_SESSION['admin_id'])) {
            $GLOBALS['ik_edit_url'] = '/admin/blox_editor.php?id=' . $currentChannelId;
        }
        $contactBlocksHtml = renderFrontEditableContentBody($__cRow, $currentChannelId);
    }
}

// 从设置读取联系信息卡片（lang-aware：当前是 EN/JA 时读 contact_cards_<lang>）
$contactCards = json_decode(configJsonLang('contact_cards') ?: '[]', true) ?: [];

// 根据数量决定列数
$gridCols = match (count($contactCards)) {
    1 => 'md:grid-cols-1',
    2 => 'md:grid-cols-2',
    4 => 'md:grid-cols-2 lg:grid-cols-4',
    default => 'md:grid-cols-3',
};

// 引入头部
require_once theme_path('layouts/header.php');
?>

<!-- 页面头部 -->
<?php
$breadcrumbItems = [['name' => __('contact_title'), 'url' => '']];
if (!$channel) {
    $channel = ['name' => __('contact_title'), 'description' => __('contact_subtitle'), 'image' => ''];
}
require theme_path('partials/contact-hero.php');
?>

<?php // 排过版：整页交给区块（联系卡片/表单/地图作为元素自由摆放、可加任意其它内容）；
      // 未排版：沿用下方固定版式，输出与老版本逐字节一致。 ?>
<?php if ($contactBlocksHtml !== ''): ?>
<div class="yk-contact-blocks"<?php echo $__ykEdit(
    '/admin/blox_editor.php?id=' . $currentChannelId,
    '✎ ' . __('fe_edit_layout')
); ?>>
    <?php echo $contactBlocksHtml; ?>
</div>
<?php else: ?>
<section class="py-12">
    <div class="container mx-auto px-4">
<?php echo renderContactCardsHtml($contactCards, $gridCols, $iconPaths, $__ykEdit); ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
<?php echo renderContactFormHtml($__ykEdit); ?>

<?php echo renderContactMapHtml($__ykEdit); ?>
        </div>
    </div>
</section>
<?php endif; ?>

<?php require_once theme_path('layouts/footer.php'); ?>
