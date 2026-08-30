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
require("../../assets/js/official-media-client.js");

test("official list forwards usage and normalizes entitlement", async function () {
    nextResponse = {
        code: 0,
        data: {
            items: [{
                id: "banner-tech-001",
                preview_url: "https://example.test/preview.webp",
                download_url: "https://example.test/original.jpg",
                sha256: "secret",
            }],
            page: 2,
            pages: 4,
            total: 72,
            entitlement: { can_import: true, reason: "ok" },
        },
    };

    const result = await global.OfficialMediaClient.list("/media", 2, "tech office", { usage: "hero-bg" });

    assert.equal(result.ok, true);
    assert.equal(result.items[0].id, "banner-tech-001");
    assert.equal(Object.prototype.hasOwnProperty.call(result.items[0], "download_url"), false);
    assert.equal(Object.prototype.hasOwnProperty.call(result.items[0], "sha256"), false);
    assert.equal(result.entitlement.canImport, true);
    assert.equal(result.entitlement.reason, "ok");
    assert.match(lastRequest.url, /action=remote_list/);
    assert.match(lastRequest.url, /keyword=tech%20office/);
    assert.match(lastRequest.url, /usage=hero-bg/);
    assert.equal(lastRequest.options.cache, "no-store");
});

test("official list keeps unavailable responses structured", async function () {
    nextResponse = { code: 1, msg: "Official media unavailable", data: { items: [] } };

    const result = await global.OfficialMediaClient.list("/media", 1, "");

    assert.equal(result.ok, false);
    assert.equal(result.message, "Official media unavailable");
    assert.deepEqual(result.items, []);
    assert.equal(result.entitlement.canImport, false);
});

test("official import submits only asset id and optional csrf", async function () {
    nextResponse = {
        code: 0,
        data: { media_id: 7, url: "/uploads/images/202608/banner-tech-001.jpg", imported: true },
    };

    const result = await global.OfficialMediaClient.importAsset("/media", "banner-tech-001", { csrf: "token-1" });

    assert.equal(result.ok, true);
    assert.equal(result.url, "/uploads/images/202608/banner-tech-001.jpg");
    assert.equal(lastRequest.options.method, "POST");
    assert.deepEqual(Array.from(lastRequest.options.body.keys()).sort(), ["_token", "asset_id"]);
    assert.equal(lastRequest.options.body.get("asset_id"), "banner-tech-001");
    assert.equal(lastRequest.options.body.get("_token"), "token-1");
});

test("official import rejects success responses without a local url", async function () {
    nextResponse = { code: 0, data: { download_url: "https://example.test/original.jpg" } };

    const result = await global.OfficialMediaClient.importAsset("/media", "banner-tech-001");

    assert.equal(result.ok, false);
    assert.equal(result.url, "");
    assert.deepEqual(Array.from(lastRequest.options.body.keys()), ["asset_id"]);
});

test("official import never returns a remote url to the editor", async function () {
    nextResponse = { code: 0, data: { url: "https://update.yikaicms.com/packages/media/originals/banner.jpg" } };

    const result = await global.OfficialMediaClient.importAsset("/media", "banner-tech-001");

    assert.equal(result.ok, false);
    assert.equal(result.url, "");
});
