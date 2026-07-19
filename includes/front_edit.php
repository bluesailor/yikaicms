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
    $csrf = function_exists('csrfToken') ? csrfToken() : '';
    // 覆盖层对任何登录管理员都渲染（区块悬停按标记生效；Logo 就地编辑全站可用）
    ?>
    <style>
      #yk-edit-outline { position: absolute; z-index: 99990; pointer-events: none;
        border: 2px solid #2563eb; border-radius: 6px; box-shadow: 0 0 0 4px rgba(37,99,235,.12);
        display: none; transition: opacity .1s; }
      #yk-edit-btn { position: absolute; top: 0; right: 0; pointer-events: auto;
        background: #2563eb; color: #fff; font-size: 16px; line-height: 1.1; font-weight: 700;
        padding: 10px 18px; border-radius: 0 6px 0 12px; text-decoration: none; white-space: nowrap;
        font-family: system-ui,-apple-system,"Microsoft YaHei",sans-serif; cursor: pointer;
        box-shadow: 0 3px 12px rgba(0,0,0,.28); letter-spacing: .5px; }
      #yk-edit-btn:hover { background: #1d4ed8; }
      /* Logo 就地编辑 */
      [data-yk-logo] { position: relative; }
      [data-yk-logo]::after { content: ""; position: absolute; inset: -6px; border: 2px dashed transparent;
        border-radius: 6px; pointer-events: none; transition: border-color .15s; }
      [data-yk-logo]:hover::after { border-color: #2563eb; }
      .yk-logo-btn { position: absolute; top: -10px; right: -10px; z-index: 99991;
        background: #2563eb; color: #fff; font-size: 11px; line-height: 1; font-weight: 600;
        padding: 4px 8px; border-radius: 999px; white-space: nowrap; cursor: pointer; display: none;
        box-shadow: 0 2px 8px rgba(0,0,0,.25); font-family: system-ui,-apple-system,"Microsoft YaHei",sans-serif; }
      [data-yk-logo]:hover .yk-logo-btn, .yk-logo-btn:hover { display: block; }
      @media print { #yk-edit-outline, .yk-logo-btn { display: none !important; } }
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

      // 按元素上的标记算编辑链接：构建器区块 data-yk-sec / 首页区块 data-yk-home / 导航 data-yk-nav
      function editUrl(el) {
        if (el.hasAttribute('data-yk-sec')) {
          return '/admin/page_edit_advance.php?id=' + cid + '&focus=' + el.getAttribute('data-yk-sec');
        }
        if (el.hasAttribute('data-yk-home')) {
          return '/admin/setting_home.php?focus=' + encodeURIComponent(el.getAttribute('data-yk-home'));
        }
        if (el.hasAttribute('data-yk-nav')) {
          return '/admin/channel.php';
        }
        if (el.hasAttribute('data-yk-footer')) {
          return '/admin/setting.php?tab=footer';
        }
        if (el.hasAttribute('data-yk-partners')) {
          return '/admin/link.php';
        }
        if (el.hasAttribute('data-yk-edit')) {
          return el.getAttribute('data-yk-edit');  // 通用：URL 直接写在属性上（内容/产品/单页详情）
        }
        return '#';
      }
      function editLabel(el) {
        if (el.hasAttribute('data-yk-nav'))      return '✎ 编辑导航';
        if (el.hasAttribute('data-yk-footer'))   return '✎ 编辑页脚';
        if (el.hasAttribute('data-yk-partners')) return '✎ 编辑合作伙伴';
        if (el.hasAttribute('data-yk-edit'))     return el.getAttribute('data-yk-edit-label') || '✎ 编辑内容';
        return '✎ 编辑此区块';
      }

      function place(sec) {
        var r = sec.getBoundingClientRect();
        box.style.display = 'block';
        box.style.top    = (r.top + window.scrollY) + 'px';
        box.style.left   = (r.left + window.scrollX) + 'px';
        box.style.width  = r.width + 'px';
        box.style.height = r.height + 'px';
        btn.href = editUrl(sec);
        btn.textContent = editLabel(sec);
      }

      function attach(sec) {
        sec.addEventListener('mouseenter', function () { clearTimeout(hideTimer); current = sec; place(sec); });
        sec.addEventListener('mouseleave', function () { hideTimer = setTimeout(hide, 200); });
      }
      // 本脚本在 ik_footer_before 处执行，页脚等位于其后的元素此刻尚未入 DOM，
      // 故延到 DOMContentLoaded 再扫描绑定（Logo/导航/首页区块在前，也一并延后无碍）。
      function onReady(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
      }
      onReady(function () {
        document.querySelectorAll('[data-yk-sec],[data-yk-home],[data-yk-nav],[data-yk-footer],[data-yk-partners],[data-yk-edit]').forEach(attach);
      });

      btn.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
      btn.addEventListener('mouseleave', function () { hideTimer = setTimeout(hide, 200); });

      window.addEventListener('scroll', function () { if (current) place(current); }, { passive: true });
      window.addEventListener('resize', function () { if (current) place(current); });

      // ===== Logo 就地编辑：悬停显示「换Logo」，选图后上传 + 保存 + 实时替换 =====
      var csrf = <?php echo json_encode($csrf); ?>;
      var fileInput = document.createElement('input');
      fileInput.type = 'file'; fileInput.accept = 'image/*'; fileInput.style.display = 'none';
      document.body.appendChild(fileInput);

      onReady(function () {
        document.querySelectorAll('[data-yk-logo]').forEach(function (logo) {
          var b = document.createElement('span');
          b.className = 'yk-logo-btn';
          b.textContent = '✎ 换Logo';
          logo.appendChild(b);
          b.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            fileInput.onchange = function () {
              if (!fileInput.files[0]) return;
              uploadAndSaveLogo(fileInput.files[0]);
              fileInput.value = '';
            };
            fileInput.click();
          });
        });
      });

      function toast(msg, ok) {
        var t = document.createElement('div');
        t.textContent = msg;
        t.style.cssText = 'position:fixed;left:50%;top:50px;transform:translateX(-50%);z-index:100000;'
          + 'background:' + (ok ? '#16a34a' : '#dc2626') + ';color:#fff;padding:8px 16px;border-radius:8px;'
          + 'font-size:13px;box-shadow:0 4px 16px rgba(0,0,0,.25)';
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 2200);
      }

      function uploadAndSaveLogo(file) {
        var fd = new FormData(); fd.append('file', file); fd.append('type', 'images'); fd.append('_token', csrf);
        toast('上传中…', true);
        fetch('/admin/upload.php', { method: 'POST', body: fd })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d.code !== 0) { toast(d.msg || '上传失败', false); return; }
            var url = d.data.url;
            var sd = new FormData();
            sd.append('key', 'site_logo'); sd.append('value', url); sd.append('_token', csrf);
            return fetch('/admin/front_edit_api.php', { method: 'POST', body: sd })
              .then(function (r) { return r.json(); })
              .then(function (s) {
                if (s.code !== 0) { toast(s.msg || '保存失败', false); return; }
                // 实时替换所有 Logo 图；原来是文字站名的则刷新以显示新图
                var imgs = document.querySelectorAll('[data-yk-logo] img');
                if (imgs.length) { imgs.forEach(function (im) { im.src = url; }); toast('Logo 已更新', true); }
                else { toast('Logo 已保存，刷新中…', true); setTimeout(function () { location.reload(); }, 700); }
              });
          })
          .catch(function (err) { toast('网络错误：' + err.message, false); });
      }
    })();
    </script>
    <?php
}

add_action('ik_footer_before', 'renderFrontEdit');
