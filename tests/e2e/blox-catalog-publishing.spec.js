const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, frame, performPagePreviewUpdate, expectClean } = require('./helpers');
const root = path.resolve(__dirname, '../..');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const fixture = (file, action) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), action], { cwd: root });
test.beforeAll(() => {
  fixture('catalog-baseline-fixture.php', 'query');
  fixture('catalog-search-fixture.php', 'seed');
});
test.afterAll(() => {
  fixture('catalog-search-fixture.php', 'cleanup');
  fixture('catalog-baseline-fixture.php', 'restore');
});

for (const [kind, type, id, route] of [
  ['product', 'product-catalog', fixtures.product_page, 'product_list'],
  ['article', 'content-catalog', fixtures.channel_list, 'list&slug=news'],
]) {
  test(`${kind} Blox layout reopens, publishes and retains real search pagination @ci`, async ({ page, browser, baseURL }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'Catalog persistence baseline');
    test.setTimeout(90000);
    page.setDefaultTimeout(12000);
    await page.goto('/admin/setting.php?tab=pagination');
    await page.locator(`[name="settings[catalog_${kind}_page_size]"]`).fill('8');
    const settingsSaved = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/setting.php');
    await page.locator('#settingForm button[type="submit"]').click();
    expect((await (await settingsSaved).json()).code).toBe(0);
    async function selectCatalog() {
      const row = page.getByTestId('blox-tree-element').and(page.locator(`[data-element-type="${type}"]`));
      await page.getByTestId('blox-tree-section').filter({ has: row }).getByTestId('blox-tree-section-label').click();
      await row.locator('[data-element-drag-handle]').first().click();
    }
    await openPageEditor(page, id);
    await selectCatalog();
    await performPagePreviewUpdate(page, () => page.locator('[data-control-key="layout"] select').selectOption('grid'));
    await performPagePreviewUpdate(page, () => page.locator('[data-control-key="show_categories"] input').uncheck());
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
    await openPageEditor(page, id);
    await selectCatalog();
    await expect(page.locator('[data-control-key="layout"] select')).toHaveValue('grid');
    await expect(page.locator('[data-control-key="show_categories"] input')).not.toBeChecked();
    await expect((await frame(page)).locator(`[data-${type}] input[name="keyword"]`)).toBeVisible();
    await command('publish', 'blox-publish-page');
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
      const visitor = await context.newPage(), errors = [];
      visitor.on('pageerror', error => errors.push(error.message));
      visitor.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
      expect((await visitor.goto(`/index.php?yk_route=${route}`)).status()).toBe(200);
      await expect(visitor.locator('#ik-adminbar')).toHaveCount(0);
      const catalog = visitor.locator(`[data-${type}]`);
      await expect(catalog).toBeVisible();
      await expect(catalog.locator('.category-item')).toHaveCount(0);
      await catalog.locator('input[name="keyword"]').fill('Catalog Zero');
      await catalog.locator('input[name="keyword"]').press('Enter');
      await expect(visitor).toHaveURL(/keyword=Catalog(?:\+|%20)Zero/);
      const titles = catalog.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ });
      await expect(titles).toHaveCount(8);
      const firstPage = await titles.allTextContents();
      await visitor.getByRole('link', { name: '2', exact: true }).click();
      await expect(titles).toHaveCount(8);
      expect(await titles.allTextContents()).not.toEqual(firstPage);
      await expect(catalog.locator('input[name="keyword"]')).toHaveValue('Catalog Zero');
      await visitor.getByRole('link', { name: '3', exact: true }).click();
      await expect(titles).toHaveCount(6);
      const finalUrl = new URL(visitor.url());
      expect(finalUrl.searchParams.get('yk_route')).toBe(kind === 'product' ? 'product_list' : 'list');
      expect(finalUrl.searchParams.get('page')).toBe('3');
      expect(finalUrl.searchParams.get('keyword')).toBe('Catalog Zero');
      if (kind === 'article') expect(finalUrl.searchParams.get('slug')).toBe('news');
      expect(errors).toEqual([]);
    } finally { await context.close(); }
  });
}
