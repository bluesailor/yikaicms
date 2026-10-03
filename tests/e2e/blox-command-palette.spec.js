// 2.0.4 编辑器命令面板（Ctrl+K）：跳到元素、插入元素、查找替换（可撤销）、整页复制粘贴。不保存不发布。
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, observeConsole, expectClean } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

const app = (page, fn, arg) => page.evaluate(([source, value]) => {
  // eslint-disable-next-line no-new-func
  return new Function('app', 'value', source)(window.Alpine.$data(document.body), value);
}, [fn, arg]);

test('command palette jumps, inserts, replaces text with undo and copies the page @ci', async ({ page, context }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop editor keyboard flow');
  const errors = observeConsole(page);
  await context.grantPermissions(['clipboard-read', 'clipboard-write']).catch(() => {});
  await openPageEditor(page, fixtures.blox_page);
  const original = await app(page, 'return JSON.stringify(app.sections);');
  await app(page, `app.sections = [{ id: 'pal-s', type: 'section', settings: { padding: 'md' }, columns: [{ id: 'pal-c', settings: {}, elements: [
    { id: 'pal-h', type: 'heading', data: { text: 'Palette heading Acme', level: 'h2' } },
    { id: 'pal-t', type: 'text', data: { html: '<p>Acme <a href="/acme">Acme link</a></p>' } },
  ] }] }];`);

  const palette = page.getByTestId('blox-command-palette');
  await page.keyboard.press('Control+k');
  await expect(palette).toBeVisible();
  await expect(page.getByTestId('blox-command-input')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(palette).toBeHidden();

  // 跳到页面里的元素
  await page.keyboard.press('Control+k');
  await page.getByTestId('blox-command-input').fill('Palette heading');
  await expect(page.getByTestId('blox-command-el:0.0.0')).toBeVisible();
  await page.keyboard.press('Enter');
  await expect(palette).toBeHidden();
  expect(await app(page, 'return app.selEl && app.selEl.id;')).toBe('pal-h');

  // 插入元素（插到当前选中所在的位置）
  const before = await app(page, 'return app.sections[0].columns[0].elements.length;');
  await page.keyboard.press('Control+k');
  await page.getByTestId('blox-command-input').fill('divider');
  await page.getByTestId('blox-command-insert:divider').click();
  await expect.poll(() => app(page, 'return app.sections[0].columns[0].elements.length;')).toBe(before + 1);

  // 查找替换：只改看得到的文字，链接地址不变；可撤销
  await page.keyboard.press('Control+k');
  await page.getByTestId('blox-command-input').fill('replace');
  await page.getByTestId('blox-command-replace').click();
  await page.getByTestId('blox-command-find').fill('Acme');
  await page.getByTestId('blox-command-replace-with').fill('Yikai');
  await expect(page.getByTestId('blox-command-replace-count')).toContainText('3');
  await page.getByTestId('blox-command-replace-apply').click();
  await expect(palette).toBeHidden();
  expect(await app(page, 'return app.sections[0].columns[0].elements[0].data.text;')).toBe('Palette heading Yikai');
  expect(await app(page, `return app.sections[0].columns[0].elements.find(function (el) { return el.id === 'pal-t'; }).data.html;`))
    .toBe('<p>Yikai <a href="/acme">Yikai link</a></p>');
  await page.getByTestId('blox-undo').click();
  await expect.poll(() => app(page, 'return app.sections[0].columns[0].elements[0].data.text;')).toBe('Palette heading Acme');

  // 整页复制后粘贴：追加到末尾，全部换新 ID
  await page.keyboard.press('Control+k');
  await page.getByTestId('blox-command-copy-page').click();
  await page.keyboard.press('Control+k');
  await page.getByTestId('blox-command-paste-page').click();
  await expect.poll(() => app(page, 'return app.sections.length;')).toBe(2);
  const ids = await app(page, 'return [app.sections[0].id, app.sections[1].id, app.sections[1].columns[0].elements[0].id];');
  expect(ids[1]).not.toBe(ids[0]);
  expect(ids[2]).not.toBe('pal-h');

  // 还原，不留未保存改动
  await app(page, 'app.sections = JSON.parse(value);', original);
  await expectClean(page);
  expect(errors.filter(e => /pageerror|Alpine Expression Error/.test(e))).toEqual([]);
});
