// 2.0.5 区块列的极光背景：列设置里开启 → 画布里那一列挂上 yk-aurora 与变量；关掉后整组设置清除。
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, performPagePreviewUpdate, frame } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

test('a section column can use the aurora background @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop editor check');
  await openPageEditor(page, fixtures.blox_page);
  await performPagePreviewUpdate(page, () => page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    app.selectSection(app.sections.length - 1, false);
    app.selectColumn(app.selectedSi, 0, false);
    app.panelTab = 'style';
    app.mobilePanel = 'settings';
    app.refreshPreview();
  }));
  const panel = page.getByTestId('blox-column-aurora');
  await expect(panel).toBeVisible();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-column-aurora-enabled').check());
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-column-bg-aurora-speed').selectOption('fast'));
  const column = (await frame(page)).locator('[data-yk-col].yk-aurora').first();
  await expect(column).toBeAttached();
  expect(await column.getAttribute('style')).toContain('--yk-aurora-speed:10s');

  await performPagePreviewUpdate(page, () => page.getByTestId('blox-column-aurora-enabled').uncheck());
  await expect((await frame(page)).locator('[data-yk-col].yk-aurora')).toHaveCount(0);
  expect(await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return Object.keys(app.selectedColData()).filter(k => k.startsWith('bg_aurora'));
  })).toEqual([]);
});
