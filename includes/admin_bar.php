<?php
/**
 * 前台管理工具条（仿 WordPress Admin Bar）
 *   登录管理员浏览前台时，顶部显示浮条：回后台 / 新建 / 编辑此页 / 清缓存 / 退出。
 *   仅依赖共享 session（config.php 已全局 session_start），不依赖 admin/auth.php。
 *   模板可在渲染前设置 $GLOBALS['ik_edit_url'] = '后台编辑链接'，即出现「编辑此页」。
 */

if (!defined('ROOT_PATH')) exit;

function renderAdminBar(): void
{
    if (empty($_SESSION['admin_id'])) return;   // 未登录管理员 → 不显示

    $name = $_SESSION['admin_nickname'] ?? ($_SESSION['admin_username'] ?? '管理员');
    $editUrl = (string) ($GLOBALS['ik_edit_url'] ?? '');
    $brand = config('site_name', '后台');

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
      #ik-adminbar .ik-ab-brand { font-weight: 600; color: #fff; }
      #ik-adminbar .ik-ab-right { margin-left: auto; display: flex; align-items: center; }
      #ik-adminbar .ik-ab-new { position: relative; }
      #ik-adminbar .ik-ab-menu { display: none; position: absolute; top: 34px; left: 0; background: #1f2937;
        min-width: 130px; flex-direction: column; box-shadow: 0 6px 16px rgba(0,0,0,.35); padding: 4px 0; }
      #ik-adminbar .ik-ab-new:hover .ik-ab-menu { display: flex; }
      #ik-adminbar .ik-ab-menu a { height: 32px; line-height: 32px; }
      @media print { #ik-adminbar { display: none; } html { margin-top: 0 !important; } }
    </style>
    <div id="ik-adminbar">
      <a class="ik-ab-brand" href="/admin/">🏠 <?php echo e($brand); ?></a>
      <a href="/admin/">控制台</a>
      <?php if (!empty($createItems)): ?>
      <span class="ik-ab-new"><a href="javascript:;">＋ 新建 ▾</a>
        <span class="ik-ab-menu">
          <?php foreach ($createItems as $it): ?>
          <a href="<?php echo e($it['url']); ?>"><?php echo e($it['label']); ?></a>
          <?php endforeach; ?>
        </span>
      </span>
      <?php endif; ?>
      <?php if ($editUrl !== ''): ?><a href="<?php echo e($editUrl); ?>">✎ 编辑此页</a><?php endif; ?>
      <a href="/admin/setting_cache.php">清缓存</a>
      <span class="ik-ab-right">
        <a href="/admin/profile.php">👤 <?php echo e($name); ?></a>
        <a href="/admin/logout.php">退出</a>
      </span>
    </div>
    <?php
}

add_action('ik_footer_before', 'renderAdminBar');
