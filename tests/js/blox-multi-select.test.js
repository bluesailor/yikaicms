const assert = require("node:assert/strict");
const test = require("node:test");

const Multi = require("../../assets/js/blox-multi-select.js");

// 三个兄弟元素（同列）与两个区块，按文档顺序。
const COL = ["e_a", "e_b", "e_c", "e_d"];
const ROOT = ["s_1", "s_2", "s_3"];

function click(state, over) {
    return Multi.applyClick(state, over);
}

test("plain click collapses the active set but keeps a dormant anchor", () => {
    var r1 = click(null, { mode: "plain", level: "element", parent: "s1/c1", id: "e_a", siblings: COL });
    assert.equal(r1.active, false);
    assert.deepEqual(r1.state.ids, ["e_a"]);
    assert.equal(r1.state.anchor, "e_a");
    var r2 = click({ ids: ["e_a", "e_b"], level: "element", parent: "s1/c1", anchor: "e_a" },
        { mode: "plain", level: "element", parent: "s1/c1", id: "e_c", siblings: COL });
    assert.equal(r2.active, false);
    assert.deepEqual(r2.state.ids, ["e_c"]);
    // 单选视觉不受影响的关键：集合不再激活
    assert.equal(Multi.active(r2.state), false);
});

test("ctrl/cmd toggle adds, removes and collapses back to nothing", () => {
    var s = null;
    s = click(s, { mode: "toggle", level: "element", parent: "s1/c1", id: "e_a", siblings: COL });
    assert.equal(s.active, false); // 单项不算激活
    assert.deepEqual(s.state.ids, ["e_a"]);
    s = click(s.state, { mode: "toggle", level: "element", parent: "s1/c1", id: "e_c", siblings: COL });
    assert.equal(s.active, true);
    assert.deepEqual(s.state.ids, ["e_a", "e_c"]);
    s = click(s.state, { mode: "toggle", level: "element", parent: "s1/c1", id: "e_a", siblings: COL });
    assert.equal(s.active, false);
    assert.deepEqual(s.state.ids, ["e_c"]);
    s = click(s.state, { mode: "toggle", level: "element", parent: "s1/c1", id: "e_c", siblings: COL });
    assert.equal(s.state.ids.length, 0);
    assert.equal(s.changed, true);
});

test("shift click selects the document-order range from the anchor", () => {
    var s = click(null, { mode: "toggle", level: "element", parent: "s1/c1", id: "e_b", siblings: COL }).state;
    var down = click(s, { mode: "shift", level: "element", parent: "s1/c1", id: "e_d", siblings: COL });
    assert.equal(down.active, true);
    assert.deepEqual(down.state.ids, ["e_b", "e_c", "e_d"]);
    // 反向区间：锚点保持不变，区间随点击方向展开
    var up = click(s, { mode: "shift", level: "element", parent: "s1/c1", id: "e_a", siblings: COL });
    assert.deepEqual(up.state.ids, ["e_a", "e_b"]);
    assert.equal(up.state.anchor, "e_b");
    // 锚点固定：再次 shift 点中间项，区间重新从锚点计算
    var mid = click(s, { mode: "shift", level: "element", parent: "s1/c1", id: "e_c", siblings: COL });
    assert.deepEqual(mid.state.ids, ["e_b", "e_c"]);
});

test("shift click after a plain click ranges from the dormant anchor", () => {
    var anchor = click(null, { mode: "plain", level: "element", parent: "s1/c1", id: "e_a", siblings: COL }).state;
    var r = click(anchor, { mode: "shift", level: "element", parent: "s1/c1", id: "e_c", siblings: COL });
    assert.equal(r.active, true);
    assert.deepEqual(r.state.ids, ["e_a", "e_b", "e_c"]);
});

test("shift click without an anchor restarts with the clicked item", () => {
    var r = click(null, { mode: "shift", level: "element", parent: "s1/c1", id: "e_c", siblings: COL });
    assert.equal(r.active, false);
    assert.deepEqual(r.state.ids, ["e_c"]);
    assert.equal(r.state.anchor, "e_c");
});

test("cross-parent modifier clicks restart instead of merging", () => {
    var inColumn = click(null, { mode: "toggle", level: "element", parent: "s1/c1", id: "e_a", siblings: COL }).state;
    // 同一区块的另一列：父级不同 → 重新开始
    var otherColumn = click(inColumn, { mode: "toggle", level: "element", parent: "s1/c2", id: "e_a", siblings: COL });
    assert.equal(otherColumn.state.parent, "s1/c2");
    assert.deepEqual(otherColumn.state.ids, ["e_a"]);
    // 区块层与元素层互不合并
    var sectionScope = click(inColumn, { mode: "shift", level: "section", parent: "root", id: "s_2", siblings: ROOT });
    assert.equal(sectionScope.state.level, "section");
    assert.deepEqual(sectionScope.state.ids, ["s_2"]);
});

test("section multi-select works at document root and keeps its own scope", () => {
    var s = click(null, { mode: "toggle", level: "section", parent: "root", id: "s_1", siblings: ROOT }).state;
    s = click(s, { mode: "shift", level: "section", parent: "root", id: "s_3", siblings: ROOT });
    assert.equal(s.active, true);
    assert.deepEqual(s.state.ids, ["s_1", "s_2", "s_3"]);
});

test("child elements form their own scope inside one host container", () => {
    var host = ["ch_1", "ch_2"];
    var s = click(null, { mode: "toggle", level: "child", parent: "children:el_host", id: "ch_1", siblings: host }).state;
    s = click(s, { mode: "toggle", level: "child", parent: "children:el_host", id: "ch_2", siblings: host });
    assert.equal(s.active, true);
    assert.deepEqual(s.state.ids, ["ch_1", "ch_2"]);
});

test("ids outside the sibling list cannot join the set", () => {
    var r = click(null, { mode: "toggle", level: "element", parent: "s1/c1", id: "e_ghost", siblings: COL });
    assert.deepEqual(r.state.ids, ["e_ghost"]); // 退化为重新开始，不并入
    assert.equal(r.active, false);
    var bad = click(null, { mode: "shift", level: "element", parent: "s1/c1", id: "", siblings: COL });
    assert.equal(bad.state.ids.length, 0);
});

test("unknown mode is treated as a plain click", () => {
    var r = click({ ids: ["e_a", "e_b"], level: "element", parent: "s1/c1", anchor: "e_a" },
        { mode: "alt", level: "element", parent: "s1/c1", id: "e_c", siblings: COL });
    assert.equal(r.state.ids.length, 0);
});

test("same input yields identical output", () => {
    var a = click(null, { mode: "toggle", level: "element", parent: "p", id: "e_a", siblings: COL });
    var b = click(null, { mode: "toggle", level: "element", parent: "p", id: "e_a", siblings: COL });
    assert.deepEqual(a, b);
});

test("helpers: active requires two or more ids, count and has behave", () => {
    var empty = Multi.create();
    assert.equal(Multi.active(empty), false);
    assert.equal(Multi.count(empty), 0);
    assert.equal(Multi.has(empty, "e_a"), false);
    var one = Multi.applyClick(empty, { mode: "toggle", level: "element", parent: "p", id: "e_a", siblings: COL }).state;
    assert.equal(Multi.active(one), false);
    assert.equal(Multi.count(one), 1);
    assert.equal(Multi.has(one, "e_a"), true);
});
