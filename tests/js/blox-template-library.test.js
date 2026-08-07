const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
const requestedCalls = [];
global.fetch = function (url, options) {
    requestedCalls.push({ url, options: options || {} });
    const data = options && options.method === "POST"
        ? { template: { key: "remote:pricing", sections: [] } }
        : {
            items: [{
                key: "remote:pricing",
                type: "section",
                name: "Pricing",
                description: "Three pricing tiers",
                category: "marketing",
                source: "remote",
                locked: true,
            }],
            remote_error: "Remote market unavailable",
        };
    return Promise.resolve({
        json: function () { return Promise.resolve({ code: 0, data: data }); },
    });
};
require("../../assets/js/blox-template-library.js");

test("list keeps local results and exposes a remote provider warning", async function () {
    const items = await global.BloxTemplateLibrary.list("/templates", "page", "failed");
    assert.equal(items.length, 1);
    assert.equal(items[0].locked, true);
    assert.equal(items.remoteError, "Remote market unavailable");
});

test("forced catalog refresh reaches the backend", async function () {
    await global.BloxTemplateLibrary.list("/templates", "page", "failed", true);
    assert.match(requestedCalls.at(-1).url, /[?&]refresh=1(?:&|$)/);
});
test("template resolution uses CSRF-protected POST", async function () {
    await global.BloxTemplateLibrary.resolve("/templates", "page", "remote:pricing", "failed", "csrf-1");
    const request = requestedCalls.at(-1);
    assert.equal(request.url, "/templates");
    assert.equal(request.options.method, "POST");
    assert.equal(request.options.body.get("action"), "get");
    assert.equal(request.options.body.get("key"), "remote:pricing");
    assert.equal(request.options.body.get("_token"), "csrf-1");
});
test("filter searches remote descriptions and categories", function () {
    const items = [{
        key: "remote:pricing",
        type: "section",
        name: "Pricing",
        description: "Three pricing tiers",
        category: "marketing",
        provider: "update.yikaicms.com",
    }];

    assert.equal(global.BloxTemplateLibrary.filter(items, "tiers", "all").length, 1);
    assert.equal(global.BloxTemplateLibrary.filter(items, "marketing", "section").length, 1);
    assert.equal(global.BloxTemplateLibrary.filter(items, "marketing", "page").length, 0);
});
