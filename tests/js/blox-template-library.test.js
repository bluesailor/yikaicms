const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
const requestedCalls = [];
global.fetch = function (url, options) {
    requestedCalls.push({ url, options: options || {} });
    if (url === "/html-error?action=list&context=page") {
        return Promise.resolve({
            ok: false,
            status: 404,
            text: function () { return Promise.resolve("<html><h1>Not Found</h1></html>"); },
        });
    }
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
        ok: true,
        status: 200,
        text: function () { return Promise.resolve(JSON.stringify({ code: 0, data: data })); },
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
test("HTML error responses become a stable HTTP error instead of leaking JSON parser text", async function () {
    await assert.rejects(
        global.BloxTemplateLibrary.list("/html-error", "page", "failed"),
        /failed \(HTTP 404\)/
    );
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
test("filter searches remote descriptions, keywords, and categories", function () {
    const items = [{
        key: "remote:pricing",
        type: "section",
        name: "Pricing",
        description: "Three pricing tiers",
        category: "marketing",
        provider: "update.yikaicms.com",
        keywords: ["price", "comparison"],
    }];

    assert.equal(global.BloxTemplateLibrary.filter(items, "tiers", "all").length, 1);
    assert.equal(global.BloxTemplateLibrary.filter(items, "marketing", "section").length, 1);
    assert.equal(global.BloxTemplateLibrary.filter(items, "comparison", "section").length, 1);
    assert.equal(global.BloxTemplateLibrary.filter(items, "marketing", "page").length, 0);
});

test("filter can narrow a mixed catalog by provider source", function () {
    const items = [
        { key: "local:1", type: "section", name: "Local", source: "local" },
        { key: "remote:hero", type: "section", name: "Remote", source: "remote" },
        { key: "plugin:shop:grid", type: "section", name: "Plugin", source: "plugin" },
    ];

    assert.deepEqual(
        global.BloxTemplateLibrary.filter(items, "", "all", "remote").map((item) => item.key),
        ["remote:hero"]
    );
    assert.equal(global.BloxTemplateLibrary.filter(items, "", "all", "all").length, 3);
});

test("remote catalog can be filtered by template scenario", function () {
    const items = [
        { key: "remote:launch", type: "page", category: "landing", source: "remote" },
        { key: "remote:about", type: "page", category: "page", source: "remote" },
        { key: "remote:hero", type: "section", category: "marketing", source: "remote" },
    ];

    assert.deepEqual(global.BloxTemplateLibrary.categories(items), ["landing", "marketing", "page"]);
    assert.deepEqual(
        global.BloxTemplateLibrary.filter(items, "", "all", "all", "landing").map((item) => item.key),
        ["remote:launch"]
    );
    assert.equal(global.BloxTemplateLibrary.categoryLabel("landing", { categoryLanding: "Landing pages" }), "Landing pages");
    assert.equal(global.BloxTemplateLibrary.categoryLabel("custom", {}), "custom");
});

test("presentation helpers keep local and remote template behavior in one module", function () {
    const items = [
        { key: "local:12", source: "local" },
        { key: "builtin:404-route-lost", source: "builtin", provider: "yikaicms" },
        { key: "plugin:shop:grid", source: "plugin", provider: "Shop" },
        { key: "remote:hero", source: "remote", locked: true, locked_reason: "license_expired" },
    ];
    const text = {
        local: "Local",
        plugin: "Plugin",
        remote: "Remote",
        lockedExpired: "Expired",
        lockedModule: "Module",
        lockedLicense: "License",
    };

    assert.equal(global.BloxTemplateLibrary.scopeCount(items, "local"), 3);
    assert.deepEqual(global.BloxTemplateLibrary.scope(items, "remote"), [items[3]]);
    assert.equal(global.BloxTemplateLibrary.providerLabel(items[1], text), "Local");
    assert.equal(global.BloxTemplateLibrary.providerLabel(items[2], text), "Plugin / Shop");
    assert.equal(global.BloxTemplateLibrary.canEditLocal(items[0]), true);
    assert.equal(global.BloxTemplateLibrary.canEditLocal(items[1]), false);
    assert.equal(global.BloxTemplateLibrary.localEditUrl(items[0]), "/admin/blox_editor.php?template=12");
    assert.equal(global.BloxTemplateLibrary.localEditUrl(items[2]), "");
    assert.equal(global.BloxTemplateLibrary.lockLabel(items[3], text), "Expired");
    assert.equal(global.BloxTemplateLibrary.hasLockedRemote(items), true);
});

test("saved local template is inserted immediately and replaces a stale copy", function () {
    const items = [
        { key: "remote:hero", type: "section", source: "remote" },
        { key: "local:12", type: "section", name: "Old", source: "local" },
    ];
    items.remoteError = "offline";
    const saved = { key: "local:12", type: "section", name: "Saved", source: "local" };

    const merged = global.BloxTemplateLibrary.upsertLocal(items, saved);

    assert.deepEqual(merged.map((item) => item.key), ["local:12", "remote:hero"]);
    assert.equal(merged[0].name, "Saved");
    assert.equal(merged[0].locked, false);
    assert.equal(merged[0].locked_reason, "");
    assert.equal(merged.remoteError, "offline");
    assert.deepEqual(global.BloxTemplateLibrary.upsertLocal(items, { key: "remote:x", type: "section", source: "remote" }), items);
});

test("recommendations match page intent and keep priority ordering stable", function () {
    const items = [
        { key: "builtin:generic", type: "section", metadata: { page_types: ["general"], priority: 100 } },
        { key: "builtin:service-low", type: "section", metadata: { page_types: ["service"], priority: 20 } },
        { key: "builtin:service-high", type: "section", metadata: { page_types: ["service"], priority: 90 } },
        { key: "builtin:service-high-2", type: "section", metadata: { page_types: ["service"], priority: 90 } },
        { key: "builtin:page", type: "page", metadata: { page_types: ["service"], priority: 100 } },
    ];

    assert.deepEqual(
        global.BloxTemplateLibrary.recommend(items, "service").map((item) => item.key),
        ["builtin:service-high", "builtin:service-high-2", "builtin:service-low"]
    );
    assert.equal(global.BloxTemplateLibrary.isRecommended(items[0], "service"), false);
    assert.equal(global.BloxTemplateLibrary.isRecommended(items[2], "service"), true);
});

test("metadata normalization gives old templates a bounded general fallback", function () {
    const old = global.BloxTemplateLibrary.normalizeMetadata(null);
    const unsafe = global.BloxTemplateLibrary.normalizeMetadata({
        page_types: ["about", "<script>", "about"],
        priority: 900,
    });

    assert.deepEqual(old.page_types, ["general"]);
    assert.equal(old.priority, 0);
    assert.deepEqual(unsafe.page_types, ["about"]);
    assert.equal(unsafe.priority, 100);
});
