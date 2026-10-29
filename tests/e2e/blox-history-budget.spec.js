const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('./site-diagnostics');
const { openPageEditor, frame, waitPreviewSettled } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

async function recorded(page, text) {
  await expect.poll(() => page.evaluate(value => {
    const store = window.Alpine.$data(document.body).historyStore();
    return !store.pending && store.entries[store.index].data.includes(value);
  }, text)).toBe(true);
}

test('history byte cap preserves toolbar undo, redo and a continuous typing branch @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Desktop editor history controls');
  await openPageEditor(page, fixtures.blox_page);
  await waitPreviewSettled(page);

  const defaults = await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    const si = app.sections.findIndex(section => (section.columns || []).some(column =>
      (column.elements || []).some(element => element.type === 'heading')));
    const ci = app.sections[si].columns.findIndex(column =>
      (column.elements || []).some(element => element.type === 'heading'));
    const ei = app.sections[si].columns[ci].elements.findIndex(element => element.type === 'heading');
    app.selectElement(si, ci, ei);
    const store = app.historyStore();
    const defaults = { maxBytes: store.maxBytes, limit: store.limit };
    store.init();
    // 同一生产路径缩小预算，让普通 fixture 也能稳定触发淘汰，不扩大页面或发布内容。
    store.maxBytes = Math.ceil(store.totalBytes * 2.5);
    return defaults;
  });
  expect(defaults).toEqual({ maxBytes: 4 * 1024 * 1024, limit: 51 });

  const field = page.getByTestId('blox-heading-text');
  for (const value of ['History budget 1', 'History budget 2', 'History budget 3']) {
    await field.fill(value);
    await recorded(page, value);
  }
  expect(await page.evaluate(() => window.Alpine.$data(document.body).historyStore().entries.length)).toBe(2);

  await page.getByTestId('blox-undo').click();
  await expect(field).toHaveValue('History budget 2');
  await expect(page.getByTestId('blox-undo')).toBeDisabled();
  await expect(page.getByTestId('blox-redo')).toBeEnabled();
  await page.getByTestId('blox-redo').click();
  await expect(field).toHaveValue('History budget 3');
  await page.getByTestId('blox-undo').click();
  await expect(field).toHaveValue('History budget 2');

  // 连续输入应合成一步；停顿前直接点撤销也必须捕获最后一个字。
  await field.focus();
  await field.press('End');
  await field.pressSequentially(' abc123', { delay: 60 });
  await expect(field).toHaveValue('History budget 2 abc123');
  await expect(page.getByTestId('blox-redo')).toBeDisabled();
  await page.getByTestId('blox-undo').click();
  await expect(field).toHaveValue('History budget 2');
  await page.getByTestId('blox-redo').click();
  await expect(field).toHaveValue('History budget 2 abc123');
  await recorded(page, 'History budget 2 abc123');
  await waitPreviewSettled(page);
  await expect((await frame(page)).getByRole('heading', { name: 'History budget 2 abc123', exact: true })).toBeVisible();

  const state = await page.evaluate(() => {
    const store = window.Alpine.$data(document.body).historyStore();
    const encoder = new TextEncoder();
    const countedBytes = store.entries.reduce((sum, entry) => sum
      + encoder.encode(entry.data || '').length
      + encoder.encode(entry.settings || '').length
      + encoder.encode(entry.structure || '').length, 0);
    return { entries: store.entries.length, totalBytes: store.totalBytes, countedBytes, maxBytes: store.maxBytes };
  });
  expect(state.entries).toBe(2);
  expect(state.totalBytes).toBe(state.countedBytes);
  expect(state.totalBytes).toBeLessThanOrEqual(state.maxBytes);
  console.log('[history-budget] ' + JSON.stringify(state));
});
