"use strict";
const test = require("node:test");
const assert = require("node:assert/strict");
const rules = require("../../assets/js/blox-control-rules.js");

const get = (data) => (key) => data[key];

test("无规则/空 terms = 显示", () => {
    assert.equal(rules.visibleWhenMet({}, get({})), true);
    assert.equal(rules.visibleWhenMet({ visible_when: { terms: [] } }, get({})), true);
});

test("required 单条件归一兼容（= / != / 多值数组）", () => {
    const eq = { required: ["mode", "=", "message"] };
    assert.equal(rules.visibleWhenMet(eq, get({ mode: "message" })), true);
    assert.equal(rules.visibleWhenMet(eq, get({ mode: "hide" })), false);
    const ne = { required: ["mode", "!=", "hide"] };
    assert.equal(rules.visibleWhenMet(ne, get({ mode: "message" })), true);
    const multi = { required: ["mode", "=", ["a", "b"]] };
    assert.equal(rules.visibleWhenMet(multi, get({ mode: "b" })), true);
    assert.equal(rules.visibleWhenMet(multi, get({ mode: "c" })), false);
});

test("visible_when AND / OR 组合", () => {
    const both = { visible_when: { relation: "and", terms: [["a", "=", 1], ["b", "=", 2]] } };
    assert.equal(rules.visibleWhenMet(both, get({ a: 1, b: 2 })), true);
    assert.equal(rules.visibleWhenMet(both, get({ a: 1, b: 3 })), false);
    const either = { visible_when: { relation: "or", terms: [["a", "=", 1], ["b", "=", 2]] } };
    assert.equal(rules.visibleWhenMet(either, get({ a: 0, b: 2 })), true);
    assert.equal(rules.visibleWhenMet(either, get({ a: 0, b: 0 })), false);
});

test("empty / not_empty（不需要 value；checkbox false 与空串都算空）", () => {
    const ne = { visible_when: { terms: [["url", "not_empty"]] } };
    assert.equal(rules.visibleWhenMet(ne, get({ url: "https://a.com" })), true);
    assert.equal(rules.visibleWhenMet(ne, get({ url: "" })), false);
    assert.equal(rules.visibleWhenMet(ne, get({})), false);
    const em = { visible_when: { terms: [["icon", "empty"]] } };
    assert.equal(rules.visibleWhenMet(em, get({ icon: false })), true);
    assert.equal(rules.visibleWhenMet(em, get({ icon: [] })), true);
    assert.equal(rules.visibleWhenMet(em, get({ icon: "star" })), false);
});

test("in / not_in 与数值比较 > <", () => {
    const inn = { visible_when: { terms: [["kind", "in", ["a", "b"]]] } };
    assert.equal(rules.visibleWhenMet(inn, get({ kind: "a" })), true);
    assert.equal(rules.visibleWhenMet(inn, get({ kind: "z" })), false);
    const nin = { visible_when: { terms: [["kind", "not_in", ["a"]]] } };
    assert.equal(rules.visibleWhenMet(nin, get({ kind: "z" })), true);
    const gt = { visible_when: { terms: [["n", ">", 3]] } };
    assert.equal(rules.visibleWhenMet(gt, get({ n: 5 })), true);
    assert.equal(rules.visibleWhenMet(gt, get({ n: 2 })), false);
    assert.equal(rules.visibleWhenMet(gt, get({ n: "abc" })), false, "非数值比较 fail-closed");
});

test("未知操作符 fail-closed（隐藏而非显示失效控件）", () => {
    const bad = { visible_when: { terms: [["a", "regex", ".*"]] } };
    assert.equal(rules.visibleWhenMet(bad, get({ a: "x" })), false);
});
