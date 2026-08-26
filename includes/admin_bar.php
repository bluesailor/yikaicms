<?php
/**
 * 前台管理工具条（仿 WordPress Admin Bar）
 *   登录管理员浏览前台时，顶部显示浮条：回后台 / 新建 / 编辑此页 / 编辑区域 / 清缓存 / 退出。
 *   仅依赖共享 session（config.php 已全局 session_start），不依赖 admin/auth.php。
 *   模板可在渲染前设置 $GLOBALS['ik_edit_url'] = '后台编辑链接'，即出现「编辑此页」。
 */

if (!defined('ROOT_PATH')) exit;

/**
 * 将遗留构建器编辑地址升级到当前 Blox 入口。
 *
 * 各主题仍可能写入旧地址，控制条在最后输出前统一收口，避免顶部“编辑”
 * 与画布就地编辑指向不同编辑器。
 */
function adminBarResolveEditUrl(string $editUrl): string
{
    if (!str_starts_with($editUrl, '/admin/page_edit_advance.php?')) {
        return $editUrl;
    }

    $query = (string) parse_url($editUrl, PHP_URL_QUERY);
    parse_str($query, $params);
    $isHome = (string) ($params['home'] ?? '') === '1';
    if ($isHome) {
        return bloxAdvancedFeaturesEnabled() ? '/admin/blox_editor.php?' . $query : '/admin/setting_home.php';
    }
    return '/admin/blox_editor.php?' . $query;
}

function renderAdminBar(): void
{
    if (isCleanFrontendPreview()) return;
    if (empty($_SESSION['admin_id'])) return;   // 未登录管理员 → 不显示

    $name = $_SESSION['admin_nickname'] ?? ($_SESSION['admin_username'] ?? __('admin_administrator'));
    $editUrl = adminBarResolveEditUrl((string) ($GLOBALS['ik_edit_url'] ?? ''));
    $brand = config('site_name', '') ?: adminBrandName();

    // 「新建」跟随站点实际启用的模块：按 channels 里存在的栏目类型生成
    $createMap = [
        'list'     => ['admin_article',  '/admin/article_edit.php'],
        'product'  => ['admin_product',  '/admin/product_edit.php'],
        'page'     => ['admin_page',     '/admin/page_edit.php'],
        'download' => ['admin_download', '/admin/download_edit.php'],
        'album'    => ['admin_album',    '/admin/album_edit.php'],
        'job'      => ['admin_job',      '/admin/job_edit.php'],
    ];
    $haveTypes = [];
    try {
        foreach (db()->fetchAll("SELECT DISTINCT type FROM `" . DB_PREFIX . "channels`") as $r) {
            $haveTypes[(string) $r['type']] = true;
        }
    } catch (\Throwable $e) {}
    $createItems = [];
    foreach ($createMap as $type => $m) {
        if (isset($haveTypes[$type])) $createItems[] = ['label' => __($m[0]), 'url' => $m[1]];
    }
    ?>
    <style>
      html { margin-top: 34px !important; }
      #ik-adminbar { position: fixed; top: 0; left: 0; right: 0; height: 34px; background: #1f2937;
        color: #cbd5e1; font-size: 13px; z-index: 99999; display: flex; align-items: center;
        padding: 0 6px; box-shadow: 0 1px 4px rgba(0,0,0,.3);
        font-family: system-ui,-apple-system,"Microsoft YaHei",sans-serif; }
      #ik-adminbar a { color: #cbd5e1; text-decoration: none; padding: 0 10px; height: 34px;
        line-height: 34px; display: inline-flex; align-items: center; gap: 4px; white-space: nowrap; }
      #ik-adminbar a:hover { background: #374151; color: #fff; }
      #ik-adminbar .ik-ab-brand { font-weight: 600; color: #fff; min-width: 0; }
      #ik-adminbar .ik-ab-brand-name { max-width: 180px; overflow: hidden; text-overflow: ellipsis; }
      #ik-adminbar .ik-ab-icon { width: 15px; height: 15px; flex: 0 0 auto; }
      #ik-adminbar .ik-ab-right { margin-left: auto; display: flex; align-items: center; }
      #ik-adminbar .ik-ab-new { position: relative; }
      #ik-adminbar .ik-ab-menu { display: none; position: absolute; top: 34px; left: 0; background: #1f2937;
        min-width: 130px; flex-direction: column; box-shadow: 0 6px 16px rgba(0,0,0,.35); padding: 4px 0; }
      #ik-adminbar .ik-ab-new:hover .ik-ab-menu { display: flex; }
      #ik-adminbar .ik-ab-menu a { height: 32px; line-height: 32px; }
      #ik-adminbar .ik-ab-regions { position: relative; height: 34px; }
      #ik-adminbar .ik-ab-regions[hidden] { display: none; }
      #ik-adminbar .ik-ab-regions summary { box-sizing: border-box; height: 34px; padding: 0 10px;
        color: #cbd5e1; display: flex; align-items: center; gap: 5px; cursor: pointer; list-style: none;
        white-space: nowrap; user-select: none; }
      #ik-adminbar .ik-ab-regions summary::-webkit-details-marker { display: none; }
      #ik-adminbar .ik-ab-regions summary:hover,
      #ik-adminbar .ik-ab-regions[open] summary { background: #374151; color: #fff; }
      #ik-adminbar .ik-ab-regions summary:focus-visible { outline: 2px solid #93c5fd; outline-offset: -2px; }
      #ik-adminbar .ik-ab-region-caret { font-size: 10px; transition: transform .15s ease; }
      #ik-adminbar .ik-ab-regions[open] .ik-ab-region-caret { transform: rotate(180deg); }
      #ik-adminbar .ik-ab-region-menu { position: absolute; top: 34px; left: 0; width: 300px;
        max-width: calc(100vw - 12px); max-height: calc(100vh - 46px); overflow-y: auto; padding: 6px 0 8px;
        background: #1f2937; color: #cbd5e1; box-shadow: 0 8px 24px rgba(0,0,0,.38); }
      #ik-adminbar .ik-ab-region-group + .ik-ab-region-group { border-top: 1px solid #374151; margin-top: 5px; padding-top: 5px; }
      #ik-adminbar .ik-ab-region-heading { display: block; padding: 4px 12px 3px; color: #94a3b8;
        font-size: 11px; font-weight: 700; line-height: 1.4; }
      #ik-adminbar .ik-ab-region-menu a { box-sizing: border-box; width: 100%; min-height: 36px; height: auto;
        padding: 7px 12px; line-height: 1.35; overflow: hidden; text-overflow: ellipsis; }
      #ik-adminbar .ik-ab-region-menu a:focus-visible { outline: 2px solid #93c5fd; outline-offset: -2px; color: #fff; }
      @media (max-width: 767px) {
        #ik-adminbar { padding: 0 2px; }
        #ik-adminbar a, #ik-adminbar .ik-ab-regions summary { padding-left: 8px; padding-right: 8px; }
        #ik-adminbar .ik-ab-optional, #ik-adminbar .ik-ab-profile { display: none; }
        #ik-adminbar .ik-ab-region-menu { position: fixed; top: 34px; left: 6px; right: 6px; width: auto; max-width: none; }
      }
      @media (max-width: 520px) {
        #ik-adminbar .ik-ab-brand-name { display: none; }
        #ik-adminbar .ik-ab-page-edit-label { display: none; }
      }
      @media print { #ik-adminbar { display: none; } html { margin-top: 0 !important; } }
    </style>
    <div id="ik-adminbar">
      <a class="ik-ab-brand" href="/admin/" aria-label="<?php echo e(__('ab_dashboard')); ?>">
        <svg class="ik-ab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m3 11 9-8 9 8"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/></svg>
        <span class="ik-ab-brand-name"><?php echo e($brand); ?></span>
      </a>
      <a class="ik-ab-optional" href="/admin/"><?php echo e(__('ab_dashboard')); ?></a>
      <?php if (!empty($createItems)): ?>
      <span class="ik-ab-new ik-ab-optional"><a href="javascript:;">＋ <?php echo e(__('ab_new')); ?> ▾</a>
        <span class="ik-ab-menu">
          <?php foreach ($createItems as $it): ?>
          <a href="<?php echo e($it['url']); ?>"><?php echo e($it['label']); ?></a>
          <?php endforeach; ?>
        </span>
      </span>
      <?php endif; ?>
      <?php if ($editUrl !== ''): ?>
      <a class="ik-ab-page-edit" href="<?php echo e($editUrl); ?>">
        <svg class="ik-ab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        <span class="ik-ab-page-edit-label"><?php echo e(__('ab_edit_page')); ?></span>
      </a>
      <?php endif; ?>
      <details id="ik-ab-regions" class="ik-ab-regions" data-page-edit-url="<?php echo e($editUrl); ?>" data-testid="admin-edit-regions" hidden>
        <summary aria-label="<?php echo e(__('ab_edit_regions')); ?>">
          <svg class="ik-ab-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
          <span><?php echo e(__('ab_edit_regions')); ?></span>
          <span class="ik-ab-region-caret" aria-hidden="true">▾</span>
        </summary>
        <nav class="ik-ab-region-menu" aria-label="<?php echo e(__('ab_edit_regions')); ?>" data-testid="admin-edit-region-menu"></nav>
      </details>
      <a class="ik-ab-optional" href="/admin/setting_cache.php"><?php echo e(__('ab_clear_cache')); ?></a>
      <span class="ik-ab-right">
        <a class="ik-ab-profile" href="/admin/profile.php">👤 <?php echo e($name); ?></a>
        <a href="/admin/logout.php"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg> <?php echo e(__('admin_logout')); ?></a>
      </span>
    </div>
    <?php
}

add_action('ik_footer_before', 'renderAdminBar');
