const { test, expect } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { openPageEditor, frame, performPagePreviewUpdate, expectClean } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

// 夹具：一份「独立页面」整页模板（隐藏页头/页尾/标题/面包屑/侧栏 + 页内锚点）。
// 2026-09-16 随包整页模板缩减后，本地目录不再有带 page_*_hidden 的模板；被验证的是
// 导入→历史→保存→发布这条链路，不该为测试在分发包里保留一款模板。
const FIXTURE = path.resolve(__dirname, 'standalone-page-fixture.php');
const ROOT = path.resolve(__dirname, '../..');
const php = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [FIXTURE, ...args], { cwd: ROOT, encoding: 'utf8' });

test('standalone page import carries its frame through history, save and publication @local', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'One write path with desktop and mobile visitor checks');
  test.setTimeout(120000);
  const installed = JSON.parse(php('seed').trim());
  try {
    await openPageEditor(page, fixtures.blox_page);
    const settings = () => page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).docSettings)));
    const before = await settings();
    const countBefore = await page.getByTestId('blox-tree-section').count();
    await page.getByTestId('blox-page-library-open').click();
    const template = page.locator(`[data-testid="blox-template-item"][data-template-key="local:${installed.id}"]`);
    await expect(template).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await performPagePreviewUpdate(page, () => template.getByTestId('blox-template-replace').click());
    const standalone = {
      page_header_hidden: true, page_footer_hidden: true,
      page_title_hidden: true, page_breadcrumb_hidden: true, page_sidebar_hidden: true,
    };
    expect(await settings()).toMatchObject(standalone);
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(installed.sections);
    await expect((await frame(page)).locator('#standalone-header')).toBeVisible();
    await expect((await frame(page)).locator('[data-yk-context-area="header"], [data-yk-context-area="footer"]')).toHaveCount(0);

    await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
    expect(await settings()).toEqual(before);
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(countBefore);
    await expect((await frame(page)).locator('#standalone-header')).toHaveCount(0);
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
    await expect((await frame(page)).locator('#standalone-header')).toBeVisible();
    await command('publish', 'blox-publish-page');

    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
      const visitor = await context.newPage();
      for (const width of [1440, 390]) {
        await visitor.setViewportSize({ width, height: 1000 });
        expect((await visitor.goto(fixtures.blox_page_url)).status()).toBe(200);
        // 页面外框被模板关掉：站点页头/页尾都不出现，页面自带的锚点区块照常渲染
        await expect(visitor.locator('#standalone-header')).toBeVisible();
        await expect(visitor.locator('#standalone-reservation')).toBeVisible();
        await expect(visitor.locator('#siteHeader, body > footer, .yk-blox-footer')).toHaveCount(0);
        await expect(visitor.locator('h1')).toHaveCount(1);
        expect(await visitor.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await visitor.locator('#standalone-header a[href="#standalone-reservation"]').click();
        await expect(visitor).toHaveURL(/#standalone-reservation$/);
        await visitor.evaluate(() => scrollTo(0, 0));
        await visitor.screenshot({ path: info.outputPath(`standalone-${width}.png`), fullPage: true });
      }
      // 其他页面不受影响：首页仍有站点页头/页尾
      expect((await visitor.goto('/')).status()).toBe(200);
      await expect(visitor.locator('#siteHeader')).toBeVisible();
      await expect(visitor.locator('footer')).toBeVisible();
    } finally { await context.close(); }
  } finally {
    php('cleanup');
  }
});
