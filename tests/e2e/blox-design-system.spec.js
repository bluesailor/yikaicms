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

const state = {
  schema: 1,
  revision: 4,
  tokens: [
    { id: 'primary', name: 'Primary', category: 'brand', value: '#3b82f6', status: 'active', locked: true, system: true, version: 1 },
    { id: 'secondary', name: 'Secondary', category: 'brand', value: '#1d4ed8', status: 'active', locked: true, system: true, version: 1 },
    { id: 'c_accent', name: 'Accent', category: 'brand', value: '#16a34a', status: 'active', locked: false, system: false, version: 2 },
    { id: 'c_old', name: 'Old', category: 'brand', value: '#991b1b', status: 'archived', locked: false, system: false, version: 3 },
  ],
  styles: [
    {
      id: 's_card', name: 'Card', category: 'component', color: 'var(--yk-color-c_accent)',
      background: 'var(--yk-color-primary)', border_color: 'var(--yk-color-secondary)', radius: 'md',
      status: 'active', locked: false, version: 1,
    },
  ],
};

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop design-system interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await page.route('**/admin/blox_design_api.php', async (route) => {
    const body = new URLSearchParams(route.request().postData() || '');
    const action = body.get('action');
    expect(['snapshot', 'usage']).toContain(action);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        msg: '',
        data: action === 'usage' ? {
          tokens: { c_accent: { count: 2, sources: [{ type: 'template', id: 9, label: 'Offer', state: 'published' }] } },
          styles: {},
        } : state,
      }),
    });
  });
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  const leakedDirtyState = await page.getByTestId('blox-dirty').isVisible().catch(() => false);
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'design-system E2E must not save or publish').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('token catalog and named preset apply through stable references @ci', async ({ page }) => {
  await page.getByTestId('blox-design-open').click();
  await expect(page.getByTestId('blox-design-token-row')).toHaveCount(3);
  await expect(page.getByTestId('blox-design-usage').filter({ hasText: '2' })).toBeVisible();
  await page.getByTestId('blox-design-tab-styles').click();
  await expect(page.getByTestId('blox-design-style-row')).toHaveCount(1);
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('blox-design-tab-colors')).toBeHidden();

  await addTemporaryHeading(page);
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-icon').press('Enter');
  await page.getByTestId('blox-style-tab').click();

  await performPreviewUpdate(page, () => page.getByTestId('blox-color-token-select').selectOption('c_accent'));
  const canvas = await frame(page);
  await expect(canvas.locator('[data-yk-el-type="icon"] i')).toHaveAttribute('style', /color:var\(--yk-color-c_accent\)/);

  await performPreviewUpdate(page, () => page.getByTestId('blox-global-style-select').selectOption('s_card'));
  const styled = canvas.locator('[data-yk-global-style="s_card"]');
  await expect(styled).toHaveCount(1);
  await expect(styled).toHaveAttribute('style', /background-color:var\(--yk-color-primary\)!important/);

  await restoreClean(page);
});

test('archiving a token keeps its frontend CSS tombstone @local', async ({ page }) => {
  await page.unroute('**/admin/blox_design_api.php');
  const boot = await page.evaluate(() => {
    const data = window.Alpine.$data(document.body);
    return { csrf: data.csrf, revision: data.designSystem.revision };
  });
  const marker = `E2E ${Date.now()}`;
  const addedResponse = await page.request.post('/admin/blox_design_api.php', {
    form: {
      action: 'token_add', revision: String(boot.revision), _token: boot.csrf,
      name: marker, category: 'test', value: '#123abc',
    },
  });
  const added = await addedResponse.json();
  expect(added.code).toBe(0);
  const token = added.data.tokens.find((item) => item.name === marker);
  expect(token && token.id).toMatch(/^c_[a-f0-9]{12}$/);

  const archivedResponse = await page.request.post('/admin/blox_design_api.php', {
    form: {
      action: 'token_archive', revision: String(added.data.revision), _token: boot.csrf, id: token.id,
    },
  });
  const archived = await archivedResponse.json();
  expect(archived.code).toBe(0);
  expect(archived.data.tokens.find((item) => item.id === token.id).status).toBe('archived');

  const frontend = await page.request.get(`/?preview=1&v=${Date.now()}`);
  expect(frontend.ok()).toBe(true);
  expect(await frontend.text()).toContain(`--yk-color-${token.id}:#123abc;`);
});
