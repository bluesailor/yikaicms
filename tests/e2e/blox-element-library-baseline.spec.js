const { test, expect } = require('@playwright/test');
const {
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  editorHasChanges,
  expectClean,
  waitPreviewSettled,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

async function restoreLibraryChange(page) {
  for (let attempt = 0; attempt < 4 && await editorHasChanges(page); attempt += 1) {
    const desktopUndo = page.getByTestId('blox-undo');
    if (await desktopUndo.isVisible()) {
      await desktopUndo.click();
    } else {
      await page.getByTestId('blox-mobile-actions-open').click();
      await page.getByTestId('blox-mobile-undo').click();
    }
    await waitPreviewSettled(page);
  }
  await expectClean(page);
}

async function openLibrary(page) {
  const desktopOpen = page.getByTestId('blox-elements-open');
  if (await desktopOpen.isVisible()) {
    await desktopOpen.click();
  } else {
    await page.getByTestId('blox-mobile-actions-open').click();
    await page.getByTestId('blox-mobile-add-elements').click();
  }
  await expect(page.getByTestId('blox-element-scroll')).toBeVisible();
}

test.beforeEach(async ({ page }) => {
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  await restoreLibraryChange(page);
  expect(unsafeWrites, 'element library baseline must not save or publish').toEqual([]);
  expect(consoleEntries, 'element library baseline console must stay clean').toEqual([]);
});

test('element library mirrors the runtime registry with usable metadata @ci', async ({ page }) => {
  await openLibrary(page);
  const runtime = await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return app.elementLib
      .filter((element) => element.type !== '__section' && element.paletteVisible !== false)
      .map((element) => ({
        type: element.type,
        label: String(element.label || ''),
        category: String(element.category || ''),
        icon: String(element.icon || ''),
      }));
  });

  expect(runtime.length).toBeGreaterThan(20);
  for (const element of runtime) {
    const tile = page.getByTestId(`blox-add-element-${element.type}`);
    await expect(tile, `${element.type} tile`).toHaveCount(1);
    await expect(tile, `${element.type} label`).toContainText(element.label);
    await expect(tile.locator('i')).toHaveClass(new RegExp(`\\bti-${element.icon}\\b`));
  }
});

test('licensed Pro elements carry a PRO badge and stay insertable @ci', async ({ page }) => {
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'licensed-mode assertion; free mode is covered in blox-page.spec.js');
  await openLibrary(page);
  for (const type of ['table', 'pricing-table']) {
    const tile = page.getByTestId(`blox-add-element-${type}`);
    await tile.scrollIntoViewIfNeeded();
    await expect(tile).toHaveAttribute('data-pro', 'available');
    await expect(tile).toHaveAttribute('draggable', 'true');
    await expect(tile).not.toHaveAttribute('aria-disabled', 'true');
    await expect(page.getByTestId(`blox-pro-badge-${type}`)).toHaveAttribute('href', '/admin/license.php');
  }
  // Free elements carry no Pro marker.
  await expect(page.getByTestId('blox-add-element-heading')).not.toHaveAttribute('data-pro', /.+/);
});

test('element library performs a non-refresh insertion into the selected section @ci', async ({ page }) => {
  const originalUrl = page.url();
  const target = page.getByTestId('blox-tree-section').first();
  if (!await target.isVisible()) await page.getByTestId('blox-mobile-structure').click();
  await expect(target).toBeVisible();
  await target.click();
  const before = await target.getByTestId('blox-tree-element').count();
  const canvasHeadings = (await frame(page)).locator('[data-yk-el-type="heading"]');
  const headingBefore = await canvasHeadings.count();
  await openLibrary(page);
  await page.getByTestId('blox-add-element-heading').press('Enter');
  await expect(target.getByTestId('blox-tree-element')).toHaveCount(before + 1);
  await waitPreviewSettled(page);
  await expect((await frame(page)).locator('[data-yk-el-type="heading"]')).toHaveCount(headingBefore + 1);
  expect(page.url()).toBe(originalUrl);
});
