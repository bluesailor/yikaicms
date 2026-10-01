const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'scheduled-publish-fixture.php'), ...args], { cwd: root }).toString();
const state = (table, id) => JSON.parse(fixture('status', table, String(id)));

// 文章定时发布与产品定时上架（2.0.3）：编辑页有完整入口，未来时间不会提前上线，到点自动上线。
test.afterAll(() => { fixture('cleanup', 'contents'); fixture('cleanup', 'products'); });

const local = (offsetMs) => {
  const d = new Date(Date.now() + offsetMs);
  const pad = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

async function save(page, url, fields) {
  const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const res = await page.request.post(url, { form: { _token: csrf, ...fields }, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
  return res.json();
}

test('articles and products can be scheduled and go live on time @ci', async ({ page, request }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');

  // 文章编辑页：有「定时发布」，选中后提示并要求填时间；「已发布」配未来时间也会提前说明
  await page.goto('/admin/article_edit.php');
  const status = page.getByTestId('article-status');
  await expect(status.locator('option[value="3"]')).toHaveCount(1);
  await status.selectOption('3');
  await expect(page.getByTestId('article-schedule-hint')).toBeVisible();
  await expect(page.getByTestId('article-publish-time')).toHaveAttribute('required', '');
  await status.selectOption('1');
  await page.getByTestId('article-publish-time').fill(local(86400000));
  await expect(page.getByTestId('article-schedule-hint')).toBeVisible();

  // 定时却没填时间：拒绝
  expect((await save(page, '/admin/article_edit.php', { title: 'E2E 定时文章', status: '3', publish_time: '' })).code).not.toBe(0);
  // 「已发布」+ 未来时间：存成定时，不会提前上线
  const article = await save(page, '/admin/article_edit.php', { title: 'E2E 定时文章', status: '1', publish_time: local(86400000) });
  expect(article.code).toBe(0);
  const articleId = article.data.id;
  expect(Number(state('contents', articleId).status)).toBe(3);

  await page.goto('/admin/article.php?status=3');
  await expect(page.locator('tr', { hasText: 'E2E 定时文章' }).getByTestId('article-scheduled-badge')).toBeVisible();

  // 产品编辑页：同样的入口
  await page.goto('/admin/product_edit.php');
  const productStatus = page.getByTestId('product-status');
  await productStatus.selectOption('3');
  await expect(page.getByTestId('product-schedule-hint')).toBeVisible();
  const product = await save(page, '/admin/product_edit.php', { title: 'E2E 定时产品', status: '3', publish_time: local(86400000) });
  expect(product.code).toBe(0);
  const productId = product.data.id;
  expect(Number(state('products', productId).status)).toBe(3);
  await page.goto('/admin/product.php?status=3');
  await expect(page.locator('tr', { hasText: 'E2E 定时产品' }).getByTestId('product-scheduled-badge')).toBeVisible();

  // 到点：任何一次前台访问都会把它们上线
  fixture('due', 'contents', String(articleId));
  fixture('due', 'products', String(productId));
  expect((await request.get('/')).status()).toBe(200);
  expect(Number(state('contents', articleId).status)).toBe(1);
  expect(Number(state('products', productId).status)).toBe(1);
});
