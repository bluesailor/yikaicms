const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
require("../../assets/js/admin-error-report.js");
const { shouldReport, createReporter, MAX_PER_PAGE } = global.YikaiErrorReport;
const origin = "https://example.test";

test("only same-origin script errors are worth reporting", function () {
    assert.equal(shouldReport("Cannot read properties of null (reading 'settings')", origin + "/assets/alpinejs/alpine.min.js", origin), true);
    assert.equal(shouldReport("Boom", "", origin), true, "unhandled rejections have no source");
    assert.equal(shouldReport("Script error.", "", origin), false, "cross-origin errors carry no detail");
    assert.equal(shouldReport("ResizeObserver loop completed with undelivered notifications.", origin + "/x.js", origin), false);
    assert.equal(shouldReport("Boom", "https://cdn.other.test/lib.js", origin), false);
    assert.equal(shouldReport("", origin + "/x.js", origin), false);
});

test("the same error is sent once and a page sends at most the cap", function () {
    const sent = [];
    const report = createReporter({ token: "t", page: "blox-editor /admin/blox_editor.php?id=2" }, (fields) => sent.push(fields), origin);
    const alpine = Object.assign(new TypeError("Cannot read properties of null (reading 'settings')"), { expression: "sel.settings.title" });
    for (let i = 0; i < 114; i++) report(alpine.message, origin + "/assets/alpinejs/alpine.min.js", 5, 10, alpine);
    assert.equal(sent.length, 1, "114 identical Alpine errors become one report");
    assert.equal(sent[0].expression, "sel.settings.title");
    assert.equal(sent[0].page, "blox-editor /admin/blox_editor.php?id=2");
    assert.equal(sent[0]._token, "t");

    for (let i = 0; i < 30; i++) report("Error " + i, origin + "/a.js", i, 0, null);
    assert.equal(sent.length, MAX_PER_PAGE);
});

test("long fields are clipped before sending", function () {
    const sent = [];
    const report = createReporter({ token: "t", page: "" }, (fields) => sent.push(fields), origin);
    report("x".repeat(2000), "", 0, 0, { stack: "a\nb\nc\nd\ne\nf", expression: "y".repeat(1000) });
    assert.equal(sent[0].message.length, 500);
    assert.equal(sent[0].expression.length, 300);
    assert.equal(sent[0].stack, "a\nb\nc\nd");
});
