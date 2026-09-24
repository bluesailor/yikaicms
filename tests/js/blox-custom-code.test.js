const { test } = require('node:test');
const assert = require('node:assert/strict');
const { check } = require('../../assets/js/blox-custom-code');

// 与 tests/Unit/BloxCustomCodeTest.php 同一组样例：客户端即时检查必须与服务端结论一致
test('custom CSS checks mirror the server rules', () => {
  const cases = [
    ['', ''],
    ['color: red;', ''],
    ['%root% { color: red } %root%:hover { color: blue }', ''],
    ['%root% .md\\:flex { display: flex } /* 注释 */', ''],
    ['%root% { background: url(/uploads/a.png) }', ''],
    ['%root% { background: url(data:image/png;base64,AAAA) }', ''],
    ['%root% { content: "{" }', ''],
    ['%root% { background: url(https://evil.test/x) }', 'external'],
    ['%root% { background: url(//evil.test/x) }', 'external'],
    ['%root% { background: \\75 rl(\\2f\\2f evil.test) }', 'external'],
    ['@import "x.css";', 'forbidden'],
    ['@\\69mport "x.css";', 'forbidden'],
    ['%root% { width: expression(alert(1)) }', 'forbidden'],
    ['%root% { background: url(javascript:alert(1)) }', 'forbidden'],
    ['%root% { background: src("x") }', 'forbidden'],
    ['</style><script>alert(1)</script>', 'markup'],
    ['%root% { color: red', 'braces'],
    ['%root% { color: red }}', 'braces'],
    ['%root% { color: red } /* open', 'comment'],
    [42, 'invalid'],
  ];
  for (const [css, expected] of cases) {
    assert.equal(check(css), expected, String(css));
  }
  assert.equal(check('a'.repeat(10001)), 'too_long');
  assert.equal(check('a'.repeat(15000), 20000), '');
});

test('class names are filtered with the server rules', () => {
  const { classList } = require('../../assets/js/blox-custom-code');
  assert.equal(classList('  card  md:flex yk-c-fake w-1/2 bad"class card <x> '), 'card md:flex w-1/2');
  assert.equal(classList(''), '');
  assert.equal(classList(Array.from({ length: 25 }, (_, i) => 'c' + i).join(' ')).split(' ').length, 20);
});
