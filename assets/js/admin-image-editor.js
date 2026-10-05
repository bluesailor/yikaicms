/**
 * 媒体库图片编辑弹窗：裁剪（自由 / 固定比例）、旋转、翻转、撤销重做、替代文字。
 *
 * 前端只负责出选区和记操作；预览图由服务端按操作历史从原图重放（admin/media_edit.php?action=preview），
 * 与保存走同一段代码，所见即所得。选区坐标按「预览显示像素 → 当前这一步图像的实际像素」换算后才进历史。
 * 弹窗标记写在 admin/media.php 里（类名要进 Tailwind 产物），这里只找 data-ie-* 节点。
 */
(function (window, document) {
    "use strict";

    var I18N = window.YK_IMAGE_EDIT_I18N || {};
    var ENDPOINT = (window.YK_BASE || "") + "/admin/media_edit.php";
    var RATIOS = { "free": null, "1:1": 1, "4:3": 4 / 3, "3:2": 3 / 2, "16:9": 16 / 9, "3:1": 3 };
    var MIN_BOX = 12;

    var modal, stage, img, box, els = {};
    var state = null;   // { id, width, height, history, undone, ratio, edited, opener, options }
    var sel = null;     // 选区（预览显示像素）{ x, y, w, h }

    function q(name) { return modal.querySelector("[data-ie-" + name + "]"); }

    function setup() {
        modal = document.getElementById("imageEditModal");
        if (!modal || modal.dataset.ieReady) return !!modal;
        modal.dataset.ieReady = "1";
        stage = q("stage"); img = q("preview"); box = q("box");
        ["size", "loading", "animated", "apply-crop", "undo", "redo", "alt", "status", "save", "restore"].forEach(function (k) { els[k] = q(k); });

        q("close").addEventListener("click", close);
        modal.addEventListener("click", function (e) { if (e.target === modal) close(); });
        modal.addEventListener("keydown", onKeydown);
        modal.querySelectorAll("[data-ie-ratio]").forEach(function (b) {
            b.addEventListener("click", function () { setRatio(b.getAttribute("data-ie-ratio")); });
        });
        modal.querySelectorAll("[data-ie-op]").forEach(function (b) {
            b.addEventListener("click", function () {
                var parts = b.getAttribute("data-ie-op").split(":");
                push(parts[0] === "rotate" ? { op: "rotate", deg: Number(parts[1]) } : { op: "flip", axis: parts[1] },
                    parts[0] === "rotate" ? I18N.rotated : I18N.flipped);
            });
        });
        els["apply-crop"].addEventListener("click", applyCrop);
        els.undo.addEventListener("click", function () { if (state.undone < state.history.length) { state.undone++; refresh(); } });
        els.redo.addEventListener("click", function () { if (state.undone > 0) { state.undone--; refresh(); } });
        els.save.addEventListener("click", save);
        els.restore.addEventListener("click", restore);
        img.addEventListener("load", function () { els.loading.hidden = true; clearSelection(); });
        img.addEventListener("error", function () { els.loading.hidden = true; announce(I18N.failed, true); });
        stage.addEventListener("pointerdown", onPointerDown);
        box.addEventListener("keydown", onBoxKey);
        return true;
    }

    // ── 状态 ──────────────────────────────────────────────────────────────

    function activeHistory() { return state.history.slice(0, state.history.length - state.undone); }

    /** 与服务端 ImageEditPlan::finalSize 同口径：裁剪定尺寸，90° / 270° 宽高互换。 */
    function currentSize() {
        var w = state.width, h = state.height;
        activeHistory().forEach(function (op) {
            if (op.op === "crop") { w = op.w; h = op.h; }
            else if (op.op === "rotate" && ((op.deg % 180) + 180) % 180 !== 0) { var t = w; w = h; h = t; }
        });
        return { w: w, h: h };
    }

    function push(op, message) {
        state.history = activeHistory();
        state.undone = 0;
        state.history.push(op);
        refresh();
        if (message) announce(message);
    }

    function refresh() {
        var size = currentSize();
        els.size.textContent = (I18N.size || ":w × :h").replace(":w", size.w).replace(":h", size.h);
        els.undo.disabled = state.undone >= state.history.length;
        els.redo.disabled = state.undone === 0;
        els.loading.hidden = false;
        img.src = ENDPOINT + "?action=preview&id=" + state.id + "&history=" + encodeURIComponent(JSON.stringify(activeHistory())) + "&t=" + Date.now();
    }

    function dirty() { return state && (activeHistory().length > 0 || els.alt.value !== state.alt); }

    // ── 打开 / 关闭 ──────────────────────────────────────────────────────

    /**
     * ref：媒体 id，或媒体库里图片的网址（网页构建器的图片控件只存网址）。
     * options.onDone(data)：保存 / 恢复成功后调用，不再刷新整页；options.notify(message, isError)：提示方式。
     */
    function open(ref, options) {
        if (!setup()) return;
        options = options || {};
        var query = typeof ref === "number" || /^\d+$/.test(String(ref)) ? "id=" + encodeURIComponent(ref) : "url=" + encodeURIComponent(ref);
        fetch(ENDPOINT + "?action=info&" + query, { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || data.code !== 0) { notify(options, data && data.msg ? data.msg : I18N.failed, true); return; }
                var info = data.data;
                state = { id: info.id, width: info.width, height: info.height, history: [], undone: 0, ratio: "free",
                    edited: !!info.edited, alt: info.alt || "", opener: document.activeElement, options: options };
                els.alt.value = state.alt;
                els.restore.hidden = !state.edited;
                els.animated.hidden = !info.animated;
                setRatio("free");
                modal.classList.remove("hidden");
                refresh();
                q("close").focus();
            })
            .catch(function () { notify(options, I18N.failed, true); });
    }

    function notify(options, message, isError) {
        if (options && typeof options.notify === "function") options.notify(message, !!isError);
        else (window.showMessage || window.alert)(message, isError ? "error" : "success");
    }

    function close() {
        if (dirty() && !window.confirm(I18N.discard || "Discard changes?")) return;
        modal.classList.add("hidden");
        img.removeAttribute("src");
        var opener = state && state.opener;
        state = null;
        if (opener && opener.focus) opener.focus();
    }

    function onKeydown(e) {
        if (e.key === "Escape") { e.preventDefault(); close(); return; }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "z" && e.target !== els.alt) { e.preventDefault(); els[e.shiftKey ? "redo" : "undo"].click(); }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === "y" && e.target !== els.alt) { e.preventDefault(); els.redo.click(); }
        if (e.key === "Enter" && e.target === box) { e.preventDefault(); applyCrop(); }
    }

    // ── 选区 ─────────────────────────────────────────────────────────────

    function bounds() { return { w: img.clientWidth, h: img.clientHeight }; }

    function setRatio(ratio) {
        state.ratio = Object.prototype.hasOwnProperty.call(RATIOS, ratio) ? ratio : "free";
        modal.querySelectorAll("[data-ie-ratio]").forEach(function (b) {
            b.setAttribute("aria-pressed", b.getAttribute("data-ie-ratio") === state.ratio ? "true" : "false");
        });
        var r = RATIOS[state.ratio];
        if (r && img.complete && img.clientWidth) {
            // 选比例就给一个居中的最大选区，用户再拖动调整
            var b = bounds(), w = b.w, h = w / r;
            if (h > b.h) { h = b.h; w = h * r; }
            setSelection({ x: (b.w - w) / 2, y: (b.h - h) / 2, w: w, h: h });
        }
    }

    function clamp(s) {
        var b = bounds();
        s.w = Math.max(MIN_BOX, Math.min(s.w, b.w));
        s.h = Math.max(MIN_BOX, Math.min(s.h, b.h));
        s.x = Math.max(0, Math.min(s.x, b.w - s.w));
        s.y = Math.max(0, Math.min(s.y, b.h - s.h));
        return s;
    }

    function setSelection(s) {
        sel = s ? clamp(s) : null;
        box.hidden = !sel;
        els["apply-crop"].disabled = !sel;
        if (!sel) return;
        box.style.left = sel.x + "px"; box.style.top = sel.y + "px";
        box.style.width = sel.w + "px"; box.style.height = sel.h + "px";
    }

    function clearSelection() { setSelection(null); }

    function point(e) {
        var rect = img.getBoundingClientRect();
        return { x: Math.max(0, Math.min(e.clientX - rect.left, rect.width)), y: Math.max(0, Math.min(e.clientY - rect.top, rect.height)) };
    }

    /** 拖一个角时按比例锁定：以对角为锚点，宽度主导。 */
    function fromAnchor(anchor, p) {
        var r = RATIOS[state.ratio];
        var w = Math.abs(p.x - anchor.x), h = Math.abs(p.y - anchor.y);
        if (r) h = w / r;
        return { x: p.x < anchor.x ? anchor.x - w : anchor.x, y: p.y < anchor.y ? anchor.y - h : anchor.y, w: w, h: h };
    }

    function onPointerDown(e) {
        if (!state || e.button > 0 || els.loading.hidden === false) return;
        var p = point(e), mode, anchor, start = sel ? { x: sel.x, y: sel.y, w: sel.w, h: sel.h } : null, origin = p;
        var handle = e.target.getAttribute && e.target.getAttribute("data-ie-handle");
        if (handle && sel) {
            mode = "resize";
            anchor = { x: handle.indexOf("w") >= 0 ? sel.x + sel.w : sel.x, y: handle.indexOf("n") >= 0 ? sel.y + sel.h : sel.y };
        } else if (e.target === box && sel) {
            mode = "move";
        } else {
            mode = "draw";
            anchor = p;
        }
        e.preventDefault();
        stage.setPointerCapture(e.pointerId);
        function move(ev) {
            var cur = point(ev);
            if (mode === "move") setSelection({ x: start.x + cur.x - origin.x, y: start.y + cur.y - origin.y, w: start.w, h: start.h });
            else setSelection(fromAnchor(anchor, cur));
        }
        function up(ev) {
            stage.removeEventListener("pointermove", move);
            stage.removeEventListener("pointerup", up);
            stage.removeEventListener("pointercancel", up);
            if (mode === "draw" && sel && (sel.w <= MIN_BOX && sel.h <= MIN_BOX)) clearSelection();   // 单击不留小框
            if (sel) box.focus();
            if (ev.pointerId !== undefined && stage.hasPointerCapture(ev.pointerId)) stage.releasePointerCapture(ev.pointerId);
        }
        stage.addEventListener("pointermove", move);
        stage.addEventListener("pointerup", up);
        stage.addEventListener("pointercancel", up);
    }

    /** 键盘：方向键移动选区 10px（按住 Alt 为 1px），加 Shift 改大小，Enter 应用。 */
    function onBoxKey(e) {
        if (!sel) return;
        var step = e.altKey ? 1 : 10, s = { x: sel.x, y: sel.y, w: sel.w, h: sel.h };
        var dx = e.key === "ArrowLeft" ? -step : e.key === "ArrowRight" ? step : 0;
        var dy = e.key === "ArrowUp" ? -step : e.key === "ArrowDown" ? step : 0;
        if (!dx && !dy) return;
        e.preventDefault();
        if (e.shiftKey) {
            s.w += dx; s.h = RATIOS[state.ratio] ? s.w / RATIOS[state.ratio] : s.h + dy;
        } else {
            s.x += dx; s.y += dy;
        }
        setSelection(s);
    }

    function applyCrop() {
        if (!sel) return;
        var size = currentSize(), b = bounds();
        var sx = size.w / b.w, sy = size.h / b.h;
        push({ op: "crop", x: Math.round(sel.x * sx), y: Math.round(sel.y * sy), w: Math.round(sel.w * sx), h: Math.round(sel.h * sy) }, I18N.cropped);
        clearSelection();
    }

    // ── 保存 / 恢复 ──────────────────────────────────────────────────────

    function post(fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        if (window.YK_IMAGE_EDIT_TOKEN && !fd.has("_token")) fd.append("_token", window.YK_IMAGE_EDIT_TOKEN);
        return fetch(ENDPOINT, { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) { return r.json(); });
    }

    function done(data, message) {
        if (!data || data.code !== 0) { announce(data && data.msg ? data.msg : I18N.failed, true); return; }
        state.history = []; state.undone = 0; state.alt = els.alt.value;
        var options = state.options || {}, opener = state.opener;
        modal.classList.add("hidden");
        img.removeAttribute("src");
        state = null;
        if (typeof options.onDone === "function") {
            notify(options, data.msg || message, false);
            if (opener && opener.focus) opener.focus();
            options.onDone(data.data || {});
            return;
        }
        if (window.showMessage) window.showMessage(data.msg || message);
        // 网址不变、文件已换：刷新列表让缩略图换新
        window.setTimeout(function () { window.location.reload(); }, 500);
    }

    function save() {
        els.save.disabled = true;
        post({ action: "save", id: state.id, history: JSON.stringify(activeHistory()), alt: els.alt.value })
            .then(function (data) { done(data, I18N.saved); })
            .catch(function () { announce(I18N.failed, true); })
            .finally(function () { els.save.disabled = false; });
    }

    function restore() {
        if (!window.confirm(I18N.restoreConfirm || "Restore the original image?")) return;
        post({ action: "restore", id: state.id })
            .then(function (data) { done(data, I18N.restored); })
            .catch(function () { announce(I18N.failed, true); });
    }

    function announce(message, isError) {
        if (!els.status || !message) return;
        els.status.textContent = message;
        if (isError) notify(state && state.options, message, true);
    }

    window.YkImageEditor = Object.freeze({ open: open });
})(window, document);
