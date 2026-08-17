const test = require('node:test');
const assert = require('node:assert/strict');
const { DraftRecovery } = require('../../assets/js/blox-draft-recovery.js');

function memoryStorage() {
    const values = new Map();
    return {
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
}

test('draft recovery queues, flushes and restores a newer divergent snapshot', function () {
    const storage = memoryStorage();
    const recovery = new DraftRecovery({ storage, key: 'page:1', now: () => 5000 });
    recovery.queue('{"sections":[1]}', 'abc');
    assert.equal(recovery.flush(), true);

    assert.deepEqual(recovery.read('{"sections":[]}'), {
        version: 1,
        savedAt: 5000,
        baseRevision: 'abc',
        data: '{"sections":[1]}',
    });
});

test('draft recovery clears equal, expired and malformed snapshots', function () {
    const storage = memoryStorage();
    const recovery = new DraftRecovery({ storage, key: 'home', now: () => 5000 });

    recovery.queue('same', 'rev');
    recovery.flush();
    assert.equal(recovery.read('same'), null);
    assert.equal(storage.getItem('home'), null);

    const expired = new DraftRecovery({ storage, key: 'home', now: () => 90000000, maxAge: 60000 });
    recovery.queue('old', 'rev');
    recovery.flush();
    assert.equal(expired.read('current'), null);

    storage.setItem('home', '{bad json');
    assert.equal(recovery.read('current'), null);
    assert.equal(storage.getItem('home'), null);
});

test('draft recovery rejects oversized data and clear cancels pending writes', function () {
    const storage = memoryStorage();
    const recovery = new DraftRecovery({ storage, key: 'tpl:2', maxBytes: 1024 });

    recovery.queue('x'.repeat(1025), 'rev');
    assert.equal(recovery.flush(), false);
    recovery.queue('valid', 'rev');
    recovery.clear();
    assert.equal(recovery.flush(), false);
    assert.equal(storage.getItem('tpl:2'), null);
});
