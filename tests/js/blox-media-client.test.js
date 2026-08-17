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

test("upload keeps FormData and returns only a valid URL as success", async function () {
    nextResponse = { code: 0, data: { url: "/uploads/logo.png" } };
    const result = await global.BloxMediaClient.upload("/media", new Blob(["x"]));

    assert.deepEqual(result, { ok: true, message: "", url: "/uploads/logo.png" });
    assert.equal(lastRequest.options.method, "POST");
    assert.equal(lastRequest.options.body.get("type"), "images");
});

test("server failures remain structured for the editor toast", async function () {
    nextResponse = { code: 1, msg: "Upload rejected" };
    const result = await global.BloxMediaClient.upload("/media", new Blob(["x"]));

    assert.equal(result.ok, false);
    assert.equal(result.message, "Upload rejected");
    assert.equal(result.url, "");
});
