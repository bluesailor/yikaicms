const test = require('node:test');
const assert = require('node:assert/strict');

const order = require('../../plugins/icon-maker/random-order.js');

test('candidate order keeps saved known ids once and appends new candidates', () => {
    assert.deepEqual(
        order.normalize(['0', '1', '2', '3'], ['2', '2', 'missing', '0']),
        ['2', '0', '1', '3']
    );
});

test('candidate order moves an item and clamps the target position', () => {
    assert.deepEqual(order.move(['0', '1', '2'], '2', 0), ['2', '0', '1']);
    assert.deepEqual(order.move(['0', '1', '2'], '0', 99), ['1', '2', '0']);
    assert.deepEqual(order.move(['0', '1'], 'missing', 0), ['0', '1']);
});

test('candidate order storage key is stable per generated query', () => {
    assert.equal(order.key('?seed=10&industry=tech'), order.key('?seed=10&industry=tech'));
    assert.notEqual(order.key('?seed=10'), order.key('?seed=11'));
});
