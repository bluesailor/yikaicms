const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
require("../../assets/js/blox-history-store.js");

function fixture(options = {}) {
    let data = options.data || '[{"id":"s1","title":"A"}]';
    let settings = options.settings;
    let structure = options.structure || '[{"id":"s1"}]';
    let selection = options.selection || { selectedSi: 0 };
    let applying = false;
    const store = new global.BloxHistoryStore({
        limit: options.limit || 51,
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
