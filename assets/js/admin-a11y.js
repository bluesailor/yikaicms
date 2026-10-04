/**
 * 后台无障碍兜底（2.0.4）：读屏与语音控制靠「可访问名称」找控件，后台很多表单只是把文字和输入框
 * 并排放着，没有用 for/id 关联。这里在不改每个模板的前提下补上能确定的关联；猜不准的不补，留给模板修。
 *
 * - 没有 for、里面也没有控件的 <label>：同一父元素里只有一个可填控件时，关联到它；
 * - 列表行复选框（name="ids[]"）：名称取「选择 + 该行第一个链接/文字」；表头全选框叫「全选」；
 * - 没有 alt 的 <img>：后台的图都是缩略图 / 预览，补 alt=""（装饰图，读屏跳过）；
 * - 只有 title 的图标按钮：把 title 补成 aria-label。
 *
 * 动态插入的内容可调用 window.ykA11y(root) 再补一次。
 */
(function () {
  'use strict';
  var i18n = window.YK_A11Y_I18N || {};
  var seq = 0;
  var CONTROL = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="reset"]):not([type="image"]), select, textarea';

  function named(el) {
    if (el.hasAttribute('aria-label') || el.hasAttribute('aria-labelledby')) return true;
    if (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]')) return true;
    return !!el.closest('label');
  }

  function labels(root) {
    root.querySelectorAll('label:not([for])').forEach(function (label) {
      if (label.querySelector(CONTROL) || !label.textContent.trim()) return;
      var parent = label.parentElement;
      if (!parent) return;
      // 包在别的 <label> 里的控件已经有名称，不算
      var controls = Array.prototype.filter.call(parent.querySelectorAll(CONTROL), function (c) {
        return !c.closest('label');
      });
      if (controls.length !== 1) return;
      var control = controls[0];
      // 控件必须在标签之后（标签在前、控件在后的常见排版），且还没有名称
      if (!(label.compareDocumentPosition(control) & Node.DOCUMENT_POSITION_FOLLOWING) || named(control)) return;
      if (!control.id) control.id = 'yk-a11y-f' + (++seq);
      label.htmlFor = control.id;
    });
  }

  function rowChecks(root) {
    root.querySelectorAll('input[type="checkbox"]').forEach(function (box) {
      if (named(box)) return;
      if (box.id === 'checkAll' || box.closest('thead')) {
        if (i18n.selectAll) box.setAttribute('aria-label', i18n.selectAll);
        return;
      }
      if (box.name !== 'ids[]' && !box.classList.contains('row-check')) return;
      var row = box.closest('tr, li, [data-id]');
      var text = '';
      if (row) {
        var link = row.querySelector('a[href]:not([href="#"])');
        text = ((link && link.textContent) || row.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 60);
      }
      if (i18n.selectRow) box.setAttribute('aria-label', i18n.selectRow.replace(':name', text || box.value));
    });
  }

  function images(root) {
    root.querySelectorAll('img:not([alt])').forEach(function (img) { img.setAttribute('alt', ''); });
  }

  function iconButtons(root) {
    root.querySelectorAll('button[title], a[title]').forEach(function (el) {
      if (!el.textContent.trim() && !el.hasAttribute('aria-label') && !el.hasAttribute('aria-labelledby')) {
        el.setAttribute('aria-label', el.getAttribute('title'));
      }
    });
  }

  function run(root) {
    root = root || document;
    labels(root);
    rowChecks(root);
    images(root);
    iconButtons(root);
  }

  window.ykA11y = run;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { run(document); });
  } else {
    run(document);
  }
})();
