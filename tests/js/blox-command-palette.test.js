"use strict";

const test = require("node:test");
const assert = require("node:assert/strict");
const palette = require("../../assets/js/blox-command-palette.js");

test("ranking prefers contiguous matches, then all words, then in-order letters", () => {
    const items = [
        { label: "插入：按钮" },
        { label: "Save draft" },
        { label: "Preview on tablet", keywords: "device" },
        { label: "Publish page" },
        { label: "Undo" },
    ];
    assert.deepEqual(palette.rank(items, "pub").map((i) => i.label), ["Publish page"]);
    assert.equal(palette.rank(items, "tablet preview")[0].label, "Preview on tablet");
    assert.equal(palette.rank(items, "device")[0].label, "Preview on tablet", "keywords count");
    assert.equal(palette.rank(items, "svdr")[0].label, "Save draft", "scattered letters still match");
    assert.equal(palette.rank(items, "按钮")[0].label, "插入：按钮");
    assert.deepEqual(palette.rank(items, "zzz"), []);
    assert.equal(palette.rank(items, "").length, 5, "empty query keeps everything in order");
    assert.equal(palette.rank(items, "", 2).length, 2);
});

test("page clipboard round-trips only its own format", () => {
    const sections = [{ id: "s1", columns: [{ elements: [{ id: "e1", type: "heading", data: { text: "Hi" } }] }] }];
    assert.deepEqual(palette.parsePage(palette.serializePage(sections)), sections);
    assert.equal(palette.parsePage("not json"), null);
    assert.equal(palette.parsePage(JSON.stringify({ sections })), null, "missing format marker");
    assert.equal(palette.parsePage(JSON.stringify({ format: palette.PAGE_FORMAT, sections: [] })), null);
    assert.equal(palette.parsePage(JSON.stringify({ format: palette.PAGE_FORMAT, sections: [{ columns: [{}] }] })), null);
});

test("find and replace changes visible text only, never markup, links or settings", () => {
    assert.deepEqual(palette.replaceText("Acme and acme and Acme", "Acme", "Yikai"), { value: "Yikai and acme and Yikai", count: 2 });
    assert.deepEqual(
        palette.replaceText('<p class="Acme"><a href="/Acme">Acme</a> Acme</p>', "Acme", "Yikai"),
        { value: '<p class="Acme"><a href="/Acme">Yikai</a> Yikai</p>', count: 2 }
    );
    const data = {
        text: "Acme bearings", url: "/acme", bg_image: "/Acme.png", icon: "acme", color: "Acme",
        items: [{ question: "Why Acme?", answer: "<p>Acme</p>", avatar: "/Acme.jpg" }],
        children: [{ id: "c1", type: "text", data: { html: "<b>Acme</b>", link_url: "/Acme" } }],
        _global_style: "Acme",
        size: "Acme", tabs_style: "Acme",
    };
    assert.equal(palette.replaceInData(JSON.parse(JSON.stringify(data)), "Acme", "Yikai", true), 4, "dry run counts");
    const copy = JSON.parse(JSON.stringify(data));
    assert.equal(palette.replaceInData(copy, "Acme", "Yikai", false), 4);
    assert.equal(copy.text, "Yikai bearings");
    assert.equal(copy.items[0].question, "Why Yikai?");
    assert.equal(copy.items[0].answer, "<p>Yikai</p>");
    assert.equal(copy.children[0].data.html, "<b>Yikai</b>");
    assert.equal(copy.items[0].avatar, "/Acme.jpg");
    assert.equal(copy.children[0].data.link_url, "/Acme");
    assert.equal(copy.bg_image, "/Acme.png");
    assert.equal(copy._global_style, "Acme");
    assert.equal(copy.color, "Acme");
    assert.equal(copy.size, "Acme", "setting values are never rewritten");
    assert.equal(copy.tabs_style, "Acme");
});
