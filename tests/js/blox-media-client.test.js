const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
let nextResponse = {};
let lastRequest = null;
global.fetch = function (url, options) {
    lastRequest = { url, options: options || {} };
    return Promise.resolve({
        json: function () { return Promise.resolve(nextResponse); },
    });
};
require("../../assets/js/blox-media-client.js");

test("list normalizes media pagination and encodes search", async function () {
    nextResponse = {
        code: 0,
        data: { items: [{ id: 1 }], pages: 3, total: 21 },
    };
    const result = await global.BloxMediaClient.list("/media", 2, "brand mark");

    assert.equal(result.ok, true);
    assert.equal(result.pages, 3);
    assert.equal(result.total, 21);
    assert.match(lastRequest.url, /keyword=brand%20mark/);
    assert.equal(lastRequest.options.cache, "no-store");
});

test("upload keeps FormData, adds CSRF, and returns only a valid URL as success", async function () {
    nextResponse = { code: 0, data: { url: "/uploads/logo.png" } };
    const result = await global.BloxMediaClient.upload("/media", new Blob(["x"]), { csrf: "token-1" });

    assert.deepEqual(result, { ok: true, message: "", url: "/uploads/logo.png" });
    assert.equal(lastRequest.options.method, "POST");
    assert.equal(lastRequest.options.body.get("type"), "images");
    assert.equal(lastRequest.options.body.get("_token"), "token-1");
});

test("server failures remain structured for the editor toast", async function () {
    nextResponse = { code: 1, msg: "Upload rejected" };
    const result = await global.BloxMediaClient.upload("/media", new Blob(["x"]));

    assert.equal(result.ok, false);
    assert.equal(result.message, "Upload rejected");
    assert.equal(result.url, "");
});

test("large browser images are resized proportionally before upload", async function () {
    const originalCreateImageBitmap = global.createImageBitmap;
    const originalDocument = global.document;
    let canvasWidth = 0;
    let canvasHeight = 0;
    let bitmapClosed = false;

    global.createImageBitmap = function () {
        return Promise.resolve({
            width: 4000,
            height: 2000,
            close: function () { bitmapClosed = true; },
        });
    };
    global.document = {
        createElement: function () {
            return {
                set width(value) { canvasWidth = value; },
                get width() { return canvasWidth; },
                set height(value) { canvasHeight = value; },
                get height() { return canvasHeight; },
                getContext: function () { return { drawImage: function () {} }; },
                toBlob: function (callback, type) { callback(new Blob(["optimized"], { type })); },
            };
        },
    };

    try {
        const source = new Blob(["original"], { type: "image/jpeg" });
        const prepared = await global.BloxMediaClient.prepareImage(source, { maxDimension: 1920 });

        assert.notEqual(prepared, source);
        assert.equal(prepared.type, "image/jpeg");
        assert.equal(canvasWidth, 1920);
        assert.equal(canvasHeight, 960);
        assert.equal(bitmapClosed, true);
    } finally {
        global.createImageBitmap = originalCreateImageBitmap;
        global.document = originalDocument;
    }
});

test("unsupported formats and browser processing failures keep the original file", async function () {
    const originalCreateImageBitmap = global.createImageBitmap;
    global.createImageBitmap = function () { return Promise.reject(new Error("decode failed")); };

    try {
        const gif = new Blob(["gif"], { type: "image/gif" });
        const jpeg = new Blob(["jpeg"], { type: "image/jpeg" });
        assert.equal(await global.BloxMediaClient.prepareImage(gif), gif);
        assert.equal(await global.BloxMediaClient.prepareImage(jpeg, { minBytes: 1 }), jpeg);
    } finally {
        global.createImageBitmap = originalCreateImageBitmap;
    }
});

test("an explicit zero maximum keeps dimensions unlimited", async function () {
    const originalCreateImageBitmap = global.createImageBitmap;
    const originalDocument = global.document;
    let canvasCreated = false;
    global.createImageBitmap = function () {
        return Promise.resolve({ width: 5000, height: 3000, close: function () {} });
    };
    global.document = {
        createElement: function () {
            canvasCreated = true;
            throw new Error("canvas should not be created");
        },
    };

    try {
        const source = new Blob(["small"], { type: "image/png" });
        assert.equal(await global.BloxMediaClient.prepareImage(source, { maxDimension: 0 }), source);
        assert.equal(canvasCreated, false);
    } finally {
        global.createImageBitmap = originalCreateImageBitmap;
        global.document = originalDocument;
    }
});
