const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { frame, openPageEditor, performPagePreviewUpdate, waitPreviewSettled, expectClean, openSectionInsertAtEnd } = require('./helpers');
const root = path.resolve(__dirname, '../..');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-baseline-fixture.php'), action], { cwd: root });
test.beforeAll(() => fixture('cache-pretty'));
test.afterAll(() => fixture('restore'));

test('FAQ edits, reorders, deletes, undoes and publishes a working accordion @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Persistence baseline; responsive FAQ has separate coverage');
  const marker = `E7 FAQ ${Date.now()}`;
  await page.goto(fixtures.blox_page_url);
  const canonical = new URL(await page.locator('link[rel="canonical"]').getAttribute('href'), baseURL);
  expect(canonical.origin).toBe(new URL(baseURL).origin);
  expect(canonical.search).toBe('');
  const before = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    expect((await before.request.get(canonical.pathname)).headers()['x-cache']).toBe('MISS');
    expect((await before.request.get(canonical.pathname)).headers()['x-cache']).toBe('HIT');
  } finally { await before.close(); }
  await openPageEditor(page, fixtures.blox_page);
  const clear = page.getByTestId('blox-clear-selection');
  if (await clear.isVisible()) await clear.click();
  await waitPreviewSettled(page);
  await openSectionInsertAtEnd(page);
  await page.getByTestId('blox-add-section-1').click();
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-accordion').press('Enter');
  const items = page.getByTestId('blox-accordion-item');
  await expect(items).toHaveCount(2);
  await expect(page.getByTestId('blox-element-property-grid')).not.toContainText('faq_repeater');
  await performPagePreviewUpdate(page, async () => {
    await page.getByTestId('blox-accordion-question').first().fill(marker);
    await page.getByTestId('blox-accordion-answer').first().fill(marker + ' answer');
    await page.getByTestId('blox-accordion-question').nth(1).fill(marker + ' second');
  });
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-accordion-move-down').first().click());
  await expect(page.getByTestId('blox-accordion-question').nth(1)).toHaveValue(marker);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-accordion-add').click());
  await expect(items).toHaveCount(3);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-accordion-delete').last().click());
  await expect(items).toHaveCount(2);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await expect(items).toHaveCount(3);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  await expect(items).toHaveCount(2);

  async function command(action, button) {
    const response = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_page_api.php'
      && new URLSearchParams(r.request().postData() || '').get('action') === action);
    if (action === 'publish') page.once('dialog', dialog => dialog.accept());
    await page.getByTestId(button).click();
    expect((await (await response).json()).code).toBe(0);
    await expectClean(page);
  }
  await command('save_draft', 'blox-save');
  await page.goto('/admin/page.php');
  await openPageEditor(page, fixtures.blox_page);
  await expect((await frame(page)).locator('summary').filter({ hasText: marker })).toHaveText([marker + ' second', marker]);
  const draftVisitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    expect(await (await draftVisitor.request.get(canonical.pathname)).text()).not.toContain(marker);
    expect((await draftVisitor.request.get(canonical.pathname)).headers()['x-cache']).toBe('HIT');
  } finally { await draftVisitor.close(); }
  await command('publish', 'blox-publish-page');
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const publicPage = await visitor.newPage();
    const errors = [];
    publicPage.on('pageerror', error => errors.push(error.message));
    publicPage.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    const response = await publicPage.goto(canonical.pathname);
    expect(response.status()).toBe(200);
    expect(response.headers()['x-cache']).toBe('MISS');
    await expect(publicPage.locator('#ik-adminbar')).toHaveCount(0);
    const summaries = publicPage.locator('summary').filter({ hasText: marker });
    await expect(summaries).toHaveText([marker + ' second', marker]);
    const target = publicPage.locator('details').filter({ has: publicPage.getByText(marker, { exact: true }) });
    await target.locator('summary').click();
    await expect(target).toHaveAttribute('open', '');
    await expect(target.getByText(marker + ' answer', { exact: true })).toBeVisible();
    await target.locator('summary').click();
    await expect(target).not.toHaveAttribute('open');
    await expect(target.getByText(marker + ' answer', { exact: true })).toBeHidden();
    expect(errors).toEqual([]);
  } finally { await visitor.close(); }
});
