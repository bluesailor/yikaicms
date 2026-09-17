const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { openEditor, expectClean, waitPreviewSettled } = require('./helpers');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'about-language-fixture.php'), action], { cwd: path.resolve(__dirname, '../..') });

test.beforeEach(() => fixture('converted'));
test.afterEach(() => fixture('restore'));

test('converted About returns to its actual heading without changing the document @ci', async ({ page }) => {
  await openEditor(page);
  const structure = page.getByTestId('blox-mobile-structure');
  if (await structure.isVisible()) await structure.click();
  const section = page.getByTestId('blox-tree-section').first();
  await section.locator('[data-section-drag-handle]').first().click();
  if (await structure.isVisible()) await structure.click();
  await section.locator('[data-testid="blox-tree-element"][data-element-type="heading"] [data-element-drag-handle]').first().click();
  // 标题文字控件为多行文本框（支持换行标题）
  const field = page.getByTestId('blox-left-panel').getByRole('textbox', { name: '标题', exact: true });
  await expect(field).toHaveValue('关于企业');
  const before = await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return { id: app.selectedElementId(), document: app.documentData(), undo: app.canUndo() };
  });
  await page.getByTestId('blox-edit-section-background').click();
  await expect(page.getByTestId('blox-section-background-video-media')).toBeVisible();
  await page.getByTestId('blox-return-content').click();
  await expect(field).toHaveValue('关于企业');
  await waitPreviewSettled(page);
  expect(await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return { id: app.selectedElementId(), document: app.documentData(), undo: app.canUndo() };
  })).toEqual(before);
  await expectClean(page);
});
