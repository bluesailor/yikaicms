const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'product-custom-urls-fixture.php'), action], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

test('custom product and category URLs save, search, paginate and resolve anonymously @ci', async ({ page, browser, baseURL }, info) => {
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  const state = JSON.parse(fixture('setup'));
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const publicPage = await visitor.newPage();
  const save = async (endpoint, expected = 0) => {
    const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === endpoint);
    await page.locator('#editForm button[type=submit]').click();
    const result = await (await response).json();
    if (expected === 0) expect(result.code, JSON.stringify(result)).toBe(0);
    else expect(result.code).not.toBe(0);
  };
  try {
    await page.goto('/admin/product_category.php');
    await page.getByRole('row').filter({ hasText: 'WP route category' }).getByTestId('product-category-edit').click();
    await page.locator('#editCustomUrl').fill('/products/medical/');
    await save('/admin/product_category.php');
    await page.waitForEvent('load');
    await page.getByRole('row').filter({ hasText: 'WP route category' }).getByTestId('product-category-edit').click();
    await expect(page.locator('#editCustomUrl')).toHaveValue('/products/medical/');
    await page.goto(`/admin/product_edit.php?id=${state.ids[0]}`);
    await page.locator('#productCustomUrl').fill('/footmaster/gd-60/');
    await save('/admin/product_edit.php');
    await page.waitForURL('**/admin/product.php');
    await page.goto(`/admin/product_edit.php?id=${state.ids[0]}`);
    await expect(page.locator('#productCustomUrl')).toHaveValue('/footmaster/gd-60/');
    await info.attach('custom-product-url-field', { body: await page.locator('#productCustomUrl').screenshot(), contentType: 'image/png' });
    // Cross-type collision must leave the previously saved URL untouched.
    await page.locator('#productCustomUrl').fill('/products/medical');
    await save('/admin/product_edit.php', 1);
    expect((await publicPage.goto('/footmaster/gd-60/')).status()).toBe(200);
    await expect(publicPage.locator('h1').first()).toContainText('WP Gateway 1');
    await expect(publicPage.locator('link[rel=canonical]')).toHaveAttribute('href', `${baseURL}/footmaster/gd-60/`);
    const slash = await visitor.request.get('/footmaster/gd-60', { maxRedirects: 0 });
    expect(slash.status()).toBe(301);
    expect(slash.headers().location).toBe('/footmaster/gd-60/');
    expect((await publicPage.goto('/products/medical/')).status()).toBe(200);
    await expect(publicPage.locator('a[href="/footmaster/gd-60/"]').first()).toBeVisible();
    const next = publicPage.locator('a[href="/products/medical/page/2/"]').first();
    await expect(next).toBeVisible();
    await next.click();
    await expect(publicPage).toHaveURL(/\/products\/medical\/page\/2\/$/);
    await expect(publicPage.locator('link[rel=canonical]')).toHaveAttribute('href', `${baseURL}/products/medical/page/2/`);
    const search = publicPage.locator('input[name=keyword]:visible').first();
    await search.fill('WP Gateway 1');
    await search.press('Enter');
    await expect(publicPage).toHaveURL(/\/products\/medical\/\?/);
    await expect(publicPage.locator('a[href="/footmaster/gd-60/"]').first()).toBeVisible();
    expect((await visitor.request.get(`/footmaster/gd-60/?id=${state.ids[1]}`)).status()).toBe(200);
    await publicPage.goto(`/footmaster/gd-60/?id=${state.ids[1]}`);
    await expect(publicPage.locator('h1').first()).toContainText('WP Gateway 1');
    const sitemap = await visitor.request.get('/sitemap.xml');
    expect(await sitemap.text()).toContain('/footmaster/gd-60/');
    expect(await sitemap.text()).toContain('/products/medical/');
    // A .html custom path must win over the Dispatcher's generic page mapping.
    await page.goto(`/admin/product_edit.php?id=${state.ids[0]}`);
    await page.locator('#productCustomUrl').fill('/brand/gd-60.html');
    await save('/admin/product_edit.php');
    await page.waitForURL('**/admin/product.php');
    expect((await publicPage.goto('/brand/gd-60.html')).status()).toBe(200);
    await expect(publicPage.locator('h1').first()).toContainText('WP Gateway 1');
    fixture('dynamic');
    expect((await publicPage.goto(`/index.php?yk_route=product&id=${state.ids[0]}`)).status()).toBe(200);
    await expect(publicPage.locator('h1').first()).toContainText('WP Gateway 1');
    await expect(publicPage.locator('link[rel=canonical]')).toHaveAttribute('href', /index\.php\?yk_route=product/);
    await publicPage.goto(`/index.php?yk_route=product_list&cat=wp-route-category`);
    await expect(publicPage.locator('a[href*="yk_route=product&"]').first()).toBeVisible();
  } finally {
    fixture('restore');
    await visitor.close().catch(() => {});
  }
});
