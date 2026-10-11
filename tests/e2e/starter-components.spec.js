// 预置组件（v2.1.0）：8 个组件放在同一页面上都渲染出来，文字、图片、链接齐全，三个视口都不横向溢出。
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'starter-components-fixture.php'), ...args], { cwd: root }).toString();
let state;

test.beforeAll(() => {
  fixture('restore');
  state = JSON.parse(fixture('seed').trim().split('\n').pop());
});
test.afterAll(() => fixture('restore'));

test('starter components render on a page @ci', async ({ page }, info) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const response = await page.goto(state.url);
  expect(response.status()).toBe(200);
  const main = page.locator('main');
  for (const text of ['快速部署', '标准版', '从选型到交付只用了三周', '准备好开始了吗？', '林晓雯', '交货周期是多久？', '通过 ISO 9001 质量管理体系认证', '电话咨询']) {
    await expect(main.getByText(text, { exact: false }).first()).toBeVisible();
  }
  await expect(main.locator('a[href="tel:4008001234"]')).toBeVisible();
  await expect(main.locator('img[src*="avatar-1.svg"]')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  expect(errors).toEqual([]);
  await main.screenshot({ path: info.outputPath('starter-components.png') });
});
