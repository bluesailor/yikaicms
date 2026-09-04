const assert = require("node:assert/strict");
const test = require("node:test");

const Actions = require("../../assets/js/blox-multi-actions.js");

let seq = 0;
const newId = () => "n_" + (++seq);

function item(id, extra) {
    return Object.assign({ id }, extra || {});
}

test("removeByIds rebuilds the list and reports removed in document order", () => {
    const list = [item("a"), item("b"), item("c"), item("d")];
    const r = Actions.removeByIds(list, ["b", "d"]);
    assert.deepEqual(r.list.map((i) => i.id), ["a", "c"]);
    assert.deepEqual(r.removed.map((i) => i.id), ["b", "d"]);
    assert.deepEqual(r.missing, []);
});

test("removeByIds reports missing ids instead of failing", () => {
    const r = Actions.removeByIds([item("a")], ["a", "ghost"]);
    assert.deepEqual(r.list, []);
    assert.deepEqual(r.missing, ["ghost"]);
});

test("removeByIds never mutates the input list", () => {
    const list = [item("a"), item("b")];
    Actions.removeByIds(list, ["a"]);
    assert.deepEqual(list.map((i) => i.id), ["a", "b"]);
});

test("pickByIds returns the document-ordered subset", () => {
    const r = Actions.pickByIds([item("a"), item("b"), item("c")], ["c", "a"]);
    assert.deepEqual(r.items.map((i) => i.id), ["a", "c"]);
    assert.deepEqual(r.missing, []);
});

test("duplicateByIds inserts clones after the last set item in document order", () => {
    seq = 0;
    const list = [item("a"), item("b"), item("c")];
    const r = Actions.duplicateByIds(list, ["a", "c"], newId);
    assert.deepEqual(r.list.map((i) => i.id), ["a", "b", "c", "n_1", "n_2"]);
    // 副本顺序 = 集合的文档顺序（a 在 c 前）
    assert.deepEqual(r.newIds, ["n_1", "n_2"]);
    assert.deepEqual(r.list[3], { id: "n_1" }); // a 的副本
    assert.deepEqual(r.list[4], { id: "n_2" }); // c 的副本
});

test("duplicateByIds clones nested children with fresh ids too", () => {
    seq = 0;
    const host = { id: "host", data: { children: [{ id: "ch_1", data: {} }] } };
    const r = Actions.duplicateByIds([host], ["host"], newId);
    assert.equal(r.list.length, 2);
    const twin = r.list[1];
    assert.notEqual(twin.id, "host");
    assert.notEqual(twin.data.children[0].id, "ch_1");
    assert.deepEqual(r.newIds, [twin.id]);
});

test("appendCloned appends in clipboard order with fresh ids", () => {
    seq = 0;
    const r = Actions.appendCloned([item("keep")], [item("x"), item("y")], newId);
    assert.deepEqual(r.list.map((i) => i.id), ["keep", "n_1", "n_2"]);
    assert.deepEqual(r.newIds, ["n_1", "n_2"]);
});

test("determinism: identical input yields identical output", () => {
    seq = 5;
    const a = Actions.duplicateByIds([item("a"), item("b")], ["b"], newId);
    seq = 5;
    const b = Actions.duplicateByIds([item("a"), item("b")], ["b"], newId);
    assert.deepEqual(a, b);
});

test("scopeContext resolves section/element/child lists by stable id", () => {
    const sections = [
        { id: "s1", columns: [{ id: "c1", elements: [{ id: "e1" }] }, { id: "c2", elements: [] }] },
        { id: "s2", columns: [{ id: "c3", elements: [{ id: "host", data: { children: [{ id: "k1" }] } }] }] },
    ];
    const root = Actions.scopeContext(sections, "section", "root");
    assert.equal(root.list, sections);
    const element = Actions.scopeContext(sections, "element", "s1/c2");
    assert.equal(element.list, sections[0].columns[1].elements);
    const child = Actions.scopeContext(sections, "child", "children:host");
    assert.equal(child.list, sections[1].columns[0].elements[0].data.children);
});

test("scopeContext returns null for dangling scopes; empty root stays valid for paste", () => {
    assert.equal(Actions.scopeContext([{ id: "s1", columns: [] }], "element", "s_gone/c_gone"), null);
    // 空文档的根作用域合法：区块剪贴板可粘进空文档
    const emptyRoot = Actions.scopeContext([], "section", "root");
    assert.deepEqual(emptyRoot, { level: "section", list: [] });
    assert.equal(Actions.scopeContext([{ id: "s1", columns: [] }], "child", "children:gone"), null);
    assert.equal(Actions.scopeContext([{ id: "s1", columns: [] }], "weird", "x"), null);
});
