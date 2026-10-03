// 2.0.4 圆角与阴影 token：在「全站样式 › 圆角与阴影」改一次，前台所有用到它（含引用它的 token）的地方一起变。
// 复用金丝雀夹具：容器 #cn-scale 用 radius_token=card（card 引用 md）、shadow_token=md。
const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'design-canary-fixture.php'), action], { cwd: root, encoding: 'utf8' });

let state;
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fixture('restore');
  state = JSON.parse(fixture('seed').trim().split('\n').pop());
});
test.afterAll(() => fixture('restore'));
test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'design page baseline');
  test.setTimeout(120000);
});

async function frontRadius(browser, baseURL) {
  const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const page = await context.newPage();
    expect((await page.goto(state.url)).status()).toBe(200);
    return await page.locator('#cn-scale').evaluate(el => {
      const style = getComputedStyle(el);
      return { radius: style.borderTopLeftRadius, shadow: style.boxShadow };
    });
  } finally {
    await context.close();
  }
}

test('editing a radius token updates every consumer, including tokens that reference it @ci', async ({ page, browser, baseURL }) => {
  const before = await frontRadius(browser, baseURL);
  expect(before.radius).toBe('10px');
  expect(before.shadow).toContain('rgba(15, 23, 42, 0.14)');

  await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-design-page-tab-scale').click();
  await expect(page.getByTestId('blox-design-scale-row-radius-card')).toBeVisible();
  // 引用在预览里解析成被引用项的值
  await expect(page.getByTestId('blox-design-scale-row-radius-card').locator('span[aria-hidden="true"]')).toHaveAttribute('style', /border-radius:\s*10px/);

  const value = page.getByTestId('blox-design-scale-value-radius-md');
  await value.fill('22px');
  const saved = page.waitForResponse(r => r.url().includes('/admin/blox_design_api.php') && r.request().method() === 'POST');
  await page.getByTestId('blox-design-scale-save-radius-md').click();
  expect((await (await saved).json()).code).toBe(0);

  const after = await frontRadius(browser, baseURL);
  expect(after.radius).toBe('22px');

  // 非法值整单拒绝，不落盘
  await value.fill('22px;}body{display:none');
  const rejected = page.waitForResponse(r => r.url().includes('/admin/blox_design_api.php') && r.request().method() === 'POST');
  await page.getByTestId('blox-design-scale-save-radius-md').click();
  expect((await (await rejected).json()).code).not.toBe(0);
  expect((await frontRadius(browser, baseURL)).radius).toBe('22px');
});
