const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const fixture = (action, id = '') => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-edit-draft-fixture.php'), action, String(id)], { cwd: root, encoding: 'utf8' }).trim();
const read = id => JSON.parse(fixture('read', id));
const body = page => page.frameLocator('#editor-container_ta_ifr').locator('body');

async function submit(page, mode) {
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded' });
  const response = page.waitForResponse(r => r.request().method() === 'POST'
    && new URL(r.url()).pathname === '/admin/product_edit.php');
  await page.locator('button[name="product_save"][value="' + mode + '"]').click();
  const result = await response;
  expect(result.status()).toBe(200);
  expect((await result.json()).code).toBe(0);
  await navigation;
  if (mode === 'apply') await page.waitForURL(/\/admin\/product\.php$/);
  else await page.waitForFunction(() => window.hugerte?.get('editor-container_ta')?.initialized === true);
}

test('product working copies reopen, preview privately and restore history without changing the public row @ci', async ({ page, browser, baseURL }) => {
  const id = fixture('seed');
  const original = read(id).product;
  const errors = [];
  page.on('dialog', dialog => { errors.push(dialog.type()); dialog.dismiss(); });
  await page.goto('/admin/product_edit.php?id=' + id);
  await page.waitForFunction(() => window.hugerte?.get('editor-container_ta')?.initialized === true);
  await body(page).fill('Working draft description A.');
  await page.locator('[name="title"]').fill('Working product A');
  await page.locator('#coverInput').fill('/assets/images/blox-templates/section-image-text.jpg');
  await page.locator('#specsList input[data-key="Power"]').fill('200 W');
  await page.locator('#specsList input[data-key="Power"]').press('Tab');
  await expect(page.locator('#specsInput')).toHaveValue('{"Power":"200 W"}');
  await page.evaluate(() => addGalleryImage('/assets/images/blox-templates/section-image-text.jpg'));
  await expect(body(page)).toContainText('Working draft description A.');
  await submit(page, 'draft');
  await expect(page.locator('[name="product_editing_draft"]')).toHaveValue('1');
  await expect(body(page)).toContainText('Working draft description A.');
  expect(read(id).product).toEqual(original);

  await page.goto('/admin/product.php');
  await page.goto('/admin/product_edit.php?id=' + id);
  await expect(page.locator('[name="title"]')).toHaveValue('Working product A');
  await expect(body(page)).toContainText('Working draft description A.');
  await expect(page.locator('#coverInput')).toHaveValue('/assets/images/blox-templates/section-image-text.jpg');
  await expect(page.locator('#specsList input[data-key="Power"]')).toHaveValue('200 W');
  await expect(page.locator('#galleryPreview img')).toHaveCount(2);

  const previewPromise = page.waitForEvent('popup');
  await page.locator('#editForm a[href*="edit_draft=1"]').click();
  const preview = await previewPromise;
  await expect(preview.locator('h1')).toHaveText('Working product A');
  await expect(preview.locator('body')).toContainText('Working draft description A.');
  await expect(preview.locator('#tab-specs')).toContainText('200 W');
  expect((await preview.request.get(preview.url())).headers()['cache-control']).toContain('no-store');
  await expect(preview.locator('img[src="/assets/images/blox-templates/section-image-text.jpg"]').first()).toBeVisible();
  await preview.close();

  const guestContext = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const guest = await guestContext.newPage();
  await guest.goto('/product.php?id=' + id);
  await expect(guest.locator('h1')).toHaveText(original.title);
  await expect(guest.locator('body')).toContainText('Original public description.');
  await expect(guest.locator('body')).not.toContainText('Working draft description A.');
  await guest.goto('/admin/draft_preview.php?type=products&id=' + id + '&edit_draft=1');
  expect(new URL(guest.url()).pathname).toBe('/admin/login.php');
  await guestContext.close();

  await body(page).fill('Working draft description B.');
  await page.locator('[name="title"]').fill('Working product B');
  await submit(page, 'draft');
  await expect(page.locator('[name="title"]')).toHaveValue('Working product B');
  const historical = read(id).history.find(row => row.summary === 'Working product A');
  expect(historical).toBeTruthy();
  await page.locator('details').filter({ has: page.locator('button[name="restore_revision"]') }).locator('summary').click();
  const restored = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('product_edit.php'));
  await page.locator('button[name="restore_revision"][value="' + historical.id + '"]').click();
  expect((await (await restored).json()).code).toBe(0);
  await expect(page.locator('[name="title"]')).toHaveValue('Working product A');
  await expect(body(page)).toContainText('Working draft description A.');
  await expect(page.locator('#specsList input[data-key="Power"]')).toHaveValue('200 W');
  expect(read(id).product).toEqual({ ...original, views: original.views + 1 });
  expect(read(id).history.some(row => row.summary === 'Working product B')).toBe(true);
  expect(errors).toEqual([]);
  await page.screenshot({ path: path.join(process.env.BLOX_E2E_SOURCE_ROOT || root, 'test-results/product-draft-restored-' + test.info().project.name + '.png'), fullPage: true });
});

test('stale editor, changed public row, cross-product history and missing CSRF are rejected @ci', async ({ page, context }) => {
  const id = fixture('seed');
  const otherId = fixture('seed');
  await page.goto('/admin/product_edit.php?id=' + id);
  const other = await context.newPage();
  await other.goto('/admin/product_edit.php?id=' + id);
  await page.locator('[name="title"]').fill('Newer draft');
  await submit(page, 'draft');
  await expect(page.locator('[name="product_editing_draft"]')).toHaveValue('1');
  const rejected = other.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('product_edit.php'));
  await other.locator('button[value="draft"]').click();
  expect((await rejected).status()).toBe(409);
  await other.close();
  expect(JSON.parse(read(id).draft.snapshot).fields.title).toBe('Newer draft');
  const version = read(id).history[0].id;
  await page.goto('/admin/product_edit.php?id=' + otherId);
  const form = await page.locator('#editForm').evaluate(form => Object.fromEntries(new FormData(form)));
  form._token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const stolen = await page.request.post('/admin/product_edit.php?id=' + otherId,
    { form: { ...form, product_save: 'restore', restore_revision: String(version) } });
  expect((await stolen.json()).code).not.toBe(0);
  const noCsrf = await page.request.post('/admin/product_edit.php?id=' + otherId, { form: { product_save: 'draft' } });
  expect(noCsrf.status()).toBe(403);

  fixture('change', id);
  await page.goto('/admin/product_edit.php?id=' + id);
  await expect(page.locator('button[value="apply"]')).toBeDisabled();
  const stale = await page.locator('#editForm').evaluate(form => Object.fromEntries(new FormData(form)));
  const forced = await page.request.post('/admin/product_edit.php?id=' + id,
    { form: { ...stale, _token: form._token, product_save: 'apply' } });
  expect(forced.status()).toBe(409);
  expect(read(id).product.content).toContain('Another editor');
});

test('applying an offline test product draft saves all fields atomically and keeps a recoverable history @ci', async ({ page }) => {
  const id = fixture('seed');
  await page.goto('/admin/product_edit.php?id=' + id);
  await page.locator('#productStatus').selectOption('0');
  await body(page).fill('Offline saved draft.');
  await page.locator('[name="title"]').fill('Offline draft verification');
  await submit(page, 'draft');
  await expect(page.locator('[name="product_editing_draft"]')).toHaveValue('1');
  fixture('fail_history');
  try {
    expect(fixture('probe_failure', id)).toBe('rolled back');
    expect(read(id).product.status).toBe(1);
    expect(read(id).draft).toBeTruthy();
  } finally {
    fixture('allow_history');
  }
  await submit(page, 'apply');
  const result = read(id);
  expect(result.product.status).toBe(0);
  expect(result.product.title).toBe('Offline draft verification');
  expect(result.product.content).toContain('Offline saved draft.');
  expect(result.draft).toBeNull();
  expect(result.history.length).toBeGreaterThan(0);
});
