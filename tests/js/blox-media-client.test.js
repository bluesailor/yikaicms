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

    assert.deepEqual(result, {
        ok: true,
        message: "",
        url: "/uploads/logo.png",
        optimized: false,
        originalBytes: 1,
        uploadBytes: 1,
    });
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
    assert.equal(result.optimized, false);
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

test("image element decoding is used when createImageBitmap rejects a supported image", async function () {
    const originalCreateImageBitmap = global.createImageBitmap;
    const originalDocument = global.document;
    const originalImage = global.Image;
    const originalURL = global.URL;
    let revoked = false;

    global.createImageBitmap = function () { return Promise.reject(new Error("bitmap decode failed")); };
    global.URL = {
        createObjectURL: function () { return "blob:fallback"; },
        revokeObjectURL: function (url) { if (url === "blob:fallback") revoked = true; },
    };
    global.Image = function () {
        const image = this;
        image.naturalWidth = 3000;
        image.naturalHeight = 1500;
        Object.defineProperty(image, "src", {
            set: function () { queueMicrotask(function () { image.onload(); }); },
        });
    };
    global.document = {
        createElement: function () {
            return {
                width: 0,
                height: 0,
                getContext: function () { return { drawImage: function () {} }; },
                toBlob: function (callback, type) { callback(new Blob(["smaller"], { type })); },
            };
        },
    };

    try {
        const source = new Blob(["original image bytes"], { type: "image/jpeg" });
        const prepared = await global.BloxMediaClient.prepareImage(source, { maxDimension: 1500 });
        assert.notEqual(prepared, source);
        assert.equal(prepared.type, "image/jpeg");
        assert.equal(revoked, true);
    } finally {
        global.createImageBitmap = originalCreateImageBitmap;
        global.document = originalDocument;
        global.Image = originalImage;
        global.URL = originalURL;
    }
});

test("canvas failures release decoded image resources before falling back", async function () {
    const originalCreateImageBitmap = global.createImageBitmap;
    const originalDocument = global.document;
    let bitmapClosed = false;

    global.createImageBitmap = function () {
        return Promise.resolve({
            width: 3000,
            height: 1500,
            close: function () { bitmapClosed = true; },
        });
    };
    global.document = {
        createElement: function () {
            return {
                width: 0,
                height: 0,
                getContext: function () {
                    return { drawImage: function () { throw new Error("canvas failed"); } };
                },
            };
        },
    };

    try {
        const source = new Blob(["original"], { type: "image/jpeg" });
        assert.equal(await global.BloxMediaClient.prepareImage(source, { maxDimension: 1500 }), source);
        assert.equal(bitmapClosed, true);
    } finally {
        global.createImageBitmap = originalCreateImageBitmap;
        global.document = originalDocument;
    }
});

test("upload filename follows the encoded MIME type and reports byte metrics", async function () {
    const originalCreateImageBitmap = global.createImageBitmap;
    const originalDocument = global.document;
    global.createImageBitmap = function () {
        return Promise.resolve({ width: 1200, height: 800, close: function () {} });
    };
    global.document = {
        createElement: function () {
            return {
                width: 0,
                height: 0,
                getContext: function () { return { drawImage: function () {} }; },
                // Browsers may fall back to PNG when the requested encoder is unavailable.
                toBlob: function (callback) { callback(new Blob(["png"], { type: "image/png" })); },
            };
        },
    };

    try {
        nextResponse = { code: 0, data: { url: "/uploads/photo.png" } };
        const source = new Blob(["original-webp"], { type: "image/webp" });
        Object.defineProperty(source, "name", { value: "photo.webp" });
        const result = await global.BloxMediaClient.upload("/media", source, { minBytes: 1 });
        const uploaded = lastRequest.options.body.get("file");

        assert.equal(uploaded.name, "photo.png");
        assert.equal(uploaded.type, "image/png");
        assert.equal(result.optimized, true);
        assert.equal(result.originalBytes, source.size);
        assert.equal(result.uploadBytes, uploaded.size);
    } finally {
        global.createImageBitmap = originalCreateImageBitmap;
        global.document = originalDocument;
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
