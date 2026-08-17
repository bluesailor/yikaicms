const assert = require("node:assert/strict");
const test = require("node:test");

function element(name) {
    return {
        name,
        isConnected: true,
        focused: 0,
        focus() { this.focused++; global.document.activeElement = this; },
        getAttribute() { return null; },
        getClientRects() { return [1]; },
    };
}

global.window = global;
global.document = { activeElement: null };
global.requestAnimationFrame = function (callback) { callback(); };
require("../../assets/js/blox-dialog-focus.js");

function root(items, initial) {
    const node = element("root");
    node.querySelectorAll = function () { return items; };
    node.querySelector = function () { return initial || null; };
    return node;
}

function event(key, shiftKey) {
    return {
        key,
        shiftKey: !!shiftKey,
        prevented: false,
        stopped: false,
        preventDefault() { this.prevented = true; },
        stopPropagation() { this.stopped = true; },
    };
}

test("open focuses the preferred control and close restores the opener", function () {
    const opener = element("opener");
    const first = element("first");
    const preferred = element("preferred");
    const dialog = root([first, preferred], preferred);
    global.document.activeElement = opener;

    global.BloxDialogFocus.open(dialog, "[data-dialog-initial]");
    assert.equal(global.document.activeElement, preferred);
    global.BloxDialogFocus.close(dialog);
    assert.equal(global.document.activeElement, opener);
});

test("tab and shift-tab remain inside the top dialog", function () {
    const first = element("first");
    const last = element("last");
    const dialog = root([first, last]);
    global.document.activeElement = element("opener");
    global.BloxDialogFocus.open(dialog);

    global.document.activeElement = last;
    const forward = event("Tab", false);
    global.BloxDialogFocus.keydown(forward, dialog);
    assert.equal(forward.prevented, true);
    assert.equal(global.document.activeElement, first);

    const backward = event("Tab", true);
    global.BloxDialogFocus.keydown(backward, dialog);
    assert.equal(backward.prevented, true);
    assert.equal(global.document.activeElement, last);
});

test("only the top dialog handles escape", function () {
    const lower = root([element("lower")]);
    const upper = root([element("upper")]);
    global.document.activeElement = element("opener");
    global.BloxDialogFocus.open(lower);
    global.BloxDialogFocus.open(upper);
    let closes = 0;

    global.BloxDialogFocus.keydown(event("Escape"), lower, function () { closes++; });
    assert.equal(closes, 0);
    const escape = event("Escape");
    global.BloxDialogFocus.keydown(escape, upper, function () { closes++; });
    assert.equal(closes, 1);
    assert.equal(escape.stopped, true);
});
