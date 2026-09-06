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
  waitPreviewSettled,
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

test.beforeEach(async ({ page }) => {
  await openPageEditor(page, fixtures.blox_page);
  await page.route('**/uploads/videos/blox-test-flower.mp4', route => route.fulfill({
    status: 200,
    contentType: 'video/mp4',
    body: Buffer.alloc(32),
  }));
  await page.route('**/admin/media_api.php?*', route => {
    const url = new URL(route.request().url());
    const type = url.searchParams.get('type') || 'image';
    const item = type === 'video'
      ? { id: 2, name: 'R1 fixture video', url: '/uploads/videos/blox-test-flower.mp4', type: 'video', size: 4096 }
      : { id: 1, name: 'R1 fixture image', url: '/images/logo.png', type: 'image', width: 1920, height: 1080 };
    return route.fulfill({ json: { code: 0, data: { items: [item], page: 1, pages: 1, total: 1 } } });
  });
});

let consoleEntries;
let unsafeWrites;

test.beforeEach(async ({ page }) => {
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
});

test.afterEach(async ({ page }) => {
  await restoreContentChange(page);
  expect(unsafeWrites, 'content usability must not save or publish').toEqual([]);
  expect(consoleEntries, 'content usability console must stay clean').toEqual([]);
});

test('heading can be edited, restyled, reselected, and rendered without refresh @ci', async ({ page }) => {
  const originalUrl = page.url();
  await addElement(page, 'heading');

  const text = page.locator('[data-control-key="text"] input');
  await performPagePreviewUpdate(page, async () => {
    await text.fill('R1 heading value');
    await text.blur();
  });
  await expect((await frame(page)).locator('[data-yk-el-type="heading"]').last()).toContainText('R1 heading value');

  await performPagePreviewUpdate(page, () => page.getByTestId('blox-control-level').selectOption('h3'));
  await expect((await frame(page)).locator('[data-yk-el-type="heading"] h3').last()).toBeVisible();

  const headingTreeItem = page.locator('[data-testid="blox-tree-element"][data-element-type="heading"]').last();
  if (await page.getByTestId('blox-mobile-structure').isVisible()) {
    await page.getByTestId('blox-mobile-structure').click();
  }
  const targetSection = page.getByTestId('blox-tree-section').last();
  await targetSection.scrollIntoViewIfNeeded();
  await targetSection.click();
  if (await page.getByTestId('blox-mobile-structure').isVisible()) {
    await page.getByTestId('blox-mobile-structure').click();
  }
  await expect(headingTreeItem).toBeVisible();
  await headingTreeItem.click();
  if (!await text.isVisible()) await page.getByTestId('blox-mobile-settings').click();
  await expect(text).toHaveValue('R1 heading value');
  expect(page.url()).toBe(originalUrl);
});

test('button text, safe URL, and new-tab setting reach the preview @ci', async ({ page }) => {
  const originalUrl = page.url();
  await addElement(page, 'button');

  const text = page.locator('[data-control-key="text"] input');
  const url = page.locator('[data-control-key="url"] input');
  await performPagePreviewUpdate(page, async () => {
    await text.fill('R1 action');
    await url.fill('/contact.html');
    await page.locator('[data-control-key="new_tab"] input').check();
  });

  const button = (await frame(page)).locator('[data-yk-el-type="button"] a').last();
  await expect(button).toHaveText('R1 action');
  await expect(button).toHaveAttribute('href', '/contact.html');
  await expect(button).toHaveAttribute('target', '_blank');
  await expect(button).toHaveAttribute('rel', 'noopener');
  expect(page.url()).toBe(originalUrl);
});

test('image and video elements select the correct media type and render @ci', async ({ page }) => {
  const originalUrl = page.url();
  await addElement(page, 'image');
  await page.getByTestId('blox-element-image-media').click();
  await expect(page.getByTestId('blox-media-grid')).toBeVisible();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-media-item').first().click());
  await expect(page.getByTestId('blox-element-image-url')).toHaveValue('/images/logo.png');
  await expect((await frame(page)).locator('[data-yk-el-type="image"] img').last()).toHaveAttribute('src', /logo\.png/);

  await addElement(page, 'video');
  await page.getByTestId('blox-element-video-media').click();
  await expect(page.getByTestId('blox-media-grid')).toBeVisible();
  await expect(page.getByTestId('blox-media-video-preview')).toHaveCount(1);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-media-item').first().click());
  await expect(page.getByTestId('blox-element-video-url')).toHaveValue('/uploads/videos/blox-test-flower.mp4');
  await expect((await frame(page)).locator('[data-yk-el-type="video"] video').last()).toHaveAttribute('src', /blox-test-flower\.mp4/);
  expect(page.url()).toBe(originalUrl);
});
