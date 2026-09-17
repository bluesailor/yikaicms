<?php
declare(strict_types=1);

/** Resolve the same editing target and publication evidence used by the editor. */
function websitePagePresentation(array $page): array
{
    $target = pagePrimaryEditTarget($page);
    $redirected = (int) $target['id'] !== (int) $page['id'];
    $url = pagePrimaryEditUrl($page);
    $type = 'page';
    $canEdit = hasPermission('blox_edit') && bloxPageEditorEnabled();
    if (($target['type'] ?? '') === 'album') {
        $type = 'album';
        $canEdit = (int) ($target['album_id'] ?? 0) > 0 ? hasPermission('media') : hasPermission('*');
    } elseif (isTimelinePageChannel($target)) {
        $type = 'timeline';
        $canEdit = hasPermission('edit_timeline');
    } elseif (!str_starts_with($url, '/admin/blox_editor.php')) {
        $type = 'redirect';
        $canEdit = hasPermission('*');
    }
    $publication = $type === 'page' ? 'empty' : 'dynamic';
    $modified = max((int) ($page['updated_at'] ?? 0), (int) ($page['content_updated_at'] ?? 0), (int) ($target['updated_at'] ?? 0));
    if ($type === 'page') {
        try {
            $state = PageBloxDocument::load((int) $target['id']);
            $publication = $state['has_unpublished_changes']
                ? ($state['has_published'] ? 'changed' : 'draft')
                : ($state['has_published'] ? 'published' : ($state['uses_legacy_html'] ? 'legacy' : 'empty'));
            if ($state['has_draft']) {
                $draft = bloxPageDraftModel()->findByPageId((int) $target['id']);
                $modified = max($modified, (int) ($draft['updated_at'] ?? 0));
            }
        } catch (Throwable $error) {
            $publication = 'unknown';
        }
    }
    return array_merge($page, [
        'edit_target' => $target, 'edit_url' => $url, 'can_edit' => $canEdit,
        'redirected' => $redirected, 'page_kind' => $type, 'publication' => $publication,
        'modified_at' => $modified, 'public_url' => channelUrl($page),
    ]);
}

/** Keep visibility and publication as separate filters. */
function websitePagesFilter(array $pages, string $query, string $filter): array
{
    $query = mb_strtolower(trim($query));
    return array_values(array_filter($pages, static function (array $page) use ($query, $filter): bool {
        if ($query !== '' && mb_strpos(mb_strtolower((string) $page['name'] . ' ' . $page['public_url'] . ' ' . ($page['parent_name'] ?? '')), $query) === false) {
            return false;
        }
        return match ($filter) {
            'active' => !empty($page['status']),
            'disabled' => empty($page['status']),
            'changed' => $page['publication'] === 'changed',
            'draft' => $page['publication'] === 'draft',
            default => true,
        };
    }));
}

/** Content language is independent of the administrator's interface language. */
function websiteHomeTitle(string $lang): string
{
    static $titles = [];
    $lang = in_array($lang, ['zh-CN', 'en', 'ja'], true) ? $lang : 'zh-CN';
    if (!isset($titles[$lang])) {
        $messages = require ROOT_PATH . '/lang/' . $lang . '.php';
        $titles[$lang] = (string) $messages[$lang === 'zh-CN' ? 'site_design_home' : 'home'];
    }
    return $titles[$lang];
}

/** Shared cards for the page catalogue and the design overview. */
function renderWebsitePageCard(array $page, bool $overview = false): string
{
    $id = (int) $page['id'];
    $disabled = empty($page['status']);
    // 与列表视图同一条删除红线：仅已停用、非系统页、非相册，且持 delete_page 权限
    $deletable = $disabled && empty($page['is_system']) && ($page['type'] ?? '') !== 'album' && hasPermission('delete_page');
    ob_start();
    ?>
    <article class="website-page-card<?php echo $disabled ? ' is-disabled' : ''; ?>" data-testid="website-page-card-<?php echo $id; ?>"<?php echo $disabled ? ' data-page-disabled="1"' : ''; ?>>
        <div class="website-page-body">
            <div class="website-page-title"><h3><?php echo e((string) $page['name']); ?></h3><span><?php echo e((string) $page['lang']); ?></span></div>
            <p class="website-page-url"><?php echo e($page['public_url']); ?></p>
            <div class="website-page-badges">
                <span class="<?php echo $disabled ? 'is-off' : 'is-live'; ?>"><?php if ($disabled): ?><i class="ti ti-eye-off" aria-hidden="true"></i><?php endif; ?><?php echo e(__($disabled ? 'website_pages_disabled' : 'website_pages_active')); ?></span>
                <span class="<?php echo in_array($page['publication'], ['draft','changed','unknown'], true) ? 'is-pending' : ''; ?>"><?php echo e(__('website_pages_state_' . $page['publication'])); ?></span>
                <?php if ($page['page_kind'] !== 'page'): ?><span><?php echo e(__('website_pages_kind_' . $page['page_kind'])); ?></span><?php endif; ?>
            </div>
            <?php if ($page['redirected']): ?>
            <p class="website-page-note" data-testid="page-redirect-target-<?php echo $id; ?>"><?php echo e(__('page_redirect_target_badge', ['name'=>(string) $page['edit_target']['name']])); ?></p>
            <?php endif; ?>
            <p class="website-page-note"><?php echo e(!empty($page['parent_name']) ? (string) $page['parent_name'] : __('admin_top_level')); ?><?php if (!empty($page['is_nav'])): ?> · <?php echo e(__('page_main_nav')); ?><?php endif; ?></p>
            <p class="website-page-note"><?php echo e(__('website_pages_updated')); ?> <?php echo e($page['modified_at'] > 0 ? date('Y-m-d H:i', $page['modified_at']) : __('website_pages_unknown_date')); ?></p>
            <div class="website-page-actions">
                <?php if ($page['can_edit']): ?>
                <a class="website-page-primary" data-testid="page-primary-edit-<?php echo $id; ?>" href="<?php echo e($page['edit_url']); ?>"><i class="ti <?php echo $page['page_kind'] === 'page' ? 'ti-layout' : 'ti-pencil'; ?>" aria-hidden="true"></i><?php echo e(__($page['page_kind'] === 'page' ? 'website_structural_design' : 'admin_content_edit')); ?></a>
                <?php else: ?><span class="website-page-note"><?php echo e(__('website_pages_no_permission')); ?></span><?php endif; ?>
                <?php // 已停用页前台是 404，不给浏览入口 ?>
                <?php if (!$disabled): ?>
                <a href="<?php echo e($page['public_url']); ?>" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i><?php echo e(__('website_pages_visit')); ?><span class="sr-only"> · <?php echo e((string) $page['name']); ?></span></a>
                <?php endif; ?>
                <?php // 已停用卡片：恢复/删除提到卡面（不再藏进"…"菜单） ?>
                <?php if (!$overview && $disabled && ($page['type'] ?? '') !== 'album'): ?>
                <button type="button" class="website-page-restore" onclick="toggleStatus(<?php echo $id; ?>, this)"
                        data-testid="page-restore-<?php echo $id; ?>">
                    <i class="ti ti-eye" aria-hidden="true"></i><?php echo e(__('admin_channel_restore')); ?>
                </button>
                <?php if ($deletable): ?>
                <button type="button" class="website-page-delete" data-page-delete="<?php echo $id; ?>"
                        data-page-name="<?php echo e((string) $page['name']); ?>" data-testid="page-delete-<?php echo $id; ?>">
                    <i class="ti ti-trash" aria-hidden="true"></i><?php echo e(__('admin_delete')); ?>
                </button>
                <?php endif; ?>
                <?php endif; ?>
                <?php if (!$overview && (hasPermission('*') || ($page['type'] ?? '') !== 'album')): ?>
                <details class="website-page-more">
                    <summary aria-label="<?php echo e(__('website_pages_more', ['name'=>(string) $page['name']])); ?>"><i class="ti ti-dots" aria-hidden="true"></i></summary>
                    <div>
                        <?php if (hasPermission('*')): ?><a href="/admin/channel.php?edit=<?php echo $id; ?>&amp;tab=main"><?php echo e(__('website_pages_settings')); ?></a><?php endif; ?>
                        <?php if (($page['type'] ?? '') !== 'album' && !$disabled): ?>
                        <button type="button" onclick="toggleStatus(<?php echo $id; ?>, this)"><?php echo e(__('pg_disable')); ?></button>
                        <?php endif; ?>
                    </div>
                </details>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

/**
 * 结构页面：栏目首页与详情页模板——它们不是"单页"，但同样要排版，
 * 过去只能从栏目列表或模板库绕进去。这里统一收拢成可直达编辑器的入口。
 *
 * 栏目首页：仅顶级 product / list 栏目有 Blox 落地文档（见 admin/blox_editor.php 的类型闸
 * 与 list.php 的前台解析）；case/download/job 等类型暂无该文档，列出但标明不可排版，
 * 免得以为漏了。
 *
 * @return list<array<string,mixed>>
 */
function websiteStructuralPages(string $lang): array
{
    $items = [];

    // 取整行：channelUrl() 需要 slug/parent_id/link_url 等字段，半拉行会生成错的前台地址
    $channels = db()->fetchAll(
        'SELECT * FROM ' . DB_PREFIX . 'channels'
        . ' WHERE lang = ? AND parent_id = 0 AND type IN (?, ?, ?, ?, ?)'
        . ' ORDER BY sort_order ASC, id ASC',
        [$lang, 'product', 'list', 'case', 'download', 'job']
    );
    $canEditChannel = hasPermission('blox_edit') && bloxPageEditorEnabled();
    foreach ($channels as $channel) {
        $id = (int) $channel['id'];
        $type = (string) $channel['type'];
        $designable = in_array($type, ['product', 'list'], true);
        $publication = 'empty';
        if ($designable) {
            try {
                $state = $type === 'list'
                    ? ChannelBloxDocument::load($id)
                    : PageBloxDocument::load($id);
                $publication = $state['has_unpublished_changes']
                    ? ($state['has_published'] ? 'changed' : 'draft')
                    : ($state['has_published'] ? 'published' : 'empty');
            } catch (Throwable) {
                $publication = 'unknown';
            }
        }
        $items[] = [
            'kind' => 'channel',
            'id' => $id,
            'name' => (string) $channel['name'],
            'lang' => $lang,
            'channel_type' => $type,
            'designable' => $designable,
            'publication' => $publication,
            'disabled' => empty($channel['status']),
            'edit_url' => $designable ? '/admin/blox_editor.php?id=' . $id : '',
            'public_url' => channelUrl($channel),
            'can_edit' => $designable && $canEditChannel,
        ];
    }

    // 详情页模板：一条内容的版式（文章/产品详情）。权限与模板库一致（blox_global）。
    $canEditTemplate = hasPermission('blox_global');
    foreach (['article-detail', 'product-detail'] as $templateType) {
        foreach (bloxTemplateModel()->catalog($templateType) as $row) {
            $id = (int) $row['id'];
            $items[] = [
                'kind' => 'detail',
                'id' => $id,
                'name' => BloxAreaTemplatePresets::displayName($row),
                'lang' => $lang,
                'channel_type' => $templateType,
                'designable' => true,
                'publication' => (int) ($row['published_at'] ?? 0) > 0 ? 'published' : 'draft',
                'disabled' => (int) ($row['status'] ?? 1) !== 1,
                'edit_url' => '/admin/blox_editor.php?template=' . $id,
                'public_url' => '',
                'can_edit' => $canEditTemplate,
            ];
        }
    }

    return $items;
}

/** 结构页面卡片：只为"进去排版"服务，不做启停/删除等页面管理动作。 */
function renderWebsiteStructuralCard(array $item): string
{
    $id = (int) $item['id'];
    $kind = (string) $item['kind'];
    $testId = $kind . '-' . $id;
    ob_start();
    ?>
    <article class="website-page-card website-page-structural<?php echo $item['designable'] ? '' : ' is-disabled'; ?>"
             data-testid="website-structural-card-<?php echo e($testId); ?>" data-structural-kind="<?php echo e($kind); ?>">
        <div class="website-page-body">
            <div class="website-page-title">
                <h3><?php echo e((string) $item['name']); ?></h3>
                <span><?php echo e((string) $item['lang']); ?></span>
            </div>
            <p class="website-page-url"><?php echo e(__('website_structural_kind_' . $kind)); ?></p>
            <div class="website-page-badges">
                <span><?php echo e(__('website_structural_type_' . str_replace('-', '_', (string) $item['channel_type']))); ?></span>
                <?php if ($item['designable']): ?>
                <span class="<?php echo in_array($item['publication'], ['draft','changed','unknown'], true) ? 'is-pending' : ''; ?>"><?php echo e(__('website_pages_state_' . $item['publication'])); ?></span>
                <?php else: ?>
                <span class="is-off"><i class="ti ti-eye-off" aria-hidden="true"></i><?php echo e(__('website_structural_no_layout')); ?></span>
                <?php endif; ?>
            </div>
            <?php if (!$item['designable']): ?>
            <p class="website-page-note"><?php echo e(__('website_structural_no_layout_hint')); ?></p>
            <?php endif; ?>
            <div class="website-page-actions">
                <?php if ($item['can_edit']): ?>
                <a class="website-page-primary" data-testid="structural-edit-<?php echo e($testId); ?>"
                   href="<?php echo e((string) $item['edit_url']); ?>"><i class="ti ti-layout" aria-hidden="true"></i><?php echo e(__('website_structural_design')); ?></a>
                <?php elseif ($item['designable']): ?>
                <span class="website-page-note"><?php echo e(__('website_pages_no_permission')); ?></span>
                <?php endif; ?>
                <?php if (($item['public_url'] ?? '') !== ''): ?>
                <a href="<?php echo e((string) $item['public_url']); ?>" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i><?php echo e(__('website_pages_visit')); ?><span class="sr-only"> · <?php echo e((string) $item['name']); ?></span></a>
                <?php endif; ?>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}

function renderWebsiteHomeCard(string $lang): string
{
    $url = langUrl('/', $lang);
    ob_start();
    ?>
    <article class="website-page-card website-page-home" data-testid="page-home-card">
        <div class="website-page-body">
            <div class="website-page-title"><h3><?php echo e(websiteHomeTitle($lang)); ?></h3><span><?php echo e($lang); ?></span></div>
            <p class="website-page-url"><?php echo e($url); ?></p>
            <div class="website-page-badges"><span class="is-live"><?php echo e(__('admin_label_fixed')); ?></span><span><?php echo e(__('site_design_theme_hint', ['theme'=>(string) config('current_theme', 'default')])); ?></span></div>
            <p class="website-page-note"><?php echo e(__(HomeBloxDocument::isActive() && HomeBloxDocument::hasPublished() ? 'site_design_home_active' : 'site_design_home_structured')); ?></p>
            <div class="website-page-actions">
                <?php if (bloxPageEditorEnabled() && hasPermission('blox_home')): ?>
                <a class="website-page-primary" data-testid="page-home-edit" href="/admin/blox_editor.php?home=1&amp;lang=<?php echo e(rawurlencode($lang)); ?>"><i class="ti ti-layout" aria-hidden="true"></i><?php echo e(__('website_structural_design')); ?></a>
                <?php endif; ?>
                <a href="<?php echo e($url); ?>" target="_blank" rel="noopener"><i class="ti ti-external-link" aria-hidden="true"></i><?php echo e(__('website_pages_visit')); ?></a>
            </div>
        </div>
    </article>
    <?php
    return (string) ob_get_clean();
}
