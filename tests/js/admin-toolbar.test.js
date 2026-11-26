const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
require("../../assets/js/admin-toolbar.js");
const { normalize, withItem, attributeValue, create, fit, open } = global.YikaiAdminToolbar;
const ORDER = ["search", "language", "notifications", "help", "visit", "cache", "ai"];
const TEXT = { saved: "Saved", restored: "Defaults restored", failed: "Could not save" };

function fakeRoot(keys) {
    const attrs = {};
    const nodes = [];
    keys.forEach(function (key) {
        ["data-yk-tb-item", "data-yk-tb-more"].forEach(function (name) {
            const node = { attrs: { [name]: key }, getAttribute(n) { return this.attrs[n] ?? null; }, setAttribute(n, v) { this.attrs[n] = v; } };
            nodes.push(node);
        });
    });
    return {
        nodes,
        pinned(key) { return nodes.filter((n) => (n.attrs["data-yk-tb-item"] || n.attrs["data-yk-tb-more"]) === key).map((n) => n.attrs["data-pinned"]); },
        setAttribute(n, v) { attrs[n] = v; },
        getAttribute(n) { return attrs[n]; },
        querySelectorAll() { return nodes; },
    };
}

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
    return { promise, resolve, reject };
}

test("normalize keeps known items once, in toolbar order", function () {
    assert.deepEqual(normalize(["ai", "help", "nope", "help", 4], ORDER), ["help", "ai"]);
    assert.deepEqual(normalize("help", ORDER), []);
    assert.deepEqual(withItem(["help"], "search", false, ORDER), ["search", "help"]);
    assert.deepEqual(withItem(["search", "help"], "search", true, ORDER), ["help"]);
});

test("the root attribute only lists shortcuts rendered on this page", function () {
    assert.equal(attributeValue(["help", "ai"], ["search", "help", "visit"]), "help");
    assert.equal(attributeValue([], ORDER), "");
});

test("unpinning applies at once and saves only the changed item", async function () {
    const root = fakeRoot(ORDER);
    const sent = [];
    const notes = [];
    const tb = create({ items: ORDER, rendered: ORDER, hidden: ["cache"], text: TEXT }, {
        root,
        send: (fields) => { sent.push(fields); return { code: 0, data: { hidden: ["help", "cache"] } }; },
        notify: (msg) => notes.push(msg),
    });
    assert.equal(root.getAttribute("data-yk-tb-hidden"), "cache");
    assert.deepEqual(root.pinned("cache"), ["0", "0"], "toolbar and More entry follow the saved state");

    const saving = tb.setPinned("help", false);
    assert.equal(root.getAttribute("data-yk-tb-hidden"), "help cache", "applies before the server answers");
    assert.deepEqual(root.pinned("help"), ["0", "0"]);
    assert.equal(await saving, true);
    assert.deepEqual(sent, [{ action: "set_item", item: "help", pinned: "0" }]);
    assert.deepEqual(notes, ["Saved"]);
    assert.equal(tb.isPinned("help"), false);
    assert.equal(tb.isPinned("search"), true);
});

test("a failed save puts the toolbar back and says so", async function () {
    const root = fakeRoot(ORDER);
    const notes = [];
    const tb = create({ items: ORDER, rendered: ORDER, hidden: [], text: TEXT }, {
        root,
        send: () => ({ code: 1, msg: "demo read-only" }),
        notify: (msg, kind) => notes.push([msg, kind]),
    });
    assert.equal(await tb.setPinned("search", false), false);
    assert.equal(root.getAttribute("data-yk-tb-hidden"), "");
    assert.deepEqual(root.pinned("search"), ["1", "1"]);
    assert.deepEqual(notes, [["Could not save", "error"]]);

    const offline = create({ items: ORDER, rendered: ORDER, hidden: ["ai"], text: TEXT }, { root, send: () => { throw new Error("offline"); } });
    assert.equal(await offline.reset(), false);
    assert.deepEqual(offline.hidden(), ["ai"]);
});

test("restore defaults pins everything and tells the server to forget", async function () {
    const root = fakeRoot(ORDER);
    const sent = [];
    const changes = [];
    const tb = create({ items: ORDER, rendered: ORDER, hidden: ["search", "notifications", "visit"], text: TEXT }, {
        root,
        send: (fields) => { sent.push(fields); return { code: 0, data: { hidden: [] } }; },
    });
    tb.onChange((hidden) => changes.push(hidden.join(" ")));
    assert.equal(await tb.reset(), true);
    assert.deepEqual(sent, [{ action: "reset" }]);
    assert.equal(root.getAttribute("data-yk-tb-hidden"), "");
    assert.deepEqual(changes, ["search notifications visit", "", ""], "checkboxes hear the reset, then the server's confirmation");
});

test("only the latest of several quick changes decides the final state", async function () {
    const root = fakeRoot(ORDER);
    const first = deferred();
    const second = deferred();
    const replies = [first, second];
    const tb = create({ items: ORDER, rendered: ORDER, hidden: [], text: TEXT }, { root, send: () => replies.shift().promise });
    const a = tb.setPinned("help", false);
    const b = tb.setPinned("cache", false);
    second.resolve({ code: 0, data: { hidden: ["help", "cache"] } });
    await b;
    first.resolve({ code: 0, data: { hidden: ["help"] } });
    await a;
    assert.deepEqual(tb.hidden(), ["help", "cache"], "a late answer to the first click does not undo the second");
});

test("unknown shortcuts are ignored and More dispatches after the click finishes", async function () {
    const root = fakeRoot(ORDER);
    let calls = 0;
    const tb = create({ items: ORDER, rendered: ORDER, hidden: [], text: TEXT }, { root, send: () => { calls++; return { code: 0, data: { hidden: [] } }; } });
    assert.equal(await tb.setPinned("logout", false), false);
    assert.equal(calls, 0);

    const seen = [];
    global.CustomEvent = class { constructor(type, init) { this.type = type; this.detail = init.detail; } };
    global.dispatchEvent = (event) => seen.push([event.type, event.detail]);
    open("notifications");
    assert.deepEqual(seen, [], "not dispatched inside the same click");
    await new Promise((resolve) => setTimeout(resolve, 5));
    assert.deepEqual(seen, [["yk-toolbar-open", "notifications"]]);
});

test("when the toolbar does not fit, low-priority shortcuts move into More until it does", function () {
    const attrs = {};
    const nodes = {};
    ["search", "language", "notifications", "help", "visit", "cache"].forEach((key) => {
        ["item", "more"].forEach((kind) => {
            nodes[kind + ":" + key] = { a: {}, setAttribute(n, v) { this.a[n] = v; }, removeAttribute(n) { delete this.a[n]; }, hasAttribute(n) { return n in this.a; } };
        });
    });
    const root = {
        setAttribute(n, v) { attrs[n] = v; },
        removeAttribute(n) { delete attrs[n]; },
        querySelector(sel) {
            const m = /data-yk-tb-(item|more)="([a-z]+)"/.exec(sel);
            return m ? nodes[m[1] + ":" + m[2]] || null : null;
        },
        querySelectorAll() { return Object.values(nodes).filter((n) => n.hasAttribute("data-overflow")); },
    };
    const hiddenByUser = new Set(["help"]);
    // 每挪走一项省出空间；还要再挪两项才放得下
    let excess = 2;
    const overflows = () => excess - Object.keys(nodes).filter((k) => k.startsWith("item:") && nodes[k].hasAttribute("data-overflow")).length > 0;
    const visible = (node) => !hiddenByUser.has(Object.keys(nodes).find((k) => nodes[k] === node).split(":")[1]);

    assert.deepEqual(fit(root, overflows, visible), ["cache", "visit"], "help is already in More, so it is skipped");
    assert.equal("data-yk-tb-overflowing" in attrs, true);
    assert.equal(nodes["more:cache"].hasAttribute("data-overflow"), true, "the More entry shows up too");
    assert.equal(nodes["item:search"].hasAttribute("data-overflow"), false, "Find Page and the bell are never moved");

    excess = 0;
    assert.deepEqual(fit(root, overflows, visible), [], "wider again: everything returns");
    assert.equal("data-yk-tb-overflowing" in attrs, false);
    assert.equal(nodes["item:cache"].hasAttribute("data-overflow"), false);
});
