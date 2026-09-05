const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-search-fixture.php'), action], { cwd: root });
test.beforeAll(() => fixture('seed'));
test.afterAll(() => fixture('cleanup'));

test('admin list sizes persist separately and preserve filters @ci', async ({ page }, info) => {
  for (const kind of ['product', 'article', 'download', 'job']) {
    await page.goto(`/admin/${kind}.php?per_page=20&keyword=Catalog%20Zero&status=1`);
    const select = page.locator('select[name="per_page"]');
    await expect(select).toHaveValue('20');
    if (['product', 'article'].includes(kind)) {
      await expect(page.locator('tbody tr').filter({ hasText: 'Catalog Zero' })).toHaveCount(20);
    }
    await Promise.all([page.waitForNavigation(), select.selectOption('50')]);
    await expect(select).toHaveValue('50');
    expect(new URL(page.url()).searchParams.get('keyword')).toBe('Catalog Zero');
    expect(new URL(page.url()).searchParams.get('status')).toBe('1');
    if (['product', 'article'].includes(kind)) {
      await expect(page.locator('tbody tr').filter({ hasText: 'Catalog Zero' })).toHaveCount(22);
    }
    await Promise.all([page.waitForNavigation(), select.selectOption('100')]);
    await expect(select).toHaveValue('100');
    await page.goto(`/admin/${kind}.php`);
    await expect(select).toHaveValue('100');
    await page.goto(`/admin/${kind}.php?per_page[]=50`);
    await expect(select).toHaveValue('100');
    await page.goto(`/admin/${kind}.php?per_page=999999`);
    await expect(select).toHaveValue('100');
  }
  await page.goto('/admin/product.php?per_page=20');
  await page.goto('/admin/article.php');
  await expect(page.locator('[name="per_page"]')).toHaveValue('100');
  await page.goto('/admin/product.php?keyword=Catalog%20Zero&status=1&page=2&per_page=50');
  await expect(page.locator('tbody tr').filter({ hasText: 'Catalog Zero' })).toHaveCount(22);
  await page.screenshot({ path: info.outputPath('admin-list-page-size.png'), fullPage: true });
});
