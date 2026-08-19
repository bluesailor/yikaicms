<?php
/**
 * 前台就地编辑覆盖层（P1）
 *
 * 登录管理员浏览「构建器页面」(content_type=blocks) 时，悬停区块高亮边框 + 浮出「编辑此区块」
 * 按钮，点击深链到 Blox 并定位到该区块（blox_editor.php?id=X&focus=N）。首页统一使用
 * 顶部管理条的「编辑此页」，不在真实页面上叠加区块操作层。
 *
 * 仅在 page.php 为管理员设置了 $GLOBALS['ik_front_edit_cid'] 时输出（BlockRenderer 同时给
 * section 打了 data-yk-sec 索引）。普通访客/非 blocks 页无任何输出，也不写入公开缓存。
 */

if (!defined('ROOT_PATH')) exit;

function renderFrontEdit(): void
{
    if (isCleanFrontendPreview()) return;
    if (empty($_SESSION['admin_id'])) return;
    $cid  = (int) ($GLOBALS['ik_front_edit_cid'] ?? 0);   // 构建器页面 channel id
    $csrf = function_exists('csrfToken') ? csrfToken() : '';
    // 覆盖层对任何登录管理员都渲染（区块悬停按标记生效；Logo 就地编辑全站可用）
    ?>
    <style>
      #yk-edit-outline { position: absolute; z-index: 99990; pointer-events: none;
        border: 2px solid #2563eb; border-radius: 6px; box-shadow: 0 0 0 4px rgba(37,99,235,.12);
        background: rgba(37,99,235,.12); /* 颜色滤镜：悬停区域整体罩一层蓝，比只有外框显眼 */
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
      [data-yk-logo]:hover::after { border-color: #2563eb; background: rgba(37,99,235,.10); }
      .yk-logo-btns { position: absolute; top: -10px; right: -10px; z-index: 99991; display: none; gap: 4px; }
      [data-yk-logo]:hover .yk-logo-btns, .yk-logo-btns:hover { display: flex; }
      .yk-logo-btn { background: #2563eb; color: #fff; font-size: 11px; line-height: 1; font-weight: 600;
        padding: 4px 8px; border-radius: 999px; white-space: nowrap; cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,.25); font-family: system-ui,-apple-system,"Microsoft YaHei",sans-serif;
        text-decoration: none; }
      .yk-logo-btn:hover { background: #1d4ed8; color: #fff; }
      .yk-logo-btn--make { background: #7c3aed; }
      .yk-logo-btn--make:hover { background: #6d28d9; }
      @media print { #yk-edit-outline, .yk-logo-btns { display: none !important; } }
      /* 换Logo 对话框 */
      .yk-lgd-mask { position: fixed; inset: 0; z-index: 99995; background: rgba(15,23,42,.55); display: flex; align-items: center; justify-content: center; padding: 16px; }
      .yk-lgd { background: #fff; border-radius: 12px; width: 560px; max-width: 100%; max-height: 90vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); font-size: 14px; color: #111827; }
      .yk-lgd-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid #f1f5f9; font-weight: 700; }
      .yk-lgd-x { cursor: pointer; border: 0; background: none; font-size: 18px; line-height: 1; color: #94a3b8; }
      .yk-lgd-x:hover { color: #334155; }
      .yk-lgd-body { padding: 16px 18px; overflow-y: auto; }
      .yk-lgd-prev { background: #f8fafc; border: 1px dashed #e2e8f0; border-radius: 10px; min-height: 96px; display: flex; align-items: center; justify-content: center; padding: 12px; margin-bottom: 14px; }
      .yk-lgd-prev img { max-width: 100%; }
      .yk-lgd-row { margin-bottom: 12px; }
      .yk-lgd-label { display: block; font-size: 12px; color: #64748b; margin-bottom: 4px; }
      .yk-lgd-input { width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; padding: 7px 10px; font-size: 13px; box-sizing: border-box; }
      .yk-lgd-input:focus { outline: 2px solid #bfdbfe; border-color: #60a5fa; }
      .yk-lgd-tools { display: flex; gap: 8px; margin-bottom: 10px; }
      .yk-lgd-btn { border: 0; border-radius: 8px; padding: 8px 14px; font-size: 13px; font-weight: 600; cursor: pointer; }
      .yk-lgd-btn--up { background: #2563eb; color: #fff; }
      .yk-lgd-btn--up:hover { background: #1d4ed8; }
      .yk-lgd-btn--ghost { background: #f1f5f9; color: #334155; }
      .yk-lgd-btn--ghost:hover { background: #e2e8f0; }
      .yk-lgd-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; max-height: 180px; overflow-y: auto; padding: 2px; }
      .yk-lgd-item { border: 2px solid #e2e8f0; border-radius: 8px; height: 64px; display: flex; align-items: center; justify-content: center; background: #fff; cursor: pointer; overflow: hidden; padding: 4px; }
      .yk-lgd-item:hover { border-color: #93c5fd; }
      .yk-lgd-item.sel { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,.2); }
      .yk-lgd-item img { max-width: 100%; max-height: 100%; object-fit: contain; }
      .yk-lgd-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid #f1f5f9; }
      .yk-lgd-num { display: flex; align-items: center; gap: 6px; }
      .yk-lgd-num .yk-lgd-input { width: 96px; }
    </style>
    <script>
    (function () {
      var cid = <?php echo $cid; ?>;
      var current = null, hideTimer = null;

      var box = document.createElement('div');
      box.id = 'yk-edit-outline';
      var btn = document.createElement('a');
      btn.id = 'yk-edit-btn';
      btn.innerHTML = '✎ ' + <?php echo json_encode(__('fe_edit_block'), JSON_UNESCAPED_UNICODE); ?>;
      box.appendChild(btn);

      document.body.appendChild(box);

      function hide() { box.style.display = 'none'; current = null; }

      // 按元素上的标记算编辑链接：构建器区块 / 导航 / 页脚 / 通用内容。
      function editUrl(el) {
        if (el.hasAttribute('data-yk-sec')) {
          return '/admin/blox_editor.php?id=' + cid + '&focus=' + el.getAttribute('data-yk-sec');
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
        if (el.hasAttribute('data-yk-nav'))      return '✎ ' + <?php echo json_encode(__('fe_edit_nav'), JSON_UNESCAPED_UNICODE); ?>;
        if (el.hasAttribute('data-yk-footer'))   return '✎ ' + <?php echo json_encode(__('fe_edit_footer'), JSON_UNESCAPED_UNICODE); ?>;
        if (el.hasAttribute('data-yk-partners')) return '✎ ' + <?php echo json_encode(__('fe_edit_partners'), JSON_UNESCAPED_UNICODE); ?>;
        if (el.hasAttribute('data-yk-edit'))     return el.getAttribute('data-yk-edit-label') || ('✎ ' + <?php echo json_encode(__('fe_edit_content'), JSON_UNESCAPED_UNICODE); ?>);
        return '✎ ' + <?php echo json_encode(__('fe_edit_block'), JSON_UNESCAPED_UNICODE); ?>;
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
        document.querySelectorAll('[data-yk-sec],[data-yk-nav],[data-yk-footer],[data-yk-partners],[data-yk-edit]').forEach(attach);
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

      var hasLogoMaker = <?php echo json_encode(function_exists('getActivePlugins') && in_array('logo-maker', getActivePlugins(), true)); ?>;
      onReady(function () {
        document.querySelectorAll('[data-yk-logo]').forEach(function (logo) {
          var wrap = document.createElement('span');
          wrap.className = 'yk-logo-btns';
          var b = document.createElement('span');
          b.className = 'yk-logo-btn';
          b.textContent = '✎ ' + <?php echo json_encode(__('fe_change_logo'), JSON_UNESCAPED_UNICODE); ?>;
          b.addEventListener('click', function (e) {
            e.preventDefault(); e.stopPropagation();
            openLogoDialog();
          });
          wrap.appendChild(b);
          if (hasLogoMaker) {
            // LOGO 制作在线入口（做好后可一键设为站点 Logo）
            var mk = document.createElement('a');
            mk.className = 'yk-logo-btn yk-logo-btn--make';
            mk.textContent = '★ ' + <?php echo json_encode(__('fe_make_logo'), JSON_UNESCAPED_UNICODE); ?>;
            mk.href = '/admin/plugin_page.php?plugin=logo-maker#logo';
            mk.addEventListener('click', function (e) { e.stopPropagation(); });
            wrap.appendChild(mk);
          }
          logo.appendChild(wrap);
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

      // ===== 换Logo 对话框：预览 + 媒体库选图 + 上传 + alt + 最大高度 =====
      var lgdState = {
        url: <?php echo json_encode((string) configRawLang('site_logo', ''), JSON_UNESCAPED_UNICODE); ?>,
        alt: <?php echo json_encode((string) config('site_logo_alt', ''), JSON_UNESCAPED_UNICODE); ?>,
        maxH: <?php echo (int) max(16, min(200, (int) config('site_logo_max_height', 40) ?: 40)); ?>,
        page: 1, pages: 1, mask: null
      };
      var lgdT = {
        title: <?php echo json_encode(__('fe_logo_dialog_title'), JSON_UNESCAPED_UNICODE); ?>,
        upload: <?php echo json_encode(__('fe_logo_upload'), JSON_UNESCAPED_UNICODE); ?>,
        media: <?php echo json_encode(__('fe_logo_media'), JSON_UNESCAPED_UNICODE); ?>,
        more: <?php echo json_encode(__('fe_logo_media_more'), JSON_UNESCAPED_UNICODE); ?>,
        alt: <?php echo json_encode(__('fe_logo_alt_label'), JSON_UNESCAPED_UNICODE); ?>,
        altPh: <?php echo json_encode(__('fe_logo_alt_ph'), JSON_UNESCAPED_UNICODE); ?>,
        maxH: <?php echo json_encode(__('fe_logo_max_height_label'), JSON_UNESCAPED_UNICODE); ?>,
        save: <?php echo json_encode(__('admin_save'), JSON_UNESCAPED_UNICODE); ?>,
        cancel: <?php echo json_encode(__('admin_cancel'), JSON_UNESCAPED_UNICODE); ?>,
        uploading: <?php echo json_encode(__('media_uploading'), JSON_UNESCAPED_UNICODE); ?>,
        upFail: <?php echo json_encode(__('admin_upload_failed'), JSON_UNESCAPED_UNICODE); ?>,
        saveFail: <?php echo json_encode(__('admin_save_failed'), JSON_UNESCAPED_UNICODE); ?>,
        netErr: <?php echo json_encode(__('admin_network_error'), JSON_UNESCAPED_UNICODE); ?>,
        updated: <?php echo json_encode(__('fe_logo_updated'), JSON_UNESCAPED_UNICODE); ?>,
        saved: <?php echo json_encode(__('fe_logo_saved'), JSON_UNESCAPED_UNICODE); ?>
      };

      function lgdEl(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text) n.textContent = text;
        return n;
      }

      function openLogoDialog() {
        if (lgdState.mask) return;
        var mask = lgdEl('div', 'yk-lgd-mask');
        var box = lgdEl('div', 'yk-lgd');
        var head = lgdEl('div', 'yk-lgd-head', lgdT.title);
        var x = lgdEl('button', 'yk-lgd-x', '×');
        head.appendChild(x);
        var body = lgdEl('div', 'yk-lgd-body');

        var prev = lgdEl('div', 'yk-lgd-prev');
        body.appendChild(prev);

        var tools = lgdEl('div', 'yk-lgd-tools');
        var upBtn = lgdEl('button', 'yk-lgd-btn yk-lgd-btn--up', '⬆ ' + lgdT.upload);
        tools.appendChild(upBtn);
        body.appendChild(tools);

        var mediaLabel = lgdEl('span', 'yk-lgd-label', lgdT.media);
        body.appendChild(mediaLabel);
        var grid = lgdEl('div', 'yk-lgd-grid');
        body.appendChild(grid);
        var moreBtn = lgdEl('button', 'yk-lgd-btn yk-lgd-btn--ghost', lgdT.more);
        moreBtn.style.marginTop = '8px';
        body.appendChild(moreBtn);

        var altRow = lgdEl('div', 'yk-lgd-row');
        altRow.style.marginTop = '12px';
        altRow.appendChild(lgdEl('span', 'yk-lgd-label', lgdT.alt));
        var altInput = lgdEl('input', 'yk-lgd-input');
        altInput.type = 'text'; altInput.maxLength = 150; altInput.placeholder = lgdT.altPh; altInput.value = lgdState.alt;
        altRow.appendChild(altInput);
        body.appendChild(altRow);

        var hRow = lgdEl('div', 'yk-lgd-row');
        hRow.appendChild(lgdEl('span', 'yk-lgd-label', lgdT.maxH));
        var hWrap = lgdEl('div', 'yk-lgd-num');
        var hInput = lgdEl('input', 'yk-lgd-input');
        hInput.type = 'number'; hInput.min = 16; hInput.max = 200; hInput.value = lgdState.maxH;
        hWrap.appendChild(hInput);
        hWrap.appendChild(lgdEl('span', '', 'px'));
        hRow.appendChild(hWrap);
        body.appendChild(hRow);

        var foot = lgdEl('div', 'yk-lgd-foot');
        var cancelBtn = lgdEl('button', 'yk-lgd-btn yk-lgd-btn--ghost', lgdT.cancel);
        var saveBtn = lgdEl('button', 'yk-lgd-btn yk-lgd-btn--up', lgdT.save);
        foot.appendChild(cancelBtn); foot.appendChild(saveBtn);

        box.appendChild(head); box.appendChild(body); box.appendChild(foot);
        mask.appendChild(box);
        document.body.appendChild(mask);
        lgdState.mask = mask;

        var pending = { url: lgdState.url };
        function renderPrev() {
          prev.textContent = '';
          if (pending.url) {
            var im = document.createElement('img');
            im.src = pending.url;
            im.style.maxHeight = (parseInt(hInput.value, 10) || 40) + 'px';
            prev.appendChild(im);
          }
        }
        renderPrev();
        hInput.addEventListener('input', renderPrev);

        function close() { mask.remove(); lgdState.mask = null; lgdState.page = 1; }
        x.addEventListener('click', close);
        cancelBtn.addEventListener('click', close);
        mask.addEventListener('click', function (e) { if (e.target === mask) close(); });

        function addItems(items) {
          items.forEach(function (it) {
            var url = it.url || it.path || '';
            if (!url) return;
            var cell = lgdEl('div', 'yk-lgd-item');
            var im = document.createElement('img');
            im.src = url; im.loading = 'lazy';
            cell.appendChild(im);
            if (url === pending.url) cell.classList.add('sel');
            cell.addEventListener('click', function () {
              grid.querySelectorAll('.sel').forEach(function (n) { n.classList.remove('sel'); });
              cell.classList.add('sel');
              pending.url = url;
              renderPrev();
            });
            grid.appendChild(cell);
          });
        }
        function loadMedia(page) {
          fetch('/admin/media_api.php?action=list&type=image&page=' + page)
            .then(function (r) { return r.json(); })
            .then(function (d) {
              if (d.code !== 0 || !d.data) return;
              addItems(d.data.items || []);
              lgdState.page = d.data.page; lgdState.pages = d.data.pages;
              moreBtn.style.display = lgdState.page < lgdState.pages ? '' : 'none';
            })
            .catch(function () { moreBtn.style.display = 'none'; });
        }
        moreBtn.addEventListener('click', function () { loadMedia(lgdState.page + 1); });
        loadMedia(1);

        upBtn.addEventListener('click', function () {
          fileInput.onchange = function () {
            if (!fileInput.files[0]) return;
            var fd = new FormData();
            fd.append('file', fileInput.files[0]); fd.append('type', 'images'); fd.append('_token', csrf);
            fileInput.value = '';
            toast(lgdT.uploading, true);
            fetch('/admin/upload.php', { method: 'POST', body: fd })
              .then(function (r) { return r.json(); })
              .then(function (d) {
                if (d.code !== 0) { toast(d.msg || lgdT.upFail, false); return; }
                pending.url = d.data.url;
                renderPrev();
                var cell = lgdEl('div', 'yk-lgd-item sel');
                var im = document.createElement('img');
                im.src = d.data.url; cell.appendChild(im);
                grid.querySelectorAll('.sel').forEach(function (n) { n.classList.remove('sel'); });
                cell.classList.add('sel');
                cell.addEventListener('click', function () { pending.url = d.data.url; renderPrev(); });
                grid.prepend(cell);
              })
              .catch(function (err) { toast(lgdT.netErr + '：' + err.message, false); });
          };
          fileInput.click();
        });

        function saveKey(key, value) {
          var sd = new FormData();
          sd.append('key', key); sd.append('value', value); sd.append('_token', csrf);
          return fetch('/admin/front_edit_api.php', { method: 'POST', body: sd })
            .then(function (r) { return r.json(); })
            .then(function (s) { if (s.code !== 0) throw new Error(s.msg || lgdT.saveFail); return s; });
        }

        saveBtn.addEventListener('click', function () {
          var newAlt = altInput.value.trim();
          var newH = Math.max(16, Math.min(200, parseInt(hInput.value, 10) || 40));
          var tasks = [];
          if (pending.url && pending.url !== lgdState.url) tasks.push(['site_logo', pending.url]);
          if (newAlt !== lgdState.alt) tasks.push(['site_logo_alt', newAlt]);
          if (newH !== lgdState.maxH) tasks.push(['site_logo_max_height', String(newH)]);
          if (!tasks.length) { close(); return; }
          saveBtn.disabled = true;
          tasks.reduce(function (chain, t) {
            return chain.then(function () { return saveKey(t[0], t[1]); });
          }, Promise.resolve())
            .then(function () {
              var hadImg = document.querySelectorAll('[data-yk-logo] img').length > 0;
              lgdState.url = pending.url || lgdState.url;
              lgdState.alt = newAlt; lgdState.maxH = newH;
              var imgs = document.querySelectorAll('[data-yk-logo] img');
              if (imgs.length) {
                imgs.forEach(function (im) {
                  if (lgdState.url) im.src = lgdState.url;
                  im.alt = newAlt;
                  im.style.maxHeight = newH + 'px';
                });
              }
              close();
              if (!hadImg && lgdState.url) { toast(lgdT.saved, true); setTimeout(function () { location.reload(); }, 700); }
              else { toast(lgdT.updated, true); }
            })
            .catch(function (err) { saveBtn.disabled = false; toast(err.message || lgdT.saveFail, false); });
        });
      }
    })();
    </script>
    <?php
}

add_action('ik_footer_before', 'renderFrontEdit');
