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
require("../../assets/js/yikay-preview-client.js");

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
    const style = { scrollBehavior: "smooth" };
    return {
        srcdoc: "",
        contentDocument: { documentElement: { style } },
        contentWindow: {
            scrollX: 0,
            scrollY: 0,
            scrollTo: function (left, top) {
                assert.equal(style.scrollBehavior, "auto", "preview restoration must not animate");
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
    const signatureClient = new window.BloxPreviewClient({});
    const shell = { outerHTML: '<header>Theme header</header>', hasAttribute: () => false };
    const section = { outerHTML: '<section>Content</section>', hasAttribute: (key) => key === 'data-yk-sec' };
    const rail = { outerHTML: '<div class="yk-insert-rail">Add section</div>', hasAttribute: () => false, classList: { contains: (name) => name === 'yk-insert-rail' } };
    const sourceDoc = { head: { children: [] }, body: { children: [shell, section] } };
    const liveDoc = { head: { children: [] }, body: { children: [shell, rail, section, rail] } };
    assert.equal(signatureClient.documentSignature(liveDoc), signatureClient.documentSignature(sourceDoc), 'outside insertion controls must not invalidate section patching');
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
        getParams: function () { return { header_state: "overlay" }; },
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
    assert.equal(pending[1].request.body.get("header_state"), "overlay");
    // 2.1.3：multipart 发送（urlencoded 会把 JSON 百分号编码成 3–4 倍大）
    assert.ok(pending[1].request.body instanceof FormData, "preview body must be multipart FormData");

    // 调用方给了现成的 JSON 字符串：原样发出，不再序列化一遍
    const jsonRequests = [];
    const jsonClient = new global.BloxPreviewClient({
        endpoint: "/preview",
        csrf: "token",
        getFrame: function () { return fakeFrame(); },
        getHost: function () { return { scrollLeft: 0, scrollTop: 0 }; },
        getDocument: function () { throw new Error("getDocument must not run when getDocumentJson is given"); },
        getDocumentJson: function () { return '{"schema":1,"settings":{},"sections":[{"id":"中文"}]}'; },
        fetch: function (url, request) { jsonRequests.push(request); return new Promise(function () {}); },
    });
    jsonClient.refresh();
    assert.equal(jsonRequests[0].body.get("blocks_data"), '{"schema":1,"settings":{},"sections":[{"id":"中文"}]}');

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
    assert.equal(frame.contentDocument.documentElement.style.scrollBehavior, "smooth", "restore the original scroll style");
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

    {
        const model = { sections: [{ columns: [{ elements: [{ id: 'e1', type: 'heading', data: { text: 'Old' } }] }] }] };
        const requests = [];
        let language = 'zh-CN';
        const scoped = new global.BloxPreviewClient({
            endpoint: '/preview', csrf: 'token', getFrame: () => patchFrame, getHost: () => host,
            getDocument: () => model, getParams: () => ({ language }),
            fetch: (url, request) => { requests.push(request.body); return Promise.resolve(response('full-preview')); },
        });
        scoped.patchFrame = () => true;
        await scoped.refresh();
        model.sections[0].columns[0].elements[0].data.text = 'New';
        assert.equal(scoped.lastDocument.sections[0].columns[0].elements[0].data.text, 'Old', 'source snapshot must not share live editor objects');
        await scoped.refresh();
        assert.equal(requests[1].get('preview_element'), 'e1');
        language = 'en';
        model.sections[0].columns[0].elements[0].data.text = 'English';
        await scoped.refresh();
        assert.equal(requests[2].get('preview_scope'), null, 'changed context requires authoritative full rendering');
        model.sections[0].columns[0].elements[0].data.background_type = 'video';
        await scoped.refresh();
        assert.equal(requests[3].get('preview_scope'), null, 'new resource requirements must not use a static fragment');
    }

    const scheduledRequests = [];
    const scheduledClient = new global.BloxPreviewClient({
        endpoint: "/preview",
        csrf: "token",
        delay: 1,
        quickDelay: 1,
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
    scheduledClient.schedule();
    const immediate = scheduledClient.refresh();
    await new Promise(function (resolve) { setTimeout(resolve, 10); });
    assert.equal(scheduledRequests.length, 2, 'immediate refresh consumes the pending scheduled refresh');
    scheduledRequests[1].resolve(response('immediate'));
    await immediate;

    // 2.1.1：同一时间只发一个请求；在途时的改动积下来，结束后发最新的一份，不中途取消；
    // 刚整页重建过才用长延迟；还积着改动时不发 settled。
    {
        const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
        const coalRequests = [];
        let version = 0;
        const coalFrame = fakeFrame();
        const coalClient = new global.BloxPreviewClient({
            endpoint: "/preview",
            csrf: "token",
            delay: 30,
            quickDelay: 1,
            getFrame: function () { return coalFrame; },
            getHost: function () { return { scrollLeft: 0, scrollTop: 0 }; },
            getDocument: function () { return [{ id: "v" + version }]; },
            fetch: function (url, request) {
                const item = deferred();
                item.request = request;
                coalRequests.push(item);
                return item.promise;
            },
        });
        const settledEvents = [];
        const previousDispatch = global.dispatchEvent;
        global.dispatchEvent = function (event) { settledEvents.push(event.type); return true; };
        try {
            coalClient.schedule();
            await wait(8);
            assert.equal(coalRequests.length, 1, "an edit goes out after the short delay");
            const firstSignal = coalRequests[0].request.signal;
            version = 1;
            coalClient.schedule();
            version = 2;
            coalClient.schedule();
            await wait(8);
            assert.equal(coalRequests.length, 1, "edits while a request is in flight wait for it");
            assert.equal(firstSignal.aborted, false, "the in-flight request is not cancelled");
            assert.equal(coalClient.pending, true);

            coalRequests[0].resolve(response("<p>v0</p>"));
            await wait(1);
            coalFrame.fireLoad();
            assert.deepEqual(settledEvents, [], "not settled while newer edits are queued");
            await wait(10);
            assert.equal(coalRequests.length, 1, "right after a full reload the long delay applies");
            await wait(50);
            assert.equal(coalRequests.length, 2, "the queued edits go out in one request");
            assert.equal(coalRequests[1].request.body.get("blocks_data"), '[{"id":"v2"}]', "carrying the latest document");

            coalRequests[1].resolve(response("<p>v2</p>"));
            await wait(1);
            coalFrame.fireLoad();
            assert.deepEqual(settledEvents, ["blox:preview-settled"]);
            assert.equal(coalClient.pending, false);
        } finally {
            global.dispatchEvent = previousDispatch;
        }
    }

    // 2.1.1 候选 CI 测出：重建中途被 cancel()，「重建结束」不会再来；之后的改动不能卡住不发。
    {
        const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
        const stuckRequests = [];
        const stuckFrame = fakeFrame();
        const stuckClient = new global.BloxPreviewClient({
            endpoint: "/preview",
            csrf: "token",
            delay: 5,
            quickDelay: 1,
            getFrame: function () { return stuckFrame; },
            getHost: function () { return { scrollLeft: 0, scrollTop: 0 }; },
            getDocument: function () { return [{ id: "s" + stuckRequests.length }]; },
            fetch: function () {
                const item = deferred();
                stuckRequests.push(item);
                return item.promise;
            },
        });
        stuckClient.schedule();
        await wait(5);
        stuckRequests[0].resolve(response("<p>reload</p>"));
        await wait(1);
        assert.equal(stuckClient.rebuilding, true, "a full reload is in progress");
        stuckClient.cancel();   // 重建的 load 监听被移除，rebuilding 不会再被清掉
        stuckClient.schedule();
        await wait(20);
        assert.equal(stuckRequests.length, 2, "an edit after a cancelled rebuild is still sent");
    }

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

    // ── 滚动恢复产品级修复（r16-r17 终审裁决落地）────────────────────────
    // 场景 A：srcdoc 重建把宿主夹到 0 的窗口内，另一轮响应采样必须用
    // 最后稳定值兜底，settled 后滚动不得被恢复为 0。
    {
        const scrollPending = [];
        const scrollFrame = fakeFrame();
        const scrollHost = { scrollLeft: 0, scrollTop: 0 };
        const scrollClient = new global.BloxPreviewClient({
            endpoint: "/preview",
            csrf: "token",
            getFrame: function () { return scrollFrame; },
            getHost: function () { return scrollHost; },
            getDocument: function () { return []; },
            fetch: function () {
                const item = deferred();
                scrollPending.push(item);
                return item.promise;
            },
        });

        const roundOne = scrollClient.refresh();
        scrollHost.scrollTop = 340;
        scrollPending[0].resolve(response("round-one"));
        assert.equal(await roundOne, true);
        // srcdoc 已替换、load 未触发：浏览器此刻把宿主夹到 0
        scrollHost.scrollTop = 0;

        const roundTwo = scrollClient.refresh();
        scrollPending[1].resolve(response("round-two"));
        assert.equal(await roundTwo, true);
        assert.equal(scrollFrame.srcdoc, "round-two");
        scrollFrame.fireLoad();
        assert.equal(scrollHost.scrollTop, 340,
            "a response sampled inside the rebuild window must not restore scroll to the clamped 0");
    }

    // 场景 B：请求在途时用户继续向下滚动，恢复取 max(捕获, 当前)，
    // 不得把用户拉回捕获位置上方。
    {
        const userPending = [];
        const userFrame = fakeFrame();
        const userHost = { scrollLeft: 0, scrollTop: 120 };
        const userClient = new global.BloxPreviewClient({
            endpoint: "/preview",
            csrf: "token",
            getFrame: function () { return userFrame; },
            getHost: function () { return userHost; },
            getDocument: function () { return []; },
            fetch: function () {
                const item = deferred();
                userPending.push(item);
                return item.promise;
            },
        });
        const round = userClient.refresh();
        userPending[0].resolve(response("user-round"));
        assert.equal(await round, true);
        userHost.scrollTop = 500; // 用户在 load 前继续下滚
        userFrame.fireLoad();
        assert.equal(userHost.scrollTop, 500,
            "restore must keep the user's newer (larger) scroll position");
    }

    console.log("BloxPreviewClient tests OK");
}

run().catch(function (error) {
    console.error(error);
    process.exitCode = 1;
});
