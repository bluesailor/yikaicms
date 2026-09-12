const { test, expect } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, frame, performPagePreviewUpdate, expectClean } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

test('standalone page import carries its frame through history, save and publication @local', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'One write path with desktop and mobile visitor checks');
  test.setTimeout(120000);
  await openPageEditor(page, fixtures.blox_page);
  const settings = () => page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).docSettings)));
  const before = await settings();
  const countBefore = await page.getByTestId('blox-tree-section').count();
  await page.getByTestId('blox-page-library-open').click();
  const template = page.locator('[data-testid="blox-template-item"][data-template-key="builtin:restaurant-landing"]');
  await expect(template).toBeVisible();
  page.once('dialog', dialog => dialog.accept());
  await performPagePreviewUpdate(page, () => template.getByTestId('blox-template-replace').click());
  const standalone = {
    page_header_hidden: true, page_footer_hidden: true,
    page_title_hidden: true, page_breadcrumb_hidden: true, page_sidebar_hidden: true,
  };
  expect(await settings()).toMatchObject(standalone);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(13);
  await expect((await frame(page)).locator('#restaurant-header')).toBeVisible();
  await expect((await frame(page)).locator('[data-yk-context-area="header"], [data-yk-context-area="footer"]')).toHaveCount(0);

  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  expect(await settings()).toEqual(before);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(countBefore);
  await expect((await frame(page)).locator('#restaurant-header')).toHaveCount(0);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  expect(await settings()).toMatchObject(standalone);

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
  expect(await settings()).toMatchObject(standalone);
  await expect((await frame(page)).locator('#restaurant-header')).toBeVisible();
  await command('publish', 'blox-publish-page');

  const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const visitor = await context.newPage();
    for (const width of [1440, 390]) {
      await visitor.setViewportSize({ width, height: 1000 });
      expect((await visitor.goto(fixtures.blox_page_url)).status()).toBe(200);
      await expect(visitor.locator('#restaurant-header')).toBeVisible();
      await expect(visitor.locator('#restaurant-footer')).toBeVisible();
      await expect(visitor.locator('#siteHeader, body > footer, .yk-blox-footer')).toHaveCount(0);
      await expect(visitor.getByText('关于我们', { exact: true })).toHaveCount(2);
      await expect(visitor.locator('h1')).toHaveCount(1);
      const images = visitor.locator('img[src*="/blox-templates/restaurant-"]');
      expect(await images.count()).toBeGreaterThanOrEqual(5);
      for (const img of await images.all()) {
        await img.scrollIntoViewIfNeeded();
        await expect.poll(() => img.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
      }
      expect(await visitor.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
      await visitor.locator('#restaurant-header a[href="#restaurant-menu"]').click();
      await expect(visitor).toHaveURL(/#restaurant-menu$/);
      await visitor.evaluate(() => scrollTo(0, 0));
      await visitor.screenshot({ path: info.outputPath(`restaurant-${width}.png`), fullPage: true });
    }
    expect((await visitor.goto('/')).status()).toBe(200);
    await expect(visitor.locator('#siteHeader')).toBeVisible();
    await expect(visitor.locator('footer')).toBeVisible();
  } finally { await context.close(); }
});
