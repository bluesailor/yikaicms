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

  // 文章编辑页（2.0.5）：立即发布 / 草稿 / 定时发布；新建默认立即发布、不显示时间；
  // 选定时发布才出现日期与时刻（默认当前时间、必填），两者合成隐藏字段 publish_time
  await page.goto('/admin/article_edit.php');
  const status = page.getByTestId('article-status');
  await expect(status.locator('option[value="3"]')).toHaveCount(1);
  await expect(status).toHaveValue('1');
  await expect(page.getByTestId('article-publish-time-row')).toBeHidden();
  await status.selectOption('3');
  await expect(page.getByTestId('article-publish-time-row')).toBeVisible();
  await expect(page.getByTestId('article-schedule-hint')).toBeVisible();
  await expect(page.getByTestId('article-publish-date')).toHaveAttribute('required', '');
  // 默认日期是站点时区的「今天」：CI 跑在 UTC，晚上 16 点后站点（+8）已是第二天，所以按前后一天比对
  const defaultDate = await page.getByTestId('article-publish-date').inputValue();
  expect([local(-86400000), local(0), local(86400000)].map((v) => v.slice(0, 10))).toContain(defaultDate);
  const tomorrow = local(86400000).slice(0, 10);
  await page.getByTestId('article-publish-date').fill(tomorrow);
  await page.getByTestId('article-publish-clock').fill('09:30');
  await expect(page.getByTestId('article-publish-time')).toHaveValue(`${tomorrow}T09:30`);
  await status.selectOption('1');
  await expect(page.getByTestId('article-publish-time-row')).toBeHidden();

  // 定时却没填时间：拒绝
  expect((await save(page, '/admin/article_edit.php', { title: 'E2E 定时文章', status: '3', publish_time: '' })).code).not.toBe(0);
  // 「立即发布」（publish_now=1）：发布时间记为现在，忽略表单里残留的时间
  const now = await save(page, '/admin/article_edit.php', { title: 'E2E 定时对照：立即发布', status: '1', publish_now: '1', publish_time: local(86400000) });
  expect(now.code).toBe(0);
  const nowState = state('contents', now.data.id);
  expect(Number(nowState.status)).toBe(1);
  expect(Math.abs(Number(nowState.publish_time) - Date.now() / 1000)).toBeLessThan(120);
  // 「已发布」+ 未来时间（2.0.4 起）：按用户的选择立即上线，时间只作显示日期，不自动改成定时
  const published = await save(page, '/admin/article_edit.php', { title: 'E2E 定时对照：选已发布', status: '1', publish_time: local(86400000) });
  expect(published.code).toBe(0);
  expect(Number(state('contents', published.data.id).status)).toBe(1);
  // 「定时发布」+ 未来时间：存成定时，不会提前上线
  const article = await save(page, '/admin/article_edit.php', { title: 'E2E 定时文章', status: '3', publish_time: local(86400000) });
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
