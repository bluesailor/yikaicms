<?php
/**
 * 前台就地编辑覆盖层
 *
 * Blox 页面保留顶部管理条的「编辑此页」作为主入口；桌面端区块悬停入口通过
 * focus_section=<持久 section id> / focus_element=<持久 element id> 精确定位。
 * 首页和触摸设备不叠加区块操作层。
 */

if (!defined('ROOT_PATH')) exit;

function renderFrontEdit(): void
{
    if (isCleanFrontendPreview()) return;
    if (empty($_SESSION['admin_id'])) return;
    $csrf = function_exists('csrfToken') ? csrfToken() : '';
    $frontendEditResult = BloxAreaEditorTarget::consumeReturnReceipt($_GET['yk_edit_receipt'] ?? '');
    $frontendReturnTo = BloxAreaEditorTarget::frontendSourceReturnTo((string) ($_SERVER['REQUEST_URI'] ?? ''));
    $bloxEditUrl = BloxAreaEditorTarget::withReturnTo(
        adminBarResolveEditUrl((string) ($GLOBALS['ik_edit_url'] ?? '')),
        $frontendReturnTo
    );
    if (!str_starts_with($bloxEditUrl, '/admin/blox_editor.php?')) {
        $bloxEditUrl = '';
    }
    // 覆盖层对任何登录管理员都渲染（专用编辑入口与白名单 Blox 元素定位全站可用）。
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
      .yk-return-focus { scroll-margin-top: 50px; outline: 3px solid #2563eb; outline-offset: 4px;
        animation: yk-return-focus 2.4s cubic-bezier(.16,1,.3,1) both; }
      #yk-return-focus-status { position: fixed; z-index: 99998; top: 46px; left: 50%;
        transform: translateX(-50%); box-sizing: border-box; max-width: calc(100vw - 24px);
        padding: 8px 12px; border: 1px solid #93c5fd; border-radius: 6px;
        background: #eff6ff; color: #1e3a8a; font: 600 13px/1.4 system-ui,-apple-system,"Microsoft YaHei",sans-serif;
        text-align: center; pointer-events: none; }
      #yk-return-focus-status.is-draft { border-color: #f59e0b; background: #fffbeb; color: #92400e; }
      #yk-return-focus-status.is-published { border-color: #34d399; background: #ecfdf5; color: #065f46; }
      @keyframes yk-return-focus {
        0% { outline-color: rgba(37,99,235,.18); }
        20%, 72% { outline-color: #2563eb; }
        100% { outline-color: rgba(37,99,235,.18); }
      }
      .yk-logo-btn { background: #2563eb; color: #fff; font-size: 11px; line-height: 1; font-weight: 600;
        padding: 4px 8px; border-radius: 999px; white-space: nowrap; cursor: pointer;
        box-shadow: 0 2px 8px rgba(0,0,0,.25); font-family: system-ui,-apple-system,"Microsoft YaHei",sans-serif;
        text-decoration: none; }
      .yk-logo-btn:hover { background: #1d4ed8; color: #fff; }
      .yk-logo-btn--make { background: #7c3aed; }
      .yk-logo-btn--make:hover { background: #6d28d9; }
      /* Touch/narrow screens prioritize the site's own controls; the admin bar still keeps page editing reachable. */
      @media (max-width: 1023px), (hover: none) {
        #yk-edit-outline, .yk-logo-btns { display: none !important; }
      }
      @media (prefers-reduced-motion: reduce) {
        .yk-return-focus { animation: none; }
      }
      @media print {
        #yk-edit-outline, .yk-logo-btns, #yk-return-focus-status { display: none !important; }
        .yk-return-focus { outline: 0 !important; animation: none !important; }
      }
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
      var current = null, hideTimer = null;
      var bloxEditUrl = <?php echo json_encode($bloxEditUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      var frontendReturnTo = <?php echo json_encode($frontendReturnTo, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      var frontendEditResult = <?php echo json_encode($frontendEditResult, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      var returnFocusText = <?php echo json_encode(__('fe_return_focus', ['label' => ':label']), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      var returnFocusFallback = <?php echo json_encode(__('fe_return_focus_fallback'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      var returnDraftText = <?php echo json_encode(__('fe_return_result_draft'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      var returnPublishedText = <?php echo json_encode(__('fe_return_result_published'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      var returnPublishedPageText = <?php echo json_encode(__('fe_return_result_published_page'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

      var box = document.createElement('div');
      box.id = 'yk-edit-outline';
      var btn = document.createElement('a');
      btn.id = 'yk-edit-btn';
      btn.innerHTML = '✎ ' + <?php echo json_encode(__('fe_edit_content'), JSON_UNESCAPED_UNICODE); ?>;
      box.appendChild(btn);

      document.body.appendChild(box);

      function cancelHide() {
        if (hideTimer === null) return;
        clearTimeout(hideTimer);
        hideTimer = null;
      }

      function hide() {
        cancelHide();
        box.style.display = 'none';
        current = null;
      }

      function scheduleHide() {
        cancelHide();
        hideTimer = setTimeout(function () {
          hideTimer = null;
          hide();
        }, 200);
      }

      function safeFocusId(value) {
        var id = typeof value === 'string' ? value.trim() : '';
        return id && id.length <= 512 && !/[\x00-\x1F\x7F]/.test(id) ? id : '';
      }

      function withFrontendReturn(url) {
        if (!url || !frontendReturnTo) return url;
        try {
          var target = new URL(url, window.location.origin);
          if (target.origin !== window.location.origin || target.pathname !== '/admin/blox_editor.php') return url;
          var source = new URL(frontendReturnTo, window.location.origin);
          source.searchParams.delete('yk_focus_section');
          source.searchParams.delete('yk_focus_element');
          source.searchParams.delete('yk_edit_receipt');
          var elementId = safeFocusId(target.searchParams.get('focus_element'));
          var sectionId = safeFocusId(target.searchParams.get('focus_section'));
          if (elementId) source.searchParams.set('yk_focus_element', elementId);
          else if (sectionId) source.searchParams.set('yk_focus_section', sectionId);
          target.searchParams.set('return_to', source.pathname + source.search + source.hash);
          return target.pathname + target.search + target.hash;
        } catch (error) {
          return url;
        }
      }

      function findReturnFocusTarget(attribute, id) {
        var targets = document.querySelectorAll('[' + attribute + ']');
        for (var i = 0; i < targets.length; i++) {
          if (targets[i].getAttribute(attribute) !== id) continue;
          if (targets[i].getClientRects().length) return targets[i];
        }
        return null;
      }

      function consumeReturnFocus() {
        if (!window.URL || !window.history || !window.history.replaceState) return;
        var currentUrl = new URL(window.location.href);
        var hasElement = currentUrl.searchParams.has('yk_focus_element');
        var hasSection = currentUrl.searchParams.has('yk_focus_section');
        var hasReceipt = currentUrl.searchParams.has('yk_edit_receipt');
        if (!hasElement && !hasSection && !hasReceipt) return;

        var elementId = safeFocusId(currentUrl.searchParams.get('yk_focus_element'));
        var sectionId = elementId ? '' : safeFocusId(currentUrl.searchParams.get('yk_focus_section'));
        currentUrl.searchParams.delete('yk_focus_element');
        currentUrl.searchParams.delete('yk_focus_section');
        currentUrl.searchParams.delete('yk_edit_receipt');
        window.history.replaceState(
          window.history.state,
          '',
          currentUrl.pathname + currentUrl.search + currentUrl.hash
        );

        var attribute = elementId ? 'data-yk-element-id' : 'data-yk-sec-id';
        var id = elementId || sectionId;
        var target = id ? findReturnFocusTarget(attribute, id) : null;
        if (!target && !frontendEditResult) return;

        var labelAttribute = elementId ? 'data-yk-element-label' : 'data-yk-sec-label';
        var label = target ? (target.getAttribute(labelAttribute) || '').trim() : '';
        var status = document.createElement('div');
        status.id = 'yk-return-focus-status';
        status.setAttribute('data-testid', 'frontend-return-focus-status');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        status.setAttribute('aria-atomic', 'true');
        if (frontendEditResult === 'draft') {
          status.className = 'is-draft';
          status.textContent = returnDraftText;
        } else if (frontendEditResult === 'published') {
          status.className = 'is-published';
          status.textContent = target ? returnPublishedText : returnPublishedPageText;
        } else {
          status.textContent = label ? returnFocusText.replace(':label', label) : returnFocusFallback;
        }
        document.body.appendChild(status);

        if (target) {
          var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
          window.requestAnimationFrame(function () {
            target.classList.add('yk-return-focus');
            target.scrollIntoView({
              behavior: reduceMotion ? 'auto' : 'smooth',
              block: elementId ? 'center' : 'start',
              inline: 'nearest'
            });
          });
        }
        window.setTimeout(function () {
          if (target) target.classList.remove('yk-return-focus');
          status.remove();
        }, 4200);
      }

      // 按元素上的标记算编辑链接：Blox 区块 / 导航 / 页脚 / 合作伙伴 / 通用内容。
      function editUrl(el) {
        if (el.hasAttribute('data-yk-element-edit') && el.hasAttribute('data-yk-element-id')) {
          var areaHost = el.closest('[data-yk-edit]');
          var areaUrl = areaHost ? (areaHost.getAttribute('data-yk-edit') || '') : '';
          var elementId = el.getAttribute('data-yk-element-id') || '';
          if (areaUrl && elementId) {
            var elementTarget = new URL(areaUrl, location.origin);
            if (elementTarget.pathname === '/admin/blox_editor.php') {
              elementTarget.searchParams.set('focus_element', elementId);
              elementTarget.searchParams.delete('focus_section');
              elementTarget.searchParams.delete('open');
              return withFrontendReturn(elementTarget.pathname + elementTarget.search + elementTarget.hash);
            }
          }
          return null;
        }
        if (bloxEditUrl && el.hasAttribute('data-yk-sec-id')) {
          var sectionId = el.getAttribute('data-yk-sec-id') || '';
          if (!sectionId) return '#';
          var target = new URL(bloxEditUrl, window.location.origin);
          target.searchParams.set('focus_section', sectionId);
          return withFrontendReturn(target.pathname + target.search + target.hash);
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
          return withFrontendReturn(el.getAttribute('data-yk-edit'));  // 通用：URL 直接写在属性上（内容/产品/单页详情）
        }
        return '#';
      }
      function editLabel(el) {
        if (el.hasAttribute('data-yk-element-edit')) {
          return '✎ ' + (el.getAttribute('data-yk-element-label') || <?php echo json_encode(__('fe_edit_content'), JSON_UNESCAPED_UNICODE); ?>);
        }
        if (el.hasAttribute('data-yk-sec-id')) return '✎ ' + <?php echo json_encode(__('fe_edit_block'), JSON_UNESCAPED_UNICODE); ?>;
        if (el.hasAttribute('data-yk-nav'))      return '✎ ' + <?php echo json_encode(__('fe_edit_nav'), JSON_UNESCAPED_UNICODE); ?>;
        if (el.hasAttribute('data-yk-footer'))   return '✎ ' + <?php echo json_encode(__('fe_edit_footer'), JSON_UNESCAPED_UNICODE); ?>;
        if (el.hasAttribute('data-yk-partners')) return '✎ ' + <?php echo json_encode(__('fe_edit_partners'), JSON_UNESCAPED_UNICODE); ?>;
        if (el.hasAttribute('data-yk-edit'))     return el.getAttribute('data-yk-edit-label') || ('✎ ' + <?php echo json_encode(__('fe_edit_content'), JSON_UNESCAPED_UNICODE); ?>);
        return '✎ ' + <?php echo json_encode(__('fe_edit_content'), JSON_UNESCAPED_UNICODE); ?>;
      }

      var regionLabels = {
        page: <?php echo json_encode(__('ab_edit_group_page'), JSON_UNESCAPED_UNICODE); ?>,
        header: <?php echo json_encode(__('ab_edit_group_header'), JSON_UNESCAPED_UNICODE); ?>,
        body: <?php echo json_encode(__('ab_edit_group_body'), JSON_UNESCAPED_UNICODE); ?>,
        footer: <?php echo json_encode(__('ab_edit_group_footer'), JSON_UNESCAPED_UNICODE); ?>,
        editPage: <?php echo json_encode(__('ab_edit_page'), JSON_UNESCAPED_UNICODE); ?>,
        editHeader: <?php echo json_encode(__('ab_edit_header'), JSON_UNESCAPED_UNICODE); ?>,
        editFooter: <?php echo json_encode(__('fe_edit_footer'), JSON_UNESCAPED_UNICODE); ?>,
        section: <?php echo json_encode(__('ab_edit_section', ['n' => ':n']), JSON_UNESCAPED_UNICODE); ?>,
        editContent: <?php echo json_encode(__('fe_edit_content'), JSON_UNESCAPED_UNICODE); ?>
      };

      function buildRegionNavigator() {
        var regions = document.getElementById('ik-ab-regions');
        if (!regions) return;
        var menu = regions.querySelector('.ik-ab-region-menu');
        var summary = regions.querySelector('summary');
        if (!menu || !summary) return;

        var groups = { page: [], header: [], body: [], footer: [] };
        var pageUrl = regions.getAttribute('data-page-edit-url') || '';
        if (pageUrl) groups.page.push({ url: pageUrl, label: regionLabels.editPage });

        function add(group, url, label) {
          if (!url || url === '#') return;
          var target;
          try {
            target = new URL(url, window.location.origin);
          } catch (error) {
            return;
          }
          if (target.origin !== window.location.origin || !target.pathname.startsWith('/admin/')) return;
          var normalizedUrl = target.pathname + target.search + target.hash;
          if (groups[group].some(function (item) { return item.url === normalizedUrl; })) return;
          groups[group].push({ url: normalizedUrl, label: label || regionLabels.editContent });
        }
        function addArea(area, group, areaLabel) {
          if (!area) return;
          add(group, withFrontendReturn(area.getAttribute('data-yk-edit') || ''), areaLabel);
          area.querySelectorAll('[data-yk-element-edit][data-yk-element-id]').forEach(function (target) {
            add(group, editUrl(target), target.getAttribute('data-yk-element-label') || regionLabels.editContent);
          });
        }

        var header = document.querySelector('.yk-blox-header[data-yk-edit]');
        var footer = document.querySelector('.yk-blox-footer[data-yk-edit]');
        addArea(header, 'header', regionLabels.editHeader);
        document.querySelectorAll('[data-yk-sec-id]').forEach(function (section, index) {
          if (section.closest('.yk-blox-header,.yk-blox-footer')) return;
          var fallbackLabel = regionLabels.section.replace(':n', String(index + 1));
          add('body', editUrl(section), section.getAttribute('data-yk-sec-label') || fallbackLabel);
        });
        document.querySelectorAll('[data-yk-nav],[data-yk-footer],[data-yk-partners],[data-yk-edit]').forEach(function (target) {
          if (target === header || target === footer || target.closest('.yk-blox-header,.yk-blox-footer')) return;
          if (target.hasAttribute('data-yk-sec-id')) return;
          add('body', editUrl(target), (editLabel(target) || '').replace(/^✎\s*/, ''));
        });
        addArea(footer, 'footer', regionLabels.editFooter);

        var groupOrder = ['page', 'header', 'body', 'footer'];
        var regionCount = groups.header.length + groups.body.length + groups.footer.length;
        if (!regionCount) return;

        menu.textContent = '';
        groupOrder.forEach(function (group) {
          if (!groups[group].length) return;
          var section = document.createElement('section');
          section.className = 'ik-ab-region-group';
          var heading = document.createElement('span');
          heading.className = 'ik-ab-region-heading';
          heading.id = 'ik-ab-region-heading-' + group;
          heading.textContent = regionLabels[group];
          section.setAttribute('aria-labelledby', heading.id);
          section.appendChild(heading);
          groups[group].forEach(function (item) {
            var link = document.createElement('a');
            link.href = item.url;
            link.textContent = item.label;
            link.title = item.label;
            section.appendChild(link);
          });
          menu.appendChild(section);
        });
        regions.hidden = false;

        document.addEventListener('click', function (event) {
          if (regions.open && !regions.contains(event.target)) regions.open = false;
        });
        regions.addEventListener('keydown', function (event) {
          if (event.key !== 'Escape' || !regions.open) return;
          event.preventDefault();
          regions.open = false;
          summary.focus();
        });
      }

      function place(sec) {
        var r = sec.getBoundingClientRect();
        var targetUrl = editUrl(sec);
        if (!targetUrl || r.width <= 0 || r.height <= 0) { hide(); return; }
        box.style.display = 'block';
        box.style.top    = (r.top + window.scrollY) + 'px';
        box.style.left   = (r.left + window.scrollX) + 'px';
        box.style.width  = r.width + 'px';
        box.style.height = r.height + 'px';
        btn.href = targetUrl;
        btn.textContent = editLabel(sec);

      }

      function attach(sec) {
        sec.addEventListener('mouseenter', function () { cancelHide(); current = sec; place(sec); });
        sec.addEventListener('mouseleave', scheduleHide);
      }
      // 本脚本在 ik_footer_before 处执行，页脚等位于其后的元素此刻尚未入 DOM，
      // 故延到 DOMContentLoaded 再扫描绑定（Logo/导航/首页区块在前，也一并延后无碍）。
      function onReady(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
      }
      onReady(function () {
        document.querySelectorAll('[data-yk-element-edit][data-yk-element-id],[data-yk-sec-id],[data-yk-nav],[data-yk-footer],[data-yk-partners],[data-yk-edit]').forEach(attach);
        buildRegionNavigator();
        consumeReturnFocus();
      });

      btn.addEventListener('mouseenter', cancelHide);
      btn.addEventListener('mouseleave', scheduleHide);

      window.addEventListener('scroll', function () { if (current) place(current); }, { passive: true });
      window.addEventListener('resize', function () { if (current) place(current); });

      // ===== Logo 就地编辑：悬停显示「换Logo」，选图后上传 + 保存 + 实时替换 =====
      var csrf = <?php echo json_encode($csrf); ?>;
      var fileInput = document.createElement('input');
      fileInput.type = 'file'; fileInput.accept = 'image/*'; fileInput.style.display = 'none';
      document.body.appendChild(fileInput);

      var hasLogoMaker = <?php echo json_encode(function_exists('isPluginAvailable') && isPluginAvailable('logo-maker')); ?>;
      // 未安装 LOGO 制作插件时（v1.18.6 起不随核心包发布），把入口换成「去装」——
      // 想换 LOGO 的当口正是推荐时机。本覆盖层只对登录管理员渲染，故可放后台链接。
      // 注：这里不查 hasPermission —— 它定义在 admin/includes/auth.php，前台不加载，
      // 判断恒假会让入口永远不出现。覆盖层本身已按 $_SESSION['admin_id'] 把关，
      // 插件页自己还有 requirePermission('*')，非超管点进去照样被拦。
      var logoMakerGetUrl = <?php echo json_encode(
          is_dir(ROOT_PATH . '/plugins/logo-maker') ? '' : '/admin/plugin.php?tab=market&q=logo-maker'
      ); ?>;
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
          } else if (logoMakerGetUrl) {
            var gm = document.createElement('a');
            gm.className = 'yk-logo-btn yk-logo-btn--make';
            gm.textContent = '★ ' + <?php echo json_encode(__('fe_get_logo_maker'), JSON_UNESCAPED_UNICODE); ?>;
            gm.href = logoMakerGetUrl;
            gm.addEventListener('click', function (e) { e.stopPropagation(); });
            wrap.appendChild(gm);
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
