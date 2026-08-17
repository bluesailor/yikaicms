const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  performPreviewUpdate,
  restoreClean,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop display-condition interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  const leakedDirtyState = await page.getByTestId('blox-dirty').isVisible().catch(() => false);
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'display-condition E2E must not save or publish').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('element conditions create OR groups, AND rules and a canvas marker @ci', async ({ page }) => {
  await addTemporaryHeading(page);
  await page.getByTestId('blox-condition-tab').click();
  await expect(page.getByTestId('blox-condition-editor')).toBeVisible();

  await performPreviewUpdate(page, () => page.getByTestId('blox-condition-empty-add').click());
  await expect(page.getByTestId('blox-condition-group-0')).toBeVisible();
  await expect((await frame(page)).locator('h1[data-yk-conditions],h2[data-yk-conditions],h3[data-yk-conditions]')).toHaveCount(1);

  await page.getByTestId('blox-condition-add-rule').first().click();
  const secondRule = page.getByTestId('blox-condition-rule-0-1');
  await secondRule.getByTestId('blox-condition-type').selectOption('url');
  await secondRule.getByTestId('blox-condition-operator').selectOption('starts_with');

  let previewRequest;
  await performPreviewUpdate(page, async () => {
    const request = page.waitForRequest((candidate) => {
      const url = new URL(candidate.url());
      const body = new URLSearchParams(candidate.postData() || '');
      return candidate.method() === 'POST'
        && url.pathname === '/admin/blox_preview.php'
        && body.get('action') === 'preview';
    });
    await secondRule.getByTestId('blox-condition-value-url').fill('/campaign');
    previewRequest = await request;
  });

  await page.getByTestId('blox-condition-add-group').click();
  await expect(page.getByTestId('blox-condition-group-1')).toBeVisible();

  const payload = new URLSearchParams(previewRequest.postData() || '');
  const document = JSON.parse(payload.get('blocks_data'));
  const sections = Array.isArray(document) ? document : document.sections;
  const conditions = sections[sections.length - 1].columns[0].elements[0].data._conditions;
  expect(conditions[0].rules).toEqual([
    { type: 'login', operator: 'is', value: 'logged_in' },
    { type: 'url', operator: 'starts_with', value: '/campaign' },
  ]);

  await restoreClean(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});
