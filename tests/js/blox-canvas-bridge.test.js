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
        onPickSection: function (si) { calls.push(["section", si]); },
        onPickColumn: function (si, ci) { calls.push(["column", si, ci]); },
        onPickElement: function (path) { calls.push(["element", path]); },
        onDrop: function (payload) { calls.push(["drop", payload.dropId]); },
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
    assert.deepEqual(current.calls, [["section", 2]]);
});

test("路径和索引在进入编辑器前完成校验", function () {
    const current = fixture();
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPickEl: "0.1" } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPickCol: "0.bad" } }), false);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPick: -1 } }), false);

    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPickEl: "0.1.2.3" } }), true);
    assert.equal(current.bridge.handleMessage({ source: current.frameWindow, data: { ykPickCol: "4.5" } }), true);
    assert.deepEqual(current.calls, [["element", "0.1.2.3"], ["column", 4, 5]]);
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
    assert.deepEqual(current.calls, [["drop", "drop-1"]]);
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