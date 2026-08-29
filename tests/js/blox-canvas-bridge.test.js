const assert = require("node:assert/strict");
const test = require("node:test");

const listeners = Object.create(null);
global.window = global;
global.addEventListener = function (type, listener) {
    listeners[type] = listeners[type] || new Set();
    listeners[type].add(listener);
};
global.removeEventListener = function (type, listener) {
    if (listeners[type]) listeners[type].delete(listener);
};

require("../../assets/js/blox-canvas-bridge.js");

function fixture(overrides = {}) {
    const sent = [];
    const frameWindow = {
        postMessage: function (message, target) { sent.push({ message, target }); },
    };
    const frame = { contentWindow: frameWindow };
    const calls = [];
    const bridge = new global.BloxCanvasBridge(Object.assign({
        getFrame: function () { return frame; },
        onPickSection: function (target) { calls.push(["section", target]); },
        onPickColumn: function (si, ci) { calls.push(["column", si, ci]); },
        onPickElement: function (target) { calls.push(["element", target]); },
        onDrop: function (payload) { calls.push(["drop", payload.dropId]); },
        onTemplateDrop: function (payload) { calls.push(["template-drop", payload.key, payload.index, payload.dropId]); },
        onColumnRatio: function (payload) { calls.push(["ratio", payload]); },
        onContext: function (payload) { calls.push(["context", payload]); },
        onInlineEdit: function (payload) { calls.push(["inline", payload.value]); },
    }, overrides));
    return { bridge, frame, frameWindow, calls, sent };
}

test("只接受当前画布 iframe 的消息", function () {
    const current = fixture();
    assert.equal(current.bridge.handleMessage({ source: {}, data: { ykPick: 2 } }), false);
    assert.deepEqual(current.calls, []);

    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPick: 2 } }), true);
    assert.deepEqual(current.calls, [["section", { id: "", si: 2 }]]);
});

test("稳定区块 ID 与索引一起通过画布边界", function () {
    const current = fixture();
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykPickSection: { id: "s_process_2026", si: 4, ignored: true },
    } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykPickSection: { id: "", si: 4 },
    } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykPickSection: { id: "bad\u0000id", si: 4 },
    } }), false);
    assert.deepEqual(current.calls, [["section", { id: "s_process_2026", si: 4 }]]);
});

test("稳定元素 ID 与临时路径一起通过画布边界", function () {
    const current = fixture();
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykPickElement: { id: "e_logo_2026", path: "1.0.2", ignored: true },
    } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykPickElement: { id: "", path: "1.0.2" },
    } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykPickElement: { id: "bad\u0000id", path: "1.0.2" },
    } }), false);
    assert.deepEqual(current.calls, [["element", { id: "e_logo_2026", path: "1.0.2" }]]);
});

test("路径和索引在进入编辑器前完成校验", function () {
    const current = fixture();
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPickEl: "0.1" } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPickCol: "0.bad" } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPick: -1 } }), false);

    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPickEl: "0.1.2.3" } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPickCol: "4.5" } }), true);
    assert.deepEqual(current.calls, [["element", { id: "", path: "0.1.2.3" }], ["column", 4, 5]]);
});

test("列宽和右键载荷先标准化再回调", function () {
    const current = fixture();
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykColumnRatio: { kind: "home", path: "0.0.0", index: 9 },
    } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykContext: { kind: "element", target: { si: 1, ci: 0, ei: 2 }, x: "12", y: 30 },
    } }), false);

    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykColumnRatio: { kind: "section", si: 2, spans: [5, 7], ignored: true },
    } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykContext: { kind: "element", target: { si: 1, ci: 0, ei: 2, ignored: true }, x: 12, y: 30 },
    } }), true);
    assert.deepEqual(current.calls, [
        ["ratio", { kind: "section", si: 2, spans: [5, 7] }],
        ["context", { kind: "element", target: { si: 1, ci: 0, ei: 2 }, x: 12, y: 30 }],
    ]);
});
test("拖放只接受 v1 且按 dropId 去重", function () {
    const current = fixture();
    const payload = {
        version: 1,
        source: "palette",
        dropId: "drop-1",
        sec: 1,
        col: 0,
        type: "heading",
        target: { kind: "column", sec: 1, col: 0, position: "end" },
    };
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykDrop: Object.assign({}, payload, { version: 2 }) } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykDrop: Object.assign({}, payload, { target: { kind: "element", path: "bad", position: "after" } }) } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykDrop: payload } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykDrop: payload } }), true);
    const containerPayload = Object.assign({}, payload, {
        dropId: "drop-2",
        target: { kind: "container", path: "1.0.2" },
    });
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykDrop: containerPayload } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykDrop: Object.assign({}, containerPayload, { dropId: "drop-3", target: { kind: "container", path: "1.0.2.0" } }),
    } }), false);
    assert.deepEqual(current.calls, [["drop", "drop-1"], ["drop", "drop-2"]]);
});

test("预制区块拖放只接受白名单模板键和插入索引", function () {
    const current = fixture();
    const payload = { version: 1, key: "builtin:hero-intro", index: 3, dropId: "template-drop-1" };
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykTemplateDrop: Object.assign({}, payload, { key: "bad key" }),
    } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: {
        ykTemplateDrop: Object.assign({}, payload, { index: -1 }),
    } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykTemplateDrop: payload } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykTemplateDrop: payload } }), true);
    assert.deepEqual(current.calls, [["template-drop", "builtin:hero-intro", 3, "template-drop-1"]]);
});

test('ykClear 路由到 onClear，非 true 形态不路由', function () {
    let cleared = 0;
    const current = fixture({ onClear: function () { cleared += 1; } });
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykClear: true } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykClear: 'yes' } }), false);
    assert.equal(cleared, 1);
});

test('ykAreaHit 只接受非负整数（r9 上下文命中上报）', function () {
    const hits = [];
    const current = fixture({ onAreaHit: function (id) { hits.push(id); } });
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaHit: 0 } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaHit: 12 } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaHit: -1 } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaHit: 1.5 } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaHit: "3" } }), false);
    assert.deepEqual(hits, [0, 12]);
});

test('ykAreaMatch 只接受完整的命中来源说明', function () {
    const matches = [];
    const current = fixture({ onAreaMatch: function (match) { matches.push(match); } });
    const valid = { id: 12, name: 'English Header', scope: 'home', languageSpecific: true };
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaMatch: valid } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaMatch: { ...valid, id: '12' } } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaMatch: { ...valid, scope: 'evil' } } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykAreaMatch: { ...valid, languageSpecific: 1 } } }), false);
    assert.deepEqual(matches, [valid]);
});

test('ykEmptyAction 白名单：templates/section 过，其余拒', function () {
    const actions = [];
    const current = fixture({ onEmptyAction: function (a) { actions.push(a); } });
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEmptyAction: 'templates' } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEmptyAction: 'section' } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEmptyAction: 'evil' } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEmptyAction: true } }), false);
    assert.deepEqual(actions, ['templates', 'section']);
});

test('ykQuickAdd 只接受列或容器的规范目标（r18 画布空态快捷添加）', function () {
    const got = [];
    const current = fixture({ onQuickAdd: function (target) { got.push(target); } });
    const send = (target) => current.bridge.handleMessage({ source: current.frameWindow, data: { ykQuickAdd: target } });
    assert.equal(send({ kind: 'column', sec: 2, col: 1, ignored: true }), true);
    assert.equal(send({ kind: 'container', path: '3.0.2' }), true);
    assert.equal(send({ kind: 'column', sec: -1, col: 0 }), false);
    assert.equal(send({ kind: 'container', path: '3.0' }), false);
    assert.equal(send({ kind: 'element', path: '3.0.2' }), false);
    assert.deepEqual(got, [
        { kind: 'column', sec: 2, col: 1 },
        { kind: 'container', path: '3.0.2' },
    ]);
});

test('ykInsertAt 白名单：index/kind/spans 全校验（r13 插入轨道）', function () {
    const got = [];
    const current = fixture({ onInsertAt: function (p) { got.push(p); } });
    const ok = (d) => current.bridge.handleMessage({ source: current.frameWindow, data: { ykInsertAt: d } });
    assert.equal(ok({ index: 0, kind: 'layout', spans: [6, 6] }), true);
    assert.equal(ok({ index: 3, kind: 'templates' }), true);
    assert.equal(ok({ index: 1, kind: 'blank' }), true);
    assert.equal(ok({ index: -1, kind: 'blank' }), false);
    assert.equal(ok({ index: 501, kind: 'blank' }), false);
    assert.equal(ok({ index: 1, kind: 'evil' }), false);
    assert.equal(ok({ index: 1, kind: 'layout', spans: [] }), false);
    assert.equal(ok({ index: 1, kind: 'layout', spans: [13] }), false);
    assert.equal(ok({ index: 1, kind: 'layout', spans: [1, 2, 3, 4, 5, 6, 7] }), false);
    assert.deepEqual(got.map(function (p) { return p.kind; }), ['layout', 'templates', 'blank']);
});

test('ykDropRejected 具名拒因白名单（r14）', function () {
    const got = [];
    const current = fixture({ onDropRejected: function (r) { got.push(r); } });
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykDropRejected: 'restricted-children' } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykDropRejected: 'no-nested-container' } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykDropRejected: 'evil' } }), false);
    assert.deepEqual(got, ['restricted-children', 'no-nested-container']);
});

test("内联编辑限制体积，发送与生命周期保持幂等", function () {
    const current = fixture();
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykInlineEdit: { kind: "element", path: "0.0.0", field: "text", format: "text", value: "ok" } } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykInlineEdit: { kind: "element", path: "0.0.0", field: "text", format: "text", value: "x".repeat(2097153) } } }), false);
    assert.deepEqual(current.calls, [["inline", "ok"]]);

    current.bridge.start();
    current.bridge.start();
    assert.equal(listeners.message.size, 1);
    assert.equal(current.bridge.post({ ykHighlight: 3 }), true);
    assert.deepEqual(current.sent, [{ message: { ykHighlight: 3 }, target: "*" }]);
    current.bridge.dispose();
    assert.equal(listeners.message.size, 0);
});
test("画布「编辑页头/页尾」入口：白名单 URL 通过并原样重建（含 back=home）", function () {
    const current = fixture({
        onEditArea: function (payload) { current.calls.push(["editArea", payload.area, payload.url]); },
    });
    // v1.18.4 原始形态
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEditArea: {
        area: "header", url: "/admin/blox_editor.php?template=2&current_header=1&open=header-settings",
    } } }), true);
    // v1.18.6：带 back=home（首页画布跳来，编辑完一键返回）——
    // 2026-08-22 曾因白名单正则没同步导致点击静默无反应
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEditArea: {
        area: "header", url: "/admin/blox_editor.php?template=2&current_header=1&back=home&open=header-settings",
    } } }), true);
    assert.deepEqual(current.calls, [
        ["editArea", "header", "/admin/blox_editor.php?template=2&current_header=1&open=header-settings"],
        ["editArea", "header", "/admin/blox_editor.php?template=2&current_header=1&back=home&open=header-settings"],
    ]);
});

test("画布区域编辑入口拒绝白名单外的 URL", function () {
    const current = fixture({
        onEditArea: function (payload) { current.calls.push(["editArea", payload.url]); },
    });
    for (const url of [
        "https://evil.com/admin/blox_editor.php?template=2",
        "/admin/blox_editor.php?template=2&back=https://evil.com",
        "/admin/blox_editor.php?template=0",
        "/admin/other.php?template=2",
    ]) {
        assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEditArea: { area: "header", url } } }), false, url);
    }
    assert.deepEqual(current.calls, []);
});

test("页面标题区入口只接受来自当前画布的布尔协议", function () {
    const current = fixture({
        onEditPageHero: function () { current.calls.push(["editPageHero"]); },
    });
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEditPageHero: true } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykEditPageHero: "true" } }), false);
    assert.equal(current.bridge.handleMessage({ source: {}, data: { ykEditPageHero: true } }), false);
    assert.deepEqual(current.calls, [["editPageHero"]]);
});
