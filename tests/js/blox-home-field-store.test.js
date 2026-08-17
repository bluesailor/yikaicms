"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const store = require("../../assets/js/blox-home-field-store.js");

test("custom locale overrides survive JSON serialization and remain sparse", () => {
    const element = { data: { block_type: "custom:1" } };
    const path = "custom_overrides.zh_CN.0.columns.1.elements.2.data.text";

    assert.equal(store.setValue(element, path, "专业版", {}), true);
    const serialized = JSON.parse(JSON.stringify(element));

    assert.equal(Array.isArray(serialized.data.custom_overrides), false);
    assert.equal(serialized.data.custom_overrides.zh_CN[0].columns[1].elements[2].data.text, "专业版");
    assert.equal(serialized.data.custom_overrides.zh_CN[0].columns[0], null);
});

test("existing repeated field arrays still clone their seed values", () => {
    const element = { data: { block_type: "stats" } };
    const seeds = { stats_items: [{ number: "10+" }, { number: "20+" }] };

    store.setValue(element, "stats_items.1.number", "99+", seeds);

    assert.deepEqual(element.data.stats_items, [{ number: "10+" }, { number: "99+" }]);
    assert.deepEqual(seeds.stats_items, [{ number: "10+" }, { number: "20+" }]);
});

test("prototype paths are rejected", () => {
    const element = { data: {} };

    assert.equal(store.setValue(element, "custom_overrides.__proto__.polluted", "yes", {}), false);
    assert.equal({}.polluted, undefined);
});

test("deleteValue prunes a single override back to inheritance", () => {
    const element = { data: { block_type: "custom:1" } };
    const path = "custom_overrides.zh_CN.0.columns.1.elements.2.data.text";
    store.setValue(element, path, "专业版", {});

    assert.equal(store.deleteValue(element, path), true);
    assert.equal(Object.hasOwn(element.data, "custom_overrides"), false);
    assert.equal(store.deleteValue(element, path), false);
});

test("FAQ items merge sparse edits until structural customization takes over", () => {
    const seeds = [
        { question: "Q1", answer: "A1" },
        { question: "Q2", answer: "A2" },
    ];
    const sparse = [null, { answer: "Edited A2" }];

    assert.deepEqual(store.faqItems(seeds, sparse, false, 30), [
        { question: "Q1", answer: "A1" },
        { question: "Q2", answer: "Edited A2" },
    ]);
    assert.deepEqual(store.faqItems(seeds, [{ question: "New", answer: "Only" }], true, 30), [
        { question: "New", answer: "Only" },
    ]);
    assert.deepEqual(seeds[1], { question: "Q2", answer: "A2" });
});

test("standard accordion reads the legacy storage format", () => {
    const parsed = store.parseAccordionItems("Question one|Answer one\r\n|Draft answer\nQuestion two", 30);

    assert.deepEqual(parsed, [
        { question: "Question one", answer: "Answer one" },
        { question: "", answer: "Draft answer" },
        { question: "Question two", answer: "" },
    ]);
});

test("standard accordion accepts the structured format used by new installs", () => {
    const source = [
        { question: "Structured question", answer: "Structured answer" },
        { question: "Second question", answer: "Second answer" },
    ];

    assert.deepEqual(store.parseAccordionItems(source, 30), source);
    assert.notEqual(store.parseAccordionItems(source, 30), source);
});

test("standard accordion parsing enforces item limits", () => {
    const items = Array.from({ length: 35 }, (_, index) => ({
        question: `<b>Q${index}</b>|part`,
        answer: `Line one\nLine two|${index}`,
    }));

    assert.equal(store.parseAccordionItems(items, 30).length, 30);
    assert.deepEqual(store.parseAccordionItems(items, 1)[0], items[0]);
});

test("moveItem reorders a copy and rejects invalid indexes", () => {
    const source = [{ question: "Q1" }, { question: "Q2" }, { question: "Q3" }];

    assert.deepEqual(store.moveItem(source, 0, 1), [
        { question: "Q2" }, { question: "Q1" }, { question: "Q3" },
    ]);
    assert.deepEqual(store.moveItem(source, 2, 0), [
        { question: "Q3" }, { question: "Q1" }, { question: "Q2" },
    ]);
    assert.deepEqual(store.moveItem(source, -1, 0), source);
    assert.deepEqual(store.moveItem(source, 0, 9), source);
    assert.deepEqual(source, [{ question: "Q1" }, { question: "Q2" }, { question: "Q3" }]);
});

test("structuralItems materializes sparse column edits before structural takeover", () => {
    const seeds = [{
        card_bg: "#ffffff",
        elements: [
            { type: "heading", data: { text: "Basic", level: "h3" } },
            { type: "button", data: { text: "Choose", url: "/contact.html" } },
        ],
    }, {
        elements: [{ type: "heading", data: { text: "Pro", level: "h3" } }],
    }];
    const sparse = [null, { elements: [{ data: { text: "Professional" } }] }];

    const materialized = store.structuralItems(seeds, sparse, false, 12);
    assert.equal(materialized[0].elements[0].data.text, "Basic");
    assert.equal(materialized[1].elements[0].type, "heading");
    assert.equal(materialized[1].elements[0].data.text, "Professional");
    materialized[0].elements[0].data.text = "Changed";
    assert.equal(seeds[0].elements[0].data.text, "Basic");

    assert.deepEqual(store.structuralItems(seeds, [seeds[1]], true, 12), [seeds[1]]);
});
