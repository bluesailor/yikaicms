const assert = require('node:assert/strict');
const test = require('node:test');

const Properties = require('../../assets/js/blox-multi-properties.js');

const item = (id, type, data = {}) => ({ id, type, data });

test('valueState distinguishes a common value from mixed values', () => {
    const same = Properties.valueState([
        item('a', 'heading', { align: 'left' }),
        item('b', 'heading', { align: 'left' }),
    ], (node) => node.data.align);
    assert.deepEqual(same, { mixed: false, value: 'left', count: 2 });

    const mixed = Properties.valueState([
        item('a', 'heading', { align: 'left' }),
        item('b', 'heading', { align: 'center' }),
    ], (node) => node.data.align);
    assert.deepEqual(mixed, { mixed: true, value: Properties.MIXED, count: 2 });
});

test('commonType only accepts a non-empty type shared by every item', () => {
    assert.equal(Properties.commonType([item('a', 'heading'), item('b', 'heading')]), 'heading');
    assert.equal(Properties.commonType([item('a', 'heading'), item('b', 'text')]), '');
    assert.equal(Properties.commonType([]), '');
});

test('planDataPatch updates selected ids without mutating the source', () => {
    const source = [
        item('a', 'heading', { align: 'left', text: 'A' }),
        item('b', 'heading', { align: 'right', text: 'B' }),
        item('c', 'heading', { align: 'left', text: 'C' }),
    ];
    const result = Properties.planDataPatch(source, ['a', 'b'], (data) => {
        data.align = 'center';
    });

    assert.equal(result.error, null);
    assert.equal(result.changed, 2);
    assert.deepEqual(result.list.map((node) => node.data.align), ['center', 'center', 'left']);
    assert.deepEqual(source.map((node) => node.data.align), ['left', 'right', 'left']);
    assert.equal(result.list[2], source[2], 'untouched items keep identity');
});

test('planDataPatch reports no-op changes without replacing item identity', () => {
    const source = [item('a', 'heading', { align: 'center' }), item('b', 'heading', { align: 'center' })];
    const result = Properties.planDataPatch(source, ['a', 'b'], (data) => {
        data.align = 'center';
    });
    assert.equal(result.changed, 0);
    assert.equal(result.list[0], source[0]);
    assert.equal(result.list[1], source[1]);
});

test('planDataPatch rejects incomplete and single-item selections', () => {
    const source = [item('a', 'heading'), item('b', 'heading')];
    const missing = Properties.planDataPatch(source, ['a', 'ghost'], () => {});
    assert.equal(missing.error, 'invalid');
    assert.deepEqual(missing.missing, ['ghost']);
    assert.equal(missing.list, undefined);

    const single = Properties.planDataPatch(source, ['a'], () => {});
    assert.equal(single.error, 'invalid');
    assert.equal(single.list, undefined);
});

test('planDataPatch propagates patch errors for command-layer rollback', () => {
    assert.throws(() => Properties.planDataPatch(
        [item('a', 'heading'), item('b', 'heading')],
        ['a', 'b'],
        () => { throw new Error('boom'); }
    ), /boom/);
});
