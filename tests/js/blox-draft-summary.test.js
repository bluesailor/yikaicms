const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
require("../../assets/js/blox-draft-summary.js");

function section(id, text, options = {}) {
    return {
        id,
        name: options.name || "",
        settings: { padding: options.padding || "md" },
        columns: [{
            id: `c-${id}`,
            span: options.span || 12,
            elements: [{
                id: `e-${id}`,
                type: "heading",
                data: { text, color: options.color || "#111827" },
            }],
        }],
    };
}

function document(sections, settings = {}) {
    return { schema: 1, settings, sections };
}

test("reports added and removed sections without false moves", function () {
    const before = document([section("a", "A"), section("b", "B"), section("c", "C")]);
    const after = document([section("b", "B"), section("c", "C"), section("d", "D")]);
    const summary = BloxDraftSummary.summarize(before, after);

    assert.equal(summary.totals.added, 1);
    assert.equal(summary.totals.removed, 1);
    assert.equal(summary.totals.moved, 0);
    assert.deepEqual(summary.items.map((item) => item.id), ["d", "a"]);
});

test("uses common-order LCS so one drag is one moved section", function () {
    const before = document([section("a", "A"), section("b", "B"), section("c", "C")]);
    const after = document([section("c", "C"), section("a", "A"), section("b", "B")]);
    const summary = BloxDraftSummary.summarize(before, after);

    assert.equal(summary.totals.moved, 1);
    assert.equal(summary.items.find((item) => item.moved).id, "c");
});

test("separates content, style, and document setting changes", function () {
    const before = document([section("a", "Original"), section("b", "B")], { sticky: false });
    const after = document([
        section("a", "Updated"),
        section("b", "B", { padding: "lg", color: "#2563eb" }),
    ], { sticky: true });
    const summary = BloxDraftSummary.summarize(before, after);

    assert.equal(summary.totals.content, 1);
    assert.equal(summary.totals.style, 1);
    assert.equal(summary.totals.settings, 1);
    assert.equal(summary.items.find((item) => item.id === "a").content, true);
    assert.equal(summary.items.find((item) => item.id === "a").style, false);
    assert.equal(summary.items.find((item) => item.id === "b").style, true);
});

test("accepts JSON strings and fails closed for malformed input", function () {
    const current = JSON.stringify(document([section("a", "A", { name: "Named section" })]));
    const summary = BloxDraftSummary.summarize("not-json", current);

    assert.equal(summary.changed, true);
    assert.equal(summary.total, 1);
    assert.equal(summary.items[0].label, "Named section");
    assert.equal(summary.items[0].canLocate, true);
});

test("returns an empty summary for equivalent key order variants", function () {
    const left = { schema: 1, settings: { a: 1, b: 2 }, sections: [section("a", "A")] };
    const right = { settings: { b: 2, a: 1 }, sections: [section("a", "A")], schema: 1 };
    const summary = BloxDraftSummary.summarize(left, right);

    assert.equal(summary.changed, false);
    assert.equal(summary.total, 0);
    assert.equal(BloxDraftSummary.summarize({ settings: [], sections: [] }, { settings: {}, sections: [] }).changed, false);
});
