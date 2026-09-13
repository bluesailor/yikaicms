const { test } = require('node:test');
const assert = require('node:assert/strict');
const scope = require('../../assets/js/blox-detail-scope');

// TASK-005 A：简单范围 UI 只能无损表达"空 include / 单条 all / 单条 item"；
// 其余必须锁定，否则模式切换或勾选会整体替换 include 并删除既有规则。
test('simple scope UI is blocked when include holds rules it cannot express', () => {
  assert.equal(scope.canEditWithSimpleUi({ include: [] }), true, 'none 可表达');
  assert.equal(scope.canEditWithSimpleUi({ include: [{ kind: 'all' }] }), true, '单条 all 可表达');
  assert.equal(scope.canEditWithSimpleUi({ include: [{ kind: 'item', ids: [1, 2] }] }), true, '单条 item 可表达');

  assert.equal(scope.canEditWithSimpleUi({ include: [{ kind: 'category', ids: [5], include_children: true }] }), false, '按栏目条件不可表达');
  assert.equal(scope.hasComplexInclude({ include: [{ kind: 'category', ids: [5] }] }), true);
  assert.equal(scope.canEditWithSimpleUi({ include: [{ kind: 'all' }, { kind: 'item', ids: [1] }] }), false, '多条规则整体替换会丢信息');
});

test('missing or malformed scope never unlocks silently', () => {
  assert.equal(scope.canEditWithSimpleUi({}), true, '没有 include 等价于 none');
  assert.equal(scope.canEditWithSimpleUi(null), true);
  assert.equal(scope.canEditWithSimpleUi({ include: 'nope' }), true, '非法 include 视为空（保守等于 none，不据此写规则）');
  // 未知 kind 视为不可表达，宁可锁定
  assert.equal(scope.canEditWithSimpleUi({ include: [{ kind: 'future-kind' }] }), false);
  assert.deepEqual(scope.includeRules({ include: [{ kind: 'all' }] }), [{ kind: 'all' }]);
  assert.deepEqual(scope.includeRules(null), []);
});

test('exclude alone does not block the simple UI (it is not overwritten by its writes)', () => {
  // 两个写方法只赋值 include，exclude/priority/lang/source 原样保留 → 不属于"被覆盖丢失"
  assert.equal(scope.canEditWithSimpleUi({ include: [{ kind: 'all' }], exclude: [{ kind: 'category', ids: [9] }] }), true);
});
