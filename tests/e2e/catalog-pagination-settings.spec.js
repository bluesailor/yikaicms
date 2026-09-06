const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');
function fixture(file, action) {
  execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), action], { cwd: root });
}
test.beforeAll(() => {
  fixture('catalog-baseline-fixture.php', 'query');
  fixture('catalog-search-fixture.php', 'seed');
});
test.afterAll(() => {
  fixture('catalog-search-fixture.php', 'cleanup');
  fixture('catalog-baseline-fixture.php', 'restore');
});
async function save(page) {
  const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/'));
  await page.locator('#settingForm button[type="submit"]').click();
  expect((await (await response).json()).code).toBe(0);
}
test('pagination settings save through browser and drive real product/article pages @ci', async ({ page }, info) => {
  await page.goto('/admin/setting.php?tab=pagination');
  for (const kind of ['product', 'article', 'case', 'download', 'job']) {
    await page.locator(`[name="settings[catalog_${kind}_page_size]"]`).fill('8');
  }
  await save(page);
  await page.reload();
  await page.screenshot({ path: info.outputPath('pagination-settings.png'), fullPage: true });
  for (const kind of ['product', 'article', 'case', 'download', 'job']) {
    await expect(page.locator(`[name="settings[catalog_${kind}_page_size]"]`)).toHaveValue('8');
  }
  for (const route of ['product_list', 'list&slug=news']) {
    await page.goto(`/index.php?yk_route=${route}&keyword=Catalog+Zero`);
    const titles = page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ });
    await expect(titles).toHaveCount(8);
    await page.getByRole('link', { name: '2', exact: true }).click();
    await expect(titles).toHaveCount(8);
    await page.getByRole('link', { name: '3', exact: true }).click();
    await expect(titles).toHaveCount(6);
  }
  await page.goto('/admin/product_setting.php');
  await expect(page.locator('[name="catalog_product_page_size"]')).toHaveValue('8');
  await page.locator('[name="catalog_product_page_size"]').fill('12');
  await save(page);
  await page.goto('/admin/setting.php?tab=pagination');
  await expect(page.locator('[name="settings[catalog_product_page_size]"]')).toHaveValue('12');
  await page.goto('/index.php?yk_route=product_list&keyword=Catalog+Zero');
  await expect(page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ })).toHaveCount(12);
});
test('invalid pagination payloads are rejected without changing saved value @ci', async ({ page }) => {
  await page.goto('/admin/setting.php?tab=pagination');
  const field = page.locator('[name="settings[catalog_product_page_size]"]');
  const original = await field.inputValue();
  const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  for (const value of ['0', '101', '-1', '1.5', ['8']]) {
    const result = await page.request.post('/admin/setting.php?tab=pagination', { form: {
      _token: token,
      [Array.isArray(value) ? 'settings[catalog_product_page_size][]' : 'settings[catalog_product_page_size]']: String(value),
    } });
    expect(result.status()).toBe(422);
    expect((await result.json()).code).not.toBe(0);
  }
  await page.reload();
  await expect(field).toHaveValue(original);
});
