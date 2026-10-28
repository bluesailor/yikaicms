const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
require("../../assets/js/yikay-history-store.js");

function fixture(options = {}) {
    let data = options.data || '[{"id":"s1","title":"A"}]';
    let settings = options.settings;
    let structure = options.structure || '[{"id":"s1"}]';
    let selection = options.selection || { selectedSi: 0 };
    let applying = false;
    const store = new global.BloxHistoryStore({
        limit: options.limit || 51,
        maxBytes: options.maxBytes,
        delay: options.delay === undefined ? 10 : options.delay,
        getData: function () { return data; },
        getSettings: settings === undefined ? undefined : function () { return settings; },
        getStructure: function () { return structure; },
        getSelection: function () { return { ...selection }; },
        isApplying: function () { return applying; },
    });
    return {
        store,
        setData: function (value) { data = value; },
        setSettings: function (value) { settings = value; },
        setStructure: function (value) { structure = value; },
        setSelection: function (value) { selection = value; },
        setApplying: function (value) { applying = value; },
    };
}

test("initializes with one bounded snapshot", function () {
    const subject = fixture();
    const initial = subject.store.init();
    assert.equal(subject.store.entries.length, 1);
    assert.equal(subject.store.index, 0);
    assert.equal(initial.data, '[{"id":"s1","title":"A"}]');
    assert.deepEqual(initial.selection, { selectedSi: 0 });
});

test("merges rapid property changes and keeps the latest selection", async function () {
    const subject = fixture({ delay: 5 });
    subject.store.init();
    subject.setData('[{"id":"s1","title":"B"}]');
    subject.store.queue();
    subject.setData('[{"id":"s1","title":"C"}]');
    subject.setSelection({ selectedSi: 0, selectedEi: 2 });
    subject.store.queue();

    assert.equal(subject.store.entries.length, 1);
    assert.equal(subject.store.canUndo(), true);
    await new Promise(function (resolve) { setTimeout(resolve, 15); });
    assert.equal(subject.store.entries.length, 2);
    assert.equal(subject.store.entries[1].data, '[{"id":"s1","title":"C"}]');
    assert.deepEqual(subject.store.entries[1].selection, { selectedSi: 0, selectedEi: 2 });
});

test("records structural changes immediately", function () {
    const subject = fixture();
    subject.store.init();
    subject.setData('[{"id":"s1"},{"id":"s2"}]');
    subject.setStructure('[{"id":"s1"},{"id":"s2"}]');
    subject.store.queue();
    assert.equal(subject.store.entries.length, 2);
    assert.equal(subject.store.pending, null);
});

test("tracks settings-only changes through undo and redo", function () {
    const subject = fixture({ settings: '{"sticky":false}' });
    const initial = subject.store.init();
    assert.equal(initial.settings, '{"sticky":false}');

    subject.setSettings('{"sticky":true}');
    subject.store.queue();
    assert.equal(subject.store.canUndo(), true);
    assert.equal(subject.store.undo().settings, '{"sticky":false}');
    assert.equal(subject.store.redo().settings, '{"sticky":true}');
});

test("undo and redo traverse snapshots and truncate a replaced branch", function () {
    const subject = fixture();
    subject.store.init();

    subject.setData('[{"id":"s1","title":"B"}]');
    subject.store.queue();
    subject.store.flush(true);
    assert.equal(subject.store.undo().data, '[{"id":"s1","title":"A"}]');
    assert.equal(subject.store.canRedo(), true);
    assert.equal(subject.store.redo().data, '[{"id":"s1","title":"B"}]');

    assert.equal(subject.store.undo().data, '[{"id":"s1","title":"A"}]');
    subject.setData('[{"id":"s1","title":"D"}]');
    subject.store.queue();
    subject.store.flush(true);
    assert.equal(subject.store.canRedo(), false);
    assert.deepEqual(subject.store.entries.map(function (entry) { return entry.data; }), [
        '[{"id":"s1","title":"A"}]',
        '[{"id":"s1","title":"D"}]',
    ]);
});

test("enforces the limit and ignores changes while applying a snapshot", function () {
    const subject = fixture({ limit: 3 });
    subject.store.init();
    subject.setApplying(true);
    subject.setData('[{"id":"s1","title":"ignored"}]');
    subject.store.queue();
    assert.equal(subject.store.entries.length, 1);

    subject.setApplying(false);
    for (const title of ["B", "C", "D"]) {
        subject.setData('[{"id":"s1","title":"' + title + '"}]');
        subject.setStructure('[{"id":"s1","version":"' + title + '"}]');
        subject.store.queue();
    }
    assert.equal(subject.store.entries.length, 3);
    assert.equal(subject.store.entries[0].data, '[{"id":"s1","title":"B"}]');
    assert.equal(subject.store.index, 2);
});

function applySnapshot(subject, snapshot) {
    assert.ok(snapshot);
    subject.setData(snapshot.data);
    subject.setSettings(snapshot.settings);
    subject.setStructure(snapshot.structure);
    subject.setSelection(snapshot.selection);
    return snapshot;
}

function bytes(snapshot) {
    return [snapshot.data, snapshot.settings, snapshot.structure]
        .reduce((sum, value) => sum + Buffer.byteLength(value || "", "utf8"), 0);
}

test("evicts oldest snapshots by UTF-8 bytes including settings and structure", function () {
    const subject = fixture({ data: "文😀A", settings: "設定", structure: "構造", maxBytes: 40 });
    subject.store.init();
    assert.equal(subject.store.totalBytes, 20);
    for (const suffix of ["B", "C"]) {
        subject.setData("文😀" + suffix);
        subject.store.queue();
        subject.store.flush(false);
    }
    assert.deepEqual(subject.store.entries.map(entry => entry.data), ["文😀B", "文😀C"]);
    assert.equal(subject.store.totalBytes, 40);
    assert.equal(applySnapshot(subject, subject.store.undo()).data, "文😀B");
    assert.equal(subject.store.canUndo(), false);
    assert.equal(subject.store.undo(), null);
    assert.equal(applySnapshot(subject, subject.store.redo()).data, "文😀C");
    assert.equal(subject.store.canRedo(), false);
});

test("branching after undo releases redo bytes before trimming retained history", function () {
    const subject = fixture({ data: "A", structure: "S", maxBytes: 6 });
    subject.store.init();
    for (const value of ["B", "C"]) {
        subject.setData(value);
        subject.store.queue();
        subject.store.flush(false);
    }
    applySnapshot(subject, subject.store.undo());
    applySnapshot(subject, subject.store.undo());
    subject.setData("D");
    subject.store.queue();
    subject.store.flush(false);
    assert.deepEqual(subject.store.entries.map(entry => entry.data), ["A", "D"]);
    assert.equal(subject.store.totalBytes, 4);
    assert.equal(subject.store.canRedo(), false);
    assert.equal(applySnapshot(subject, subject.store.undo()).data, "A");
    assert.equal(applySnapshot(subject, subject.store.redo()).data, "D");
});

test("continuous input coalesces under a byte cap and undo flushes the latest input", async function () {
    const subject = fixture({ data: "A", structure: "S", maxBytes: 12, delay: 20 });
    subject.store.init();
    subject.setData("B");
    subject.store.queue();
    subject.store.flush(false);

    let measurements = 0;
    const measure = subject.store.snapshotBytes.bind(subject.store);
    subject.store.snapshotBytes = snapshot => { measurements++; return measure(snapshot); };
    for (const value of ["C", "CD", "CDE"]) {
        subject.setData(value);
        subject.store.queue();
    }
    assert.equal(measurements, 0, "Typing must not re-encode the document for each keystroke");
    assert.equal(subject.store.entries.length, 2);
    assert.equal(applySnapshot(subject, subject.store.undo()).data, "B");
    assert.equal(measurements, 1);
    assert.equal(applySnapshot(subject, subject.store.redo()).data, "CDE");
    await new Promise(resolve => setTimeout(resolve, 35));
    assert.equal(subject.store.entries.length, 3, "A flushed typing timer must not append another entry");
    assert.equal(subject.store.totalBytes, 8);

    subject.setData("CDEFGHI");
    subject.store.queue();
    await new Promise(resolve => setTimeout(resolve, 35));
    assert.deepEqual(subject.store.entries.map(entry => entry.data), ["CDE", "CDEFGHI"]);
    assert.equal(subject.store.totalBytes, 12);
    assert.equal(applySnapshot(subject, subject.store.undo()).data, "CDE");
    assert.equal(applySnapshot(subject, subject.store.redo()).data, "CDEFGHI");
});

test("settings-only edits and immediate structure edits retain their undo state under the cap", function () {
    const subject = fixture({ data: "A", settings: "0", structure: "S", maxBytes: 6 });
    subject.store.init();
    subject.setSettings("1");
    subject.store.queue();
    subject.store.flush(false);
    subject.setStructure("T");
    subject.setData("B");
    subject.store.queue();
    assert.equal(subject.store.pending, null);
    const prior = applySnapshot(subject, subject.store.undo());
    assert.equal(prior.data, "A");
    assert.equal(prior.settings, "1");
    assert.equal(prior.structure, "S");
    const latest = applySnapshot(subject, subject.store.redo());
    assert.equal(latest.data, "B");
    assert.equal(latest.settings, "1");
    assert.equal(latest.structure, "T");
    assert.equal(subject.store.totalBytes, 6);
});

test("keeps a single oversized snapshot and resets accounting on reinitialization", function () {
    const subject = fixture({ data: "A", structure: "S", maxBytes: 5 });
    subject.store.init();
    subject.setData("oversized");
    subject.store.queue();
    subject.store.flush(false);
    assert.equal(subject.store.entries.length, 1);
    assert.equal(subject.store.entries[0].data, "oversized");
    assert.equal(subject.store.totalBytes, 10);
    assert.equal(subject.store.canUndo(), false);
    subject.setData("B");
    subject.store.queue();
    subject.store.flush(false);
    assert.equal(subject.store.totalBytes, 2);
    assert.equal(subject.store.entries.length, 1);

    subject.setData("C");
    subject.store.queue();
    subject.store.init();
    assert.equal(subject.store.pending, null);
    assert.equal(subject.store.index, 0);
    assert.equal(subject.store.totalBytes, 2);
    assert.equal(subject.store.entries.length, 1);
});

test("default 4 MiB cap bounds a full 51-step large-document history", function () {
    const subject = fixture({ data: "x".repeat(220 * 1024), structure: "S" });
    subject.store.init();
    assert.equal(subject.store.maxBytes, 4 * 1024 * 1024);
    for (let step = 1; step <= 51; step++) {
        subject.setData("x".repeat(220 * 1024) + step);
        subject.store.queue();
        subject.store.flush(false);
    }
    assert.ok(subject.store.entries.length < 51);
    assert.ok(subject.store.totalBytes <= 4 * 1024 * 1024);
    assert.equal(subject.store.totalBytes, subject.store.entries.reduce((sum, entry) => sum + bytes(entry), 0));
    assert.equal(subject.store.entries.at(-1).data, "x".repeat(220 * 1024) + 51);
    while (subject.store.canUndo()) applySnapshot(subject, subject.store.undo());
    while (subject.store.canRedo()) applySnapshot(subject, subject.store.redo());
    assert.equal(subject.store.entries[subject.store.index].data, "x".repeat(220 * 1024) + 51);
});
