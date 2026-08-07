const assert = require("node:assert/strict");

global.window = global;
global.requestAnimationFrame = function (callback) { callback(); };

class FakeAbortController {
    constructor() {
        this.signal = { aborted: false };
    }

    abort() {
        this.signal.aborted = true;
    }
}

global.AbortController = FakeAbortController;
require("../../assets/js/blox-preview-client.js");

function deferred() {
    let resolve;
    let reject;
    const promise = new Promise(function (onResolve, onReject) {
        resolve = onResolve;
        reject = onReject;
    });
    return { promise, resolve, reject };
}

function response(html, status = 200) {
    return {
        ok: status >= 200 && status < 300,
        status,
        text: function () { return Promise.resolve(html); },
    };
}

function fakeFrame() {
    const listeners = new Set();
    return {
        srcdoc: "",
        contentWindow: {
            scrollX: 0,
            scrollY: 0,
            scrollTo: function (left, top) {
                this.scrollX = left;
                this.scrollY = top;
            },
        },
        addEventListener: function (type, listener) {
            if (type === "load") listeners.add(listener);
        },
        removeEventListener: function (type, listener) {
            if (type === "load") listeners.delete(listener);
        },
        fireLoad: function () {
            Array.from(listeners).forEach(function (listener) { listener(); });
        },
    };
}

async function run() {
    const pending = [];
    const loading = [];
    const errors = [];
    const frame = fakeFrame();
    const host = { scrollLeft: 0, scrollTop: 0 };
    let loaded = 0;

    const client = new global.BloxPreviewClient({
        endpoint: "/preview",
        csrf: "token",
        getFrame: function () { return frame; },
        getHost: function () { return host; },
        getDocument: function () { return [{ id: "section" }]; },
        setLoading: function (value) { loading.push(value); },
        onLoaded: function () { loaded++; },
        onError: function (error) { errors.push(error); },
        fetch: function (url, request) {
            const item = deferred();
            item.url = url;
            item.request = request;
            pending.push(item);
            return item.promise;
        },
    });

    const first = client.refresh();
    const firstSignal = pending[0].request.signal;
    const second = client.refresh();

    assert.equal(firstSignal.aborted, true, "a newer refresh must abort the previous request");
    assert.equal(pending[1].url, "/preview");
    assert.equal(pending[1].request.body.get("action"), "preview");
    assert.equal(pending[1].request.body.get("blox"), "1");
    assert.equal(pending[1].request.body.get("_token"), "token");
    assert.equal(pending[1].request.body.get("blocks_data"), '[{"id":"section"}]');

    host.scrollLeft = 12;
    host.scrollTop = 34;
    frame.contentWindow.scrollX = 56;
    frame.contentWindow.scrollY = 78;
    pending[1].resolve(response("second"));
    assert.equal(await second, true);
    assert.equal(frame.srcdoc, "second");

    host.scrollLeft = 0;
    host.scrollTop = 0;
    frame.contentWindow.scrollX = 0;
    frame.contentWindow.scrollY = 0;
    frame.fireLoad();
    assert.deepEqual([host.scrollLeft, host.scrollTop], [12, 34]);
    assert.deepEqual([frame.contentWindow.scrollX, frame.contentWindow.scrollY], [56, 78]);
    assert.equal(loaded, 1);

    pending[0].resolve(response("stale"));
    assert.equal(await first, false);
    assert.equal(frame.srcdoc, "second", "a stale response must not replace the current preview");
    assert.equal(errors.length, 0);
    assert.equal(loading.at(-1), false);

    const patchPending = deferred();
    const patchFrame = fakeFrame();
    patchFrame.srcdoc = "existing-preview";
    patchFrame.contentDocument = { ready: true };
    let patchCalls = 0;
    let patchLoaded = 0;
    const patchClient = new global.BloxPreviewClient({
        endpoint: "/preview",
        csrf: "token",
        getFrame: function () { return patchFrame; },
        getHost: function () { return host; },
        getDocument: function () { return [{ id: "patched" }]; },
        onLoaded: function () { patchLoaded++; },
        fetch: function () { return patchPending.promise; },
    });
    patchClient.patchFrame = function (target, html) {
        patchCalls++;
        assert.equal(target, patchFrame);
        assert.equal(html, "patched-preview");
        return true;
    };
    const patched = patchClient.refresh();
    patchPending.resolve(response("patched-preview"));
    assert.equal(await patched, true);
    assert.equal(patchCalls, 1);
    assert.equal(patchLoaded, 1);
    assert.equal(patchFrame.srcdoc, "existing-preview", "a section patch must not reload the iframe document");

    const scheduledRequests = [];
    const scheduledClient = new global.BloxPreviewClient({
        endpoint: "/preview",
        csrf: "token",
        delay: 1,
        getFrame: function () { return frame; },
        getHost: function () { return host; },
        getDocument: function () { return []; },
        fetch: function () {
            const item = deferred();
            scheduledRequests.push(item);
            return item.promise;
        },
    });
    scheduledClient.schedule();
    scheduledClient.schedule();
    await new Promise(function (resolve) { setTimeout(resolve, 10); });
    assert.equal(scheduledRequests.length, 1, "schedule must debounce rapid changes");
    scheduledRequests[0].resolve(response("scheduled"));

    const errorClient = new global.BloxPreviewClient({
        endpoint: "/preview",
        csrf: "token",
        getFrame: function () { return frame; },
        getHost: function () { return host; },
        getDocument: function () { return []; },
        onError: function (error) { errors.push(error); },
        fetch: function () { return Promise.resolve(response("failed", 500)); },
    });
    assert.equal(await errorClient.refresh(), false);
    assert.equal(errors.length, 1);

    const cancelPending = deferred();
    const cancelLoading = [];
    const cancelClient = new global.BloxPreviewClient({
        endpoint: "/preview",
        csrf: "token",
        getFrame: function () { return frame; },
        getHost: function () { return host; },
        getDocument: function () { return []; },
        setLoading: function (value) { cancelLoading.push(value); },
        fetch: function () { return cancelPending.promise; },
    });
    const cancelled = cancelClient.refresh();
    const cancelSignal = cancelClient.controller.signal;
    cancelClient.cancel();
    assert.equal(cancelSignal.aborted, true);
    assert.equal(cancelLoading.at(-1), false);
    cancelPending.resolve(response("cancelled"));
    assert.equal(await cancelled, false);

    console.log("BloxPreviewClient tests OK");
}

run().catch(function (error) {
    console.error(error);
    process.exitCode = 1;
});
