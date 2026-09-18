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

// ---- R1B：可观察状态（写入成功 / 存储不可用 / 额度不足 / 文档超限） ----

function failingStorage(error, values) {
    values = values || new Map();
    return {
        values,
        failing: true,
        getItem: (key) => (values.has(key) ? values.get(key) : null),
        setItem(key, value) {
            if (this.failing) throw error;
            values.set(key, value);
        },
        removeItem: (key) => values.delete(key),
    };
}

test('R1B: failed write keeps the previous valid copy and classifies quota errors', function () {
    const quotaError = new Error('full');
    quotaError.name = 'QuotaExceededError';
    const storage = failingStorage(quotaError);
    const recovery = new DraftRecovery({ storage, key: 'page:9', now: () => 7000 });

    storage.failing = false;
    recovery.queue('first copy', 'rev1');
    assert.equal(recovery.flush(), true);
    assert.equal(recovery.getState(), 'saved');
    assert.equal(recovery.lastSavedAt, 7000);

    storage.failing = true;
    recovery.queue('second copy', 'rev2');
    assert.equal(recovery.flush(), false);
    assert.equal(recovery.getState(), 'quota');
    // 旧的有效副本必须原样保留（绝不先清空再写）
    assert.equal(JSON.parse(storage.values.get('page:9')).data, 'first copy');
    // 待写内容保留，恢复可写后重试成功
    storage.failing = false;
    assert.equal(recovery.flush(), true);
    assert.equal(recovery.getState(), 'saved');
    assert.equal(JSON.parse(storage.values.get('page:9')).data, 'second copy');
});

test('R1B: non-quota write failures and missing storage report unavailable', function () {
    const storage = failingStorage(new Error('SecurityError'));
    const recovery = new DraftRecovery({ storage, key: 'page:10' });
    recovery.queue('doc', 'rev');
    assert.equal(recovery.flush(), false);
    assert.equal(recovery.getState(), 'unavailable');

    const none = new DraftRecovery({ key: 'page:11' });
    none.queue('doc', 'rev');
    assert.equal(none.getState(), 'unavailable');
});

test('R1B: oversized documents surface toolarge and never touch the stored copy', function () {
    const storage = memoryStorage();
    const recovery = new DraftRecovery({ storage, key: 'tpl:9', maxBytes: 1024, now: () => 1 });
    recovery.queue('keep me', 'rev');
    assert.equal(recovery.flush(), true);

    recovery.queue('x'.repeat(2048), 'rev');
    assert.equal(recovery.getState(), 'toolarge');
    assert.equal(recovery.flush(), false, 'oversized data must not stay pending');
    assert.equal(JSON.parse(storage.getItem('tpl:9')).data, 'keep me');
});

test('R1B: onStateChange fires once per transition, not per keystroke', function () {
    const transitions = [];
    const storage = memoryStorage();
    const recovery = new DraftRecovery({
        storage, key: 'tpl:10', maxBytes: 1024,
        onStateChange: (state) => transitions.push(state),
    });
    recovery.queue('x'.repeat(2000), 'rev');
    recovery.queue('y'.repeat(2001), 'rev');
    recovery.queue('z'.repeat(2002), 'rev');
    assert.deepEqual(transitions, ['toolarge']);

    recovery.queue('small', 'rev');
    recovery.flush();
    recovery.queue('small2', 'rev');
    recovery.flush();
    assert.deepEqual(transitions, ['toolarge', 'saved']);
});
