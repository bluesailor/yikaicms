const { test } = require('node:test');
const assert = require('node:assert/strict');
const { methods } = require('../../assets/js/blox-items-control.js');

const ctrl = {
    key: 'items',
    max: 3,
    fields: [{ key: 'avatar', type: 'image' }, { key: 'name', type: 'text' }, { key: '__proto__', type: 'text' }],
    default: [{ avatar: '/a.svg', name: 'Seed', extra: 'dropped' }],
};

function editor(items) {
    return Object.assign({ selEl: { type: 'testimonial-carousel', data: items === undefined ? {} : { items } } }, methods);
}

test('items start from schema defaults and keep only declared fields', () => {
    const app = editor();
    assert.deepEqual(JSON.parse(JSON.stringify(app.repeaterItems(ctrl))), [{ avatar: '/a.svg', name: 'Seed' }]);
    app.setRepeaterItem(ctrl, 0, 'name', 42);
    assert.equal(app.selEl.data.items[0].name, '42');
    app.setRepeaterItem(ctrl, 0, 'extra', 'x');
    assert.equal('extra' in app.selEl.data.items[0], false);
    app.setRepeaterItem(ctrl, 0, '__proto__', 'x');
    assert.equal(Object.getPrototypeOf(app.selEl.data.items[0]), Object.prototype);
});

test('items can be added up to the limit, reordered and deleted but never emptied', () => {
    const app = editor([{ name: 'A' }, { name: 'B' }]);
    app.addRepeaterItem(ctrl);
    assert.equal(app.canAddRepeaterItem(ctrl), false);
    app.addRepeaterItem(ctrl);
    assert.equal(app.selEl.data.items.length, 3);
    app.moveRepeaterItem(ctrl, 0, 1);
    assert.deepEqual(app.selEl.data.items.map((item) => item.name), ['B', 'A', '']);
    app.moveRepeaterItem(ctrl, 0, -1);
    assert.equal(app.selEl.data.items[0].name, 'B');
    app.deleteRepeaterItem(ctrl, 2);
    app.deleteRepeaterItem(ctrl, 1);
    app.deleteRepeaterItem(ctrl, 0);
    assert.deepEqual(app.selEl.data.items.map((item) => item.name), ['B']);
});

test('picking an image writes back only while the same element is selected', () => {
    let callback = null;
    const app = editor([{ name: 'A' }]);
    app.openMedia = (fn) => { callback = fn; };
    app.pickRepeaterImage(ctrl, 0, 'avatar');
    callback('/picked.png');
    assert.equal(app.selEl.data.items[0].avatar, '/picked.png');

    app.pickRepeaterImage(ctrl, 0, 'avatar');
    const previous = app.selEl;
    app.selEl = { type: 'text', data: {} };
    callback('/other.png');
    assert.equal(previous.data.items[0].avatar, '/picked.png');
    assert.deepEqual(app.selEl.data, {});
});
