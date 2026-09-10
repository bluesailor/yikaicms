const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { openEditor, frame, performPreviewUpdate, expectClean, waitPreviewSettled } = require('./helpers');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'partners-fixture.php'), action], { cwd: path.resolve(__dirname, '../..') });

test.beforeEach(() => fixture('seed'));
test.afterEach(() => fixture('restore'));

async function selectPartners(page) {
  const structure = page.getByTestId('blox-mobile-structure');
  if (await structure.isVisible()) await structure.click();
  const section = page.getByTestId('blox-tree-section').first();
  await section.locator('[data-section-drag-handle]').first().click();
  if (await structure.isVisible()) await structure.click();
  await section.locator('[data-element-drag-handle]').first().click();
  await expect(page.getByTestId('blox-partners-manager')).toBeVisible();
}

async function clickAction(page, action) {
  const desktop = page.getByTestId('blox-' + action);
  if (await desktop.isVisible()) return desktop.click({ timeout: 10000 });
  await page.getByTestId('blox-mobile-actions-open').click();
  await page.getByTestId('blox-mobile-' + action).click({ timeout: 10000 });
}

async function save(page, publish = false) {
  const pending = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/blox_home_api.php'
    && response.request().method() === 'POST'
    && (new URLSearchParams(response.request().postData() || '').get('action') || 'save') === (publish ? 'publish' : 'save'));
  if (publish) page.once('dialog', dialog => dialog.accept());
  await clickAction(page, publish ? 'publish' : 'save');
  expect((await (await pending).json()).code).toBe(0);
  await expectClean(page);
}

async function expectNames(page, names) {
  await expect(page.getByTestId('blox-partner-name')).toHaveCount(names.length);
  for (let index = 0; index < names.length; index++) {
    await expect(page.getByTestId('blox-partner-name').nth(index)).toHaveValue(names[index]);
  }
}

test('partners edit reorder undo save reopen and publish; shared data stays live @ci', async ({ page, browser, baseURL }, info) => {
  test.setTimeout(150000);
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, viewport: info.project.use.viewport });
  try {
    const publicPage = await visitor.newPage();
    for (const [language, url] of [['zh-CN', '/'], ['en', '/en/'], ['ja', '/ja/']]) {
      expect((await publicPage.goto(url)).status()).toBe(200);
      await expect(publicPage.getByRole('link', { name: 'Shared ' + language, exact: true })).toBeVisible();
      await expect(publicPage.getByRole('link', { name: 'Disabled partner', exact: true })).toHaveCount(0);
    }
    await openEditor(page);
    await selectPartners(page);
    await expect(page.getByTestId('blox-partners-dynamic')).toBeChecked();
    await expect(page.getByTestId('blox-content-source').getByRole('link')).toHaveAttribute('href', /\/admin\/link.php\?lang=zh-CN/);
    await performPreviewUpdate(page, () => page.getByTestId('blox-partners-custom').check());
    await performPreviewUpdate(page, () => page.getByTestId('blox-partners-copy').click());
    await expect(page.getByTestId('blox-partner-name')).toHaveValue('Shared zh-CN');
    await performPreviewUpdate(page, () => page.getByTestId('blox-partner-name').fill('Local Alpha'));
    await performPreviewUpdate(page, () => page.getByTestId('blox-partner-url').fill('/contact.html?partner=alpha'));
    await performPreviewUpdate(page, () => page.getByTestId('blox-partner-logo').fill('/assets/images/demo/banner-2.svg'));
    await performPreviewUpdate(page, () => page.getByTestId('blox-partners-add').click());
    await performPreviewUpdate(page, () => page.getByTestId('blox-partner-name').nth(1).fill('Local Beta'));
    await performPreviewUpdate(page, () => page.getByTestId('blox-partner-url').nth(1).fill('/contact.html?partner=beta'));
    await performPreviewUpdate(page, () => page.getByTestId('blox-partner-up').nth(1).click());
    await expectNames(page, ['Local Beta', 'Local Alpha']);
    await performPreviewUpdate(page, () => page.getByTestId('blox-partner-remove').first().click());
    await expect(page.getByTestId('blox-partner-row')).toHaveCount(1);
    await performPreviewUpdate(page, () => clickAction(page, 'undo'));
    await expect(page.getByTestId('blox-partner-row')).toHaveCount(2);
    await expect((await frame(page)).getByRole('link', { name: 'Local Alpha', exact: true })).toHaveAttribute('href', '/contact.html?partner=alpha');
    await save(page);
    await openEditor(page);
    await selectPartners(page);
    await expectNames(page, ['Local Beta', 'Local Alpha']);
    await publicPage.goto('/');
    await expect(publicPage.getByRole('link', { name: 'Local Alpha', exact: true })).toHaveCount(0);
    await save(page, true);
    await publicPage.goto('/');
    await expect(publicPage.getByRole('link', { name: /^Local (Alpha|Beta)$/ })).toHaveCount(2);
    expect(await publicPage.getByRole('link', { name: /^Local (Alpha|Beta)$/ }).evaluateAll(nodes => nodes.map(node => node.title))).toEqual(['Local Beta', 'Local Alpha']);
    await expect(publicPage.getByRole('img', { name: 'Local Alpha', exact: true })).toBeVisible();
    expect(await publicPage.getByRole('img', { name: 'Local Alpha', exact: true }).evaluate(node => node.complete && node.naturalWidth > 0)).toBe(true);
    await expect(publicPage.locator('#ik-adminbar')).toHaveCount(0);
    await page.screenshot({ path: info.outputPath('partners-panel.png') });
    await performPreviewUpdate(page, () => page.getByTestId('blox-partners-dynamic').check());
    await expect((await frame(page)).getByRole('link', { name: 'Shared zh-CN', exact: true })).toBeVisible();
    await save(page, true);
    fixture('update');
    await publicPage.goto('/');
    await expect(publicPage.getByRole('link', { name: 'Shared updated', exact: true })).toBeVisible();
    await openEditor(page);
    await selectPartners(page);
    await performPreviewUpdate(page, () => page.getByTestId('blox-partners-custom').check());
    await expectNames(page, ['Local Beta', 'Local Alpha']);
    // Return to the saved dynamic state; no dirty draft is left in the worker.
    await performPreviewUpdate(page, () => clickAction(page, 'undo'));
    await waitPreviewSettled(page);
    await expectClean(page);
  } finally { await visitor.close(); }
});
