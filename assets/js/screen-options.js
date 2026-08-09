/**
 * 列表「显示选项」（仿 WordPress Screen Options，v1：列显隐）
 *   自动识别当前页主列表表格，提供「显示选项」面板勾选要显示的列，
 *   按页面路径记忆到 localStorage。无需改动各列表页。
 *   仅作用于含 thead 且 ≥3 列的表格；编辑页/无表格页自动跳过。
 */
// 文案来自后台 footer 渲染的 window.YK_SO_I18N（本文件是独立 JS，用不了 PHP 的 __()）。
// 取不到就回落中文原文——字典没渲染出来时按钮至少还有字，不会变成空白。
function T(key, fallback) {
  var d = window.YK_SO_I18N || {};
  return (typeof d[key] === 'string' && d[key] !== '') ? d[key] : fallback;
}

(function () {
  "use strict";

  // 找主列表表格：第一个 thead 里有 ≥3 个 th 的 table
  var table = null;
  var tables = document.querySelectorAll("table");
  for (var i = 0; i < tables.length; i++) {
    if (tables[i].querySelectorAll("thead th").length >= 3) { table = tables[i]; break; }
  }
  if (!table) return;

  table.classList.add("ik-so-target");
  var ths = table.querySelectorAll("thead th");
  var key = "ik_so:" + location.pathname;
  var hidden = {};
  try { hidden = JSON.parse(localStorage.getItem(key) || "{}") || {}; } catch (e) {}

  // 收集列（1-based，匹配 nth-child）；跳过空表头与含控件（如全选框）的列
  var cols = [];
  ths.forEach(function (th, idx) {
    var label = (th.textContent || "").trim();
    var hasControl = !!th.querySelector("input,button,select");
    cols.push({ idx: idx + 1, label: label, skip: label === "" || hasControl });
  });

  // 注入隐藏样式
  var styleEl = document.createElement("style");
  document.head.appendChild(styleEl);
  function applyStyle() {
    var rules = [];
    Object.keys(hidden).forEach(function (i) {
      if (hidden[i]) rules.push("table.ik-so-target > * > tr > *:nth-child(" + i + "){display:none}");
    });
    styleEl.textContent = rules.join("\n");
  }
  applyStyle();

  // 按钮 + 面板（插到表格上方，右对齐）
  var wrap = document.createElement("div");
  wrap.style.cssText = "position:relative;display:block;text-align:right;margin:0 0 10px;";
  wrap.innerHTML =
    '<button type="button" id="ikSoBtn" class="text-sm border rounded px-3 py-1.5 bg-white hover:bg-gray-50 text-gray-600">⚙ ' + T('screen_options', '显示选项') + ' ▾</button>' +
    '<div id="ikSoPanel" style="display:none;position:absolute;right:0;top:36px;z-index:50;background:#fff;border:1px solid #e5e7eb;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);padding:10px 14px;min-width:180px;text-align:left;"></div>';
  table.parentNode.insertBefore(wrap, table);

  var btn = wrap.querySelector("#ikSoBtn");
  var panel = wrap.querySelector("#ikSoPanel");
  var html = '<div style="font-size:12px;color:#9ca3af;margin-bottom:6px">' + T('columns', '显示的列') + '</div>';
  cols.forEach(function (c) {
    if (c.skip) return;
    html +=
      '<label style="display:flex;align-items:center;gap:6px;font-size:13px;padding:3px 0;cursor:pointer">' +
      '<input type="checkbox" data-col="' + c.idx + '" ' + (hidden[c.idx] ? "" : "checked") + ">" +
      c.label.replace(/</g, "&lt;") + "</label>";
  });
  panel.innerHTML = html;

  btn.onclick = function () { panel.style.display = panel.style.display === "none" ? "block" : "none"; };
  document.addEventListener("click", function (e) { if (!wrap.contains(e.target)) panel.style.display = "none"; });
  panel.addEventListener("change", function (e) {
    if (e.target.type !== "checkbox") return;
    var idx = e.target.getAttribute("data-col");
    hidden[idx] = !e.target.checked;
    try { localStorage.setItem(key, JSON.stringify(hidden)); } catch (err) {}
    applyStyle();
  });
})();
