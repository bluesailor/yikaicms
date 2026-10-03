const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'redirects-fixture.php'), action], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

// 301 跳转（核心免费版）：后台添加、批量导入、冲突与格式错误提示；访客一次直达目标，带上原查询串。
test('admin manages 301 redirects and visitors land on the target in one hop @ci', async ({ page, browser, baseURL }) => {
  test.setTimeout(90000);
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const get = url => visitor.request.get(url, { maxRedirects: 0 });
  const saveForm = async () => {
    const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/redirects.php');
    await page.locator('#editForm button[type=submit]').click();
    return (await (await response).json());
  };
  try {
    await page.goto('/admin/redirects.php');
    await expect(page.locator('a[href="/admin/redirects.php"]').first()).toBeVisible();
    await page.getByTestId('redirect-add').click();
    await page.locator('#editSource').fill('/old-gear-page');
    await page.locator('#editTarget').fill('/news.html');
    expect((await saveForm()).code).toBe(0);
    await page.waitForLoadState('load');

    const hop = await get('/old-gear-page/?utm_source=mail');
    expect(hop.status()).toBe(301);
    expect(hop.headers().location).toBe('/news.html?utm_source=mail');
    expect((await get('/old-gear-page')).headers().location).toBe('/news.html');

    await page.goto('/admin/redirects.php');
    await expect(page.getByTestId('redirect-table')).toContainText('/old-gear-page');
    await page.getByTestId('redirect-add').click();
    await page.locator('#editSource').fill('/admin/x/');
    await page.locator('#editTarget').fill('/');
    expect((await saveForm()).code).not.toBe(0);
    await page.locator('#editSource').fill('/loop-a/');
    await page.locator('#editTarget').fill('/loop-a');
    expect((await saveForm()).code).not.toBe(0);
    await page.locator('#editModal button[onclick="closeModals()"]').last().click();

    await page.getByTestId('redirect-import-open').click();
    await page.locator('#importLines').fill(['# WordPress', '/2019/05/old-post/ /news.html', '/product-category/gears/,/product.html,302', '/bad/ javascript:alert(1)'].join('\n'));
    const imported = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/redirects.php');
    await page.locator('#importForm button[type=submit]').click();
    const result = await (await imported).json();
    expect(result.data.saved).toBe(2);
    expect(result.data.errors).toHaveLength(1);
    await expect(page.getByTestId('redirect-import-result')).toContainText('4');
    const temp = await get('/product-category/gears/');
    expect(temp.status()).toBe(302);
    expect(temp.headers().location).toBe('/product.html');

    await page.goto('/admin/redirects.php?q=gears');
    await expect(page.getByTestId('redirect-table').locator('tbody tr')).toHaveCount(1);
    page.once('dialog', d => d.accept());
    const deleted = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/redirects.php');
    await page.getByTestId('redirect-table').getByRole('button', { name: /删除|Delete/ }).click();
    expect((await (await deleted).json()).code).toBe(0);
    expect((await get('/product-category/gears/')).status()).toBe(404);
  } finally {
    fixture('restore');
    await visitor.close().catch(() => {});
  }
});
