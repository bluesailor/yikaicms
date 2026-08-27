const { test, expect } = require('@playwright/test');
const {
  countSections,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  performPreviewUpdate,
  restoreClean,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop Query Loop interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  const leakedDirtyState = await page.getByTestId('blox-dirty').isVisible().catch(() => false);
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'Query Loop E2E must not save or publish').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('Query Loop exposes pagination and child fallback controls @ci', async ({ page }) => {
  test.setTimeout(60_000);
  const before = await countSections(page);
  await page.getByTestId('blox-add-section-1').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);

  await page.getByTestId('blox-library-open').last().click();
  await page.getByTestId('blox-add-element-list-dynamic').press('Enter');
  const pagination = page.locator('[data-control-key="pagination_mode"] select');
  await expect(pagination).toBeVisible();

  let previewRequest;
  await performPreviewUpdate(page, async () => {
    const request = page.waitForRequest((candidate) => {
      const url = new URL(candidate.url());
      const body = new URLSearchParams(candidate.postData() || '');
      return candidate.method() === 'POST'
        && url.pathname === '/admin/blox_preview.php'
        && body.get('action') === 'preview';
    });
    await pagination.selectOption('numbers');
    previewRequest = await request;
  });
  const previewBody = new URLSearchParams(previewRequest.postData() || '');
  const document = JSON.parse(previewBody.get('blocks_data'));
  const sections = Array.isArray(document) ? document : document.sections;
  const loop = sections[sections.length - 1].columns[0].elements[0];
  expect(loop.data.pagination_mode).toBe('numbers');

  await page.getByTestId('blox-library-open').last().click();
  await page.getByTestId('blox-add-element-heading').press('Enter');
  await expect(page.locator('[data-control-key="loop_field"] select')).toBeVisible();
  await expect(page.locator('[data-control-key="loop_fallback"] input')).toBeVisible();
  await expect(page.locator('[data-control-key="site_field"]')).toHaveCount(0);

  await performPreviewUpdate(page, () => page.locator('[data-control-key="loop_fallback"] input').fill('Untitled'));
  await restoreClean(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});
