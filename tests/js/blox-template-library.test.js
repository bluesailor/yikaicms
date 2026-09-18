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

test("full page imports apply only explicit page frame settings without mutating inputs", function () {
    const current = { page_header_hidden: false, sticky: true };
    const template = { type: "page", settings: { page_header_hidden: true, page_footer_hidden: "1", page_title_hidden: "false", sticky: false, product_template: { mode: "all" } } };
    const before = JSON.stringify([current, template]);
    assert.deepEqual(global.BloxTemplateLibrary.applyPageSettings(current, template, "append", true), {
        page_header_hidden: true, page_footer_hidden: true, page_title_hidden: false, sticky: true,
    });
    assert.equal(JSON.stringify([current, template]), before);
});

test("section imports and non-page targets never alter page frame preferences", function () {
    const current = { page_header_hidden: true, page_footer_hidden: true };
    const settings = { page_header_hidden: false, page_footer_hidden: false };
    assert.equal(global.BloxTemplateLibrary.applyPageSettings(current, { type: "section", settings }, "replace", true), current);
    assert.equal(global.BloxTemplateLibrary.applyPageSettings(current, { type: "page", settings }, "replace", false), current);
});

test("replacing a standalone page with a legacy page restores default frame settings", function () {
    const current = { page_header_hidden: true, page_footer_hidden: true, page_title_hidden: true, page_sidebar_hidden: true, sticky: true };
    assert.deepEqual(global.BloxTemplateLibrary.applyPageSettings(current, { type: "page" }, "replace", true), { sticky: true });
    assert.deepEqual(global.BloxTemplateLibrary.applyPageSettings(current, { type: "page" }, "append", true), current);
});

test("area comparison counts nested elements without changing documents", function () {
    const current = [{ columns: [{ elements: [
        { type: "container", data: { children: [{ type: "logo" }, { type: "nav" }] } },
        { type: "logo" },
    ] }] }];
    const candidate = [{ columns: [{ elements: [{ type: "site-contact" }, { type: "site-contact" }, { type: "logo" }] }] }];
    const original = JSON.stringify([current, candidate]);
    const result = global.BloxTemplateLibrary.compareSections(current, candidate, (type, count) => type + ":" + count);
    assert.deepEqual(global.BloxTemplateLibrary.elementCounts(current), { container: 1, logo: 2, nav: 1 });
    assert.deepEqual(result, { added: ["site-contact:2"], removed: ["container:1", "logo:1", "nav:1"], same: false });
    assert.equal(JSON.stringify([current, candidate]), original);
    assert.deepEqual(global.BloxTemplateLibrary.compareSections(current, current, String), { added: [], removed: [], same: true });
    assert.deepEqual(global.BloxTemplateLibrary.elementCounts([]), {});
});

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

test("section purposes support filtering, labels, and metadata search", function () {
    const items = [
        { key: "a", type: "section", name: "Intro", metadata: { purpose: "company-intro", page_types: ["about"] } },
        { key: "b", type: "section", name: "Hero", metadata: { purpose: "hero", page_types: ["home"] } },
        { key: "c", type: "page", name: "Page", metadata: { purpose: "content", page_types: ["general"] } },
    ];

    assert.deepEqual(global.BloxTemplateLibrary.purposes(items), ["company-intro", "hero"]);
    assert.deepEqual(
        global.BloxTemplateLibrary.filter(items, "", "section", "all", "all", "hero").map((item) => item.key),
        ["b"]
    );
    assert.deepEqual(global.BloxTemplateLibrary.filter(items, "about", "section", "all", "all", "all").map((item) => item.key), ["a"]);
    assert.equal(global.BloxTemplateLibrary.purposeLabel("company-intro", { purposeCompanyIntro: "Company intro" }), "Company intro");
});

test("section catalog can isolate dynamic data templates", function () {
    const items = [
        { key: "static:hero", type: "section", metadata: { data_source: "static" } },
        { key: "dynamic:products", type: "section", metadata: { data_source: "dynamic" } },
        { key: "page:about", type: "page", metadata: { data_source: "static" } },
    ];

    assert.deepEqual(global.BloxTemplateLibrary.dataSources(items), ["dynamic", "static"]);
    assert.deepEqual(
        global.BloxTemplateLibrary.filter(items, "", "section", "all", "all", "all", "dynamic").map((item) => item.key),
        ["dynamic:products"]
    );
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

test("document fingerprints ignore generated ids but retain meaningful header changes", function () {
    const preset = {
        settings: { sticky: false },
        sections: [{
            type: "section",
            settings: { padding: "sm" },
            columns: [{ elements: [{ type: "logo", data: { source: "site" } }] }],
        }],
    };
    const applied = {
        settings: { sticky: false },
        sections: [{
            id: "s_runtime",
            type: "section",
            library_id: 12,
            settings: { padding: "sm" },
            columns: [{
                id: "c_runtime",
                elements: [{ id: "e_runtime", type: "logo", data: { source: "site" } }],
            }],
        }],
    };

    assert.equal(
        global.BloxTemplateLibrary.documentFingerprint(preset),
        global.BloxTemplateLibrary.documentFingerprint(applied)
    );
    applied.sections[0].settings.padding = "lg";
    assert.notEqual(
        global.BloxTemplateLibrary.documentFingerprint(preset),
        global.BloxTemplateLibrary.documentFingerprint(applied)
    );
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
    assert.equal(old.variant, "standard");
    assert.equal(old.data_source, "static");
    assert.deepEqual(old.states, []);
    const dynamic = global.BloxTemplateLibrary.normalizeMetadata({
        variant: "dynamic",
        data_source: "dynamic",
        states: ["empty", "error", "loading", "unknown"],
    });
    assert.equal(dynamic.variant, "dynamic");
    assert.equal(dynamic.data_source, "dynamic");
    assert.deepEqual(dynamic.states, ["empty", "error", "loading"]);
});

test("canvas prepare posts prepare_insert and surfaces the server review id", async function () {
    const originalFetch = global.fetch;
    const bodies = [];
    global.fetch = function (url, options) {
        bodies.push({ url, body: options && options.body });
        return Promise.resolve({
            ok: true,
            status: 200,
            text: function () {
                return Promise.resolve(JSON.stringify({
                    code: 0,
                    data: {
                        template: { key: "remote:pricing", name: "Pricing", requirements: { design_tokens: ["remote"] } },
                        review_id: "abc123",
                        design_diagnostics: { missing_tokens: ["remote"] },
                    },
                }));
            },
        });
    };
    try {
        const data = await global.BloxTemplateLibrary.prepareInsert(
            "/admin/blox_template_api.php", "page", "remote:pricing", "failed", "csrf-token"
        );
        assert.equal(bodies.length, 1);
        assert.equal(String(bodies[0].body.get("action")), "prepare_insert");
        assert.equal(String(bodies[0].body.get("key")), "remote:pricing");
        assert.equal(String(bodies[0].body.get("_token")), "csrf-token");
        assert.equal(data.review_id, "abc123");
        assert.deepEqual(data.design_diagnostics.missing_tokens, ["remote"]);
        assert.deepEqual(data.template.requirements.design_tokens, ["remote"]);
    } finally {
        global.fetch = originalFetch;
    }
});

test("canvas confirm posts structured mappings and returns regenerated sections", async function () {
    const originalFetch = global.fetch;
    const bodies = [];
    global.fetch = function (url, options) {
        bodies.push({ url, body: options && options.body });
        return Promise.resolve({
            ok: true,
            status: 200,
            text: function () {
                return Promise.resolve(JSON.stringify({
                    code: 0,
                    data: { template: { key: "remote:pricing", type: "section", sections: [{ type: "section" }] } },
                }));
            },
        });
    };
    try {
        const template = await global.BloxTemplateLibrary.confirmInsert(
            "/admin/blox_template_api.php", "page", "remote:pricing", "abc123",
            { style_mode: "detach", tokens: { remote: "primary", ghost: "" }, styles: {} },
            "failed", "csrf-token"
        );
        assert.equal(bodies.length, 1);
        const body = bodies[0].body;
        assert.equal(String(body.get("action")), "confirm_insert");
        assert.equal(String(body.get("style_mode")), "detach");
        // 空映射不提交；结构化键按 design_tokens[from]=to 序列化，服务端读回数组。
        assert.equal(String(body.get("design_tokens[remote]")), "primary");
        assert.equal(body.get("design_tokens[ghost]"), null);
        assert.equal(template.sections.length, 1);
    } finally {
        global.fetch = originalFetch;
    }
});

test("canvas confirm rejects responses without sections", async function () {
    const originalFetch = global.fetch;
    global.fetch = function () {
        return Promise.resolve({
            ok: true,
            status: 200,
            text: function () { return Promise.resolve(JSON.stringify({ code: 0, data: { template: {} } })); },
        });
    };
    try {
        await assert.rejects(
            global.BloxTemplateLibrary.confirmInsert(
                "/admin/blox_template_api.php", "page", "remote:pricing", "abc123", {}, "failed", "csrf-token"
            ),
            /failed/
        );
    } finally {
        global.fetch = originalFetch;
    }
});

test("premium section refusals get a specific label instead of a generic licence prompt", function () {
    const text = {
        lockedLicense: "Licence required",
        lockedExpired: "Renew",
        lockedModule: "Module missing",
        lockedDomain: "Domain mismatch",
        lockedDisabled: "Disabled",
    };
    const locked = (reason) => ({ key: "remote:hero-split", source: "remote", locked: true, locked_reason: reason });
    assert.equal(global.BloxTemplateLibrary.lockLabel(locked("license_expired"), text), "Renew");
    assert.equal(global.BloxTemplateLibrary.lockLabel(locked("domain_mismatch"), text), "Domain mismatch");
    assert.equal(global.BloxTemplateLibrary.lockLabel(locked("disabled"), text), "Disabled");
    assert.equal(global.BloxTemplateLibrary.lockLabel(locked("module_missing"), text), "Module missing");
    assert.equal(global.BloxTemplateLibrary.lockLabel(locked("license_required"), text), "Licence required");
    // 未锁定的条目不显示任何锁定文案
    assert.equal(global.BloxTemplateLibrary.lockLabel({ key: "remote:hero-split", locked: false, locked_reason: "" }, text), "");
});

test("premium entry shows one notice that matches the entitlement, and none for entitled users", function () {
    const lib = global.BloxTemplateLibrary;
    const paid = (reason, locked = true) => ({ key: "remote:" + reason, source: "remote", paid: true, locked, locked_reason: locked ? reason : "" });

    // 有权益：零提示、零锁
    assert.equal(lib.premiumNotice([paid("", false), paid("", false)], "", true), null);
    // 无授权码 → 查看专业授权；已填授权码但未生效 → 去后台授权
    assert.deepEqual({ ...lib.premiumNotice([paid("license_required"), paid("license_required")], "", false) }, { state: "purchase" });
    assert.deepEqual({ ...lib.premiumNotice([paid("license_required")], "", true) }, { state: "activate" });
    assert.deepEqual({ ...lib.premiumNotice([paid("license_expired")], "", true) }, { state: "renew" });
    assert.deepEqual({ ...lib.premiumNotice([paid("domain_mismatch")], "", true) }, { state: "domain" });
    assert.deepEqual({ ...lib.premiumNotice([paid("disabled")], "", true) }, { state: "disabled" });
    // 网络/服务失败优先提示重试
    assert.deepEqual({ ...lib.premiumNotice([paid("license_required")], "offline", false) }, { state: "error" });
    // 原因不一致、或部分可用 → 逐卡说明
    assert.deepEqual({ ...lib.premiumNotice([paid("license_expired"), paid("rate_limited")], "", true) }, { state: "mixed" });
    assert.deepEqual({ ...lib.premiumNotice([paid("rate_limited"), paid("", false)], "", true) }, { state: "mixed" });
    // 社区免费条目不参与判定
    assert.equal(lib.premiumNotice([{ key: "remote:c", source: "remote", paid: false, locked: false }], "", false), null);
});

test("cards do not repeat a lock the notice already explains, and premium badges only mark mixed lists", function () {
    const lib = global.BloxTemplateLibrary;
    const locked = { key: "remote:a", source: "remote", paid: true, locked: true, locked_reason: "license_expired" };
    assert.equal(lib.showCardLock(locked, { state: "renew" }), false);
    assert.equal(lib.showCardLock(locked, { state: "mixed" }), true);
    assert.equal(lib.showCardLock(locked, null), true);
    assert.equal(lib.showCardLock({ key: "remote:b", locked: false }, null), false);

    const premiumOnly = [locked, { ...locked, key: "remote:b" }];
    assert.equal(lib.showPremiumBadge(locked, premiumOnly), false, "全是精品时不挂徽标");
    const mixed = [locked, { key: "remote:free", source: "remote", paid: false }];
    assert.equal(lib.showPremiumBadge(locked, mixed), true, "与免费混排时才用徽标区分");
    assert.equal(lib.showPremiumBadge(mixed[1], mixed), false);
});

test("content language travels with list, get and insert requests only when set", async function () {
    const library = global.BloxTemplateLibrary;
    const originalFetch = global.fetch;
    const calls = [];
    global.fetch = function (url, options) {
        calls.push({ url: String(url), body: options && options.body ? String(options.body) : "" });
        const data = options && options.method === "POST"
            ? { template: { key: "builtin:contact-connect", sections: [] }, review_id: "r1" }
            : { items: [] };
        return Promise.resolve({ ok: true, status: 200, text: function () { return Promise.resolve(JSON.stringify({ code: 0, data: data })); } });
    };
    try {
        await library.list("/api", "page", "fail");
        assert.equal(calls[0].url.includes("lang="), false, "no language by default");

        library.setContentLanguage("ja");
        await library.list("/api", "page", "fail");
        await library.resolve("/api", "page", "builtin:contact-connect", "fail", "t");
        await library.prepareInsert("/api", "page", "builtin:contact-connect", "fail", "t");
        await library.confirmInsert("/api", "page", "builtin:contact-connect", "r1", {}, "fail", "t");
        assert.match(calls[1].url, /[?&]lang=ja(&|$)/);
        for (const call of calls.slice(2)) {
            assert.equal(new URLSearchParams(call.body).get("lang"), "ja");
        }
    } finally {
        library.setContentLanguage("");
        global.fetch = originalFetch;
    }
});

test("home-common category collects flagged built-in sections and home-ready remote sections", function () {
    const library = window.BloxTemplateLibrary;
    const items = [
        { key: "builtin:a", type: "section", source: "builtin", category: "social", home_common: true, metadata: { page_types: ["home"] } },
        { key: "builtin:b", type: "section", source: "builtin", category: "content", metadata: { page_types: ["home"] } },
        { key: "remote:c", type: "section", source: "remote", category: "products", metadata: { page_types: ["home", "landing"] } },
        { key: "remote:d", type: "section", source: "remote", category: "products", metadata: { page_types: ["about"] } },
        { key: "builtin:page", type: "page", source: "builtin", category: "page", home_common: true, metadata: {} },
    ];
    assert.deepEqual(library.categories(items), ["home-common", "content", "page", "products", "social"]);
    assert.deepEqual(library.filter(items, "", "all", "all", "home-common", "all", "all").map(function (item) { return item.key; }), ["builtin:a", "remote:c"]);
    assert.equal(library.categoryLabel("home-common", { categoryHomeCommon: "Homepage essentials" }), "Homepage essentials");
    assert.equal(library.categoryLabel("social", { categorySocial: "Customers" }), "Customers");
});
