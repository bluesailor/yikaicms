const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const {
  editorHasChanges,
  expectClean,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openPageEditor,
  performPagePreviewUpdate,
} = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

async function addElement(page, type) {
  await performPagePreviewUpdate(page, () => page.evaluate(elementType => {
    const app = window.Alpine.$data(document.body);
    app.selectSection(app.sections.length - 1, false);
    app.addElement(app.elementLib.find(element => element.type === elementType));
    app.mobilePanel = 'settings';
    app.refreshPreview();
  }, type));
  const treeItem = page.locator(`[data-testid="blox-tree-element"][data-element-type="${type}"]`).last();
  if (!await treeItem.isVisible()) await page.getByTestId('blox-mobile-structure').click();
  await expect(treeItem).toBeVisible();
  if (!await page.getByTestId('blox-property-scroll').isVisible()) {
    await page.getByTestId('blox-mobile-settings').click();
  }
  await expect(page.getByTestId('blox-property-scroll')).toBeVisible();
}

const control = (page, key, suffix = '') => page.locator(`[data-control-key="${key}"] ${suffix}`.trim());

async function restoreContentChange(page) {
  for (let attempt = 0; attempt < 24 && await editorHasChanges(page); attempt += 1) {
    const desktopUndo = page.getByTestId('blox-undo');
    if (await desktopUndo.isVisible()) {
      await desktopUndo.click();
    } else {
      await page.getByTestId('blox-mobile-actions-open').click();
      const mobileUndo = page.getByTestId('blox-mobile-undo');
      await expect(mobileUndo).toBeVisible();
      await mobileUndo.click();
    }
    await page.waitForTimeout(80);
  }
  await expectClean(page);
}

let consoleEntries;
let unsafeWrites;

test.beforeEach(async ({ page }) => {
  await openPageEditor(page, fixtures.blox_page);
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
});

test.afterEach(async ({ page }) => {
  const rte = page.locator('[aria-labelledby="blox-rte-dialog-title"]');
  if (await rte.isVisible()) await rte.locator('button').last().click();
  await restoreContentChange(page);
  expect(unsafeWrites, 'basic element usability must not save or publish').toEqual([]);
  expect(consoleEntries, 'basic element usability console must stay clean').toEqual([]);
});

test('text opens rich editor and renders formatted content @ci', async ({ page }) => {
  await addElement(page, 'text');
  await page.getByTestId('blox-richtext-edit').click();

  const rte = page.locator('[aria-labelledby="blox-rte-dialog-title"]');
  await expect(rte).toBeVisible();
  const editor = rte.frameLocator('iframe');
  await expect(editor.locator('body')).toBeVisible();
  await editor.locator('body').fill('R2 rich text value');
  await rte.locator('button').last().click();

  await expect((await frame(page)).locator('[data-yk-el-type="text"]').filter({ hasText: 'R2 rich text value' })).toContainText('R2 rich text value');
  await expect(page.getByTestId('blox-richtext-edit')).toBeVisible();
});

test('alert, quote, card, and CTA expose editable content @ci', async ({ page }) => {
  await addElement(page, 'alert');
  await performPagePreviewUpdate(page, async () => {
    await control(page, 'text', 'textarea').fill('R2 alert value');
    await control(page, 'level', 'select').selectOption('warning');
  });
  await expect((await frame(page)).locator('[data-yk-el-type="alert"]')).toContainText('R2 alert value');

  await addElement(page, 'quote');
  await performPagePreviewUpdate(page, async () => {
    await control(page, 'text', 'textarea').fill('R2 quote value');
    await control(page, 'author', 'input').fill('R2 author');
  });
  const quote = (await frame(page)).locator('[data-yk-el-type="quote"]').filter({ hasText: 'R2 quote value' });
  await expect(quote).toContainText('R2 quote value');
  await expect(quote.locator('footer')).toContainText('R2 author');

  await addElement(page, 'card');
  await performPagePreviewUpdate(page, async () => {
    await control(page, 'title', 'input').fill('R2 card title');
    await control(page, 'text', 'textarea').fill('R2 card body');
    await control(page, 'link', 'input').fill('/contact.html');
  });
  const card = (await frame(page)).locator('[data-yk-el-type="card"]').filter({ hasText: 'R2 card title' });
  await expect(card).toContainText('R2 card title');
  await expect(card.locator('a')).toHaveAttribute('href', '/contact.html');

  await addElement(page, 'cta');
  await performPagePreviewUpdate(page, async () => {
    await control(page, 'title', 'input').fill('R2 CTA title');
    await control(page, 'text', 'textarea').fill('R2 CTA body');
    await control(page, 'btn_text', 'input').fill('R2 CTA action');
  });
  const cta = (await frame(page)).locator('[data-yk-el-type="cta"]').filter({ hasText: 'R2 CTA title' });
  await expect(cta).toContainText('R2 CTA title');
  await expect(cta).toContainText('R2 CTA action');
});

test('icon, icon-box, logo, divider, and spacer expose usable controls @ci', async ({ page }) => {
  await addElement(page, 'icon');
  await page.getByTestId('blox-icon-library-toggle').click();
  await expect(page.getByTestId('blox-icon-search')).toBeVisible();
  await page.getByTestId('blox-icon-provider-tabler').click();
  await page.getByTestId('blox-icon-option-star').click();
  await performPagePreviewUpdate(page, async () => {
    await control(page, 'size', 'select').selectOption('lg');
    await control(page, 'text', 'input').fill('R2 icon label');
  });
  await expect((await frame(page)).locator('[data-yk-el-type="icon"]').filter({ hasText: 'R2 icon label' })).toContainText('R2 icon label');

  await addElement(page, 'icon-box');
  await performPagePreviewUpdate(page, async () => {
    await control(page, 'title', 'input').fill('R2 icon box title');
    await control(page, 'text', 'textarea').fill('R2 icon box body');
  });
  await expect((await frame(page)).locator('[data-yk-el-type="icon-box"]').filter({ hasText: 'R2 icon box title' })).toContainText('R2 icon box title');

  await addElement(page, 'logo');
  await performPagePreviewUpdate(page, () => control(page, 'display', 'select').selectOption('text'));
  await expect((await frame(page)).locator('[data-yk-el-type="logo"]')).toBeVisible();

  await addElement(page, 'divider');
  await performPagePreviewUpdate(page, async () => {
    await control(page, 'style', 'select').selectOption('dashed');
    await control(page, 'width', 'input').fill('2');
  });
  await expect((await frame(page)).locator('[data-yk-el-type="divider"] hr')).toHaveAttribute('style', /dashed/);

  await addElement(page, 'spacer');
  await performPagePreviewUpdate(page, () => control(page, 'size', 'select').selectOption('lg'));
  await expect((await frame(page)).locator('[data-yk-el-type="spacer"]')).toBeVisible();
});
