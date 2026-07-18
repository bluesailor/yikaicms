<?php
/**
 * 前台就地编辑覆盖层（P1）
 *
 * 登录管理员浏览「构建器页面」(content_type=blocks) 时，悬停区块高亮边框 + 浮出「编辑此区块」
 * 按钮，点击深链到后台构建器并定位到该区块（page_edit_advance.php?id=X&focus=N）。
 *
 * 仅在 page.php 为管理员设置了 $GLOBALS['ik_front_edit_cid'] 时输出（BlockRenderer 同时给
 * section 打了 data-yk-sec 索引）。普通访客/非 blocks 页无任何输出，也不写入公开缓存。
 */

if (!defined('ROOT_PATH')) exit;

function renderFrontEdit(): void
{
    if (empty($_SESSION['admin_id'])) return;
    $cid  = (int) ($GLOBALS['ik_front_edit_cid'] ?? 0);   // 构建器页面 channel id
    $home = !empty($GLOBALS['ik_front_edit_home']);        // 首页
    if ($cid <= 0 && !$home) return;
    ?>
    <style>
      #yk-edit-outline { position: absolute; z-index: 99990; pointer-events: none;
        border: 2px solid #2563eb; border-radius: 6px; box-shadow: 0 0 0 4px rgba(37,99,235,.12);
        display: none; transition: opacity .1s; }
      #yk-edit-btn { position: absolute; top: -1px; right: -1px; pointer-events: auto;
        background: #2563eb; color: #fff; font-size: 12px; line-height: 1; font-weight: 600;
        padding: 6px 10px; border-radius: 0 4px 0 8px; text-decoration: none; white-space: nowrap;
        font-family: system-ui,-apple-system,"Microsoft YaHei",sans-serif; cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,.2); }
      #yk-edit-btn:hover { background: #1d4ed8; }
      @media print { #yk-edit-outline { display: none !important; } }
    </style>
    <script>
    (function () {
      var cid = <?php echo $cid; ?>;
      var current = null, hideTimer = null;

      var box = document.createElement('div');
      box.id = 'yk-edit-outline';
      var btn = document.createElement('a');
      btn.id = 'yk-edit-btn';
      btn.innerHTML = '✎ 编辑此区块'; // ✎ 编辑此区块
      box.appendChild(btn);
      document.body.appendChild(box);

      function hide() { box.style.display = 'none'; current = null; }

      // 按元素上的标记算编辑链接：构建器区块 data-yk-sec / 首页区块 data-yk-home
      function editUrl(el) {
        if (el.hasAttribute('data-yk-sec')) {
          return '/admin/page_edit_advance.php?id=' + cid + '&focus=' + el.getAttribute('data-yk-sec');
        }
        if (el.hasAttribute('data-yk-home')) {
          return '/admin/setting_home.php?focus=' + encodeURIComponent(el.getAttribute('data-yk-home'));
        }
        return '#';
      }

      function place(sec) {
        var r = sec.getBoundingClientRect();
        box.style.display = 'block';
        box.style.top    = (r.top + window.scrollY) + 'px';
        box.style.left   = (r.left + window.scrollX) + 'px';
        box.style.width  = r.width + 'px';
        box.style.height = r.height + 'px';
        btn.href = editUrl(sec);
      }

      function attach(sec) {
        sec.addEventListener('mouseenter', function () { clearTimeout(hideTimer); current = sec; place(sec); });
        sec.addEventListener('mouseleave', function () { hideTimer = setTimeout(hide, 200); });
      }
      document.querySelectorAll('[data-yk-sec],[data-yk-home]').forEach(attach);

      btn.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
      btn.addEventListener('mouseleave', function () { hideTimer = setTimeout(hide, 200); });

      window.addEventListener('scroll', function () { if (current) place(current); }, { passive: true });
      window.addEventListener('resize', function () { if (current) place(current); });
    })();
    </script>
    <?php
}

add_action('ik_footer_before', 'renderFrontEdit');
