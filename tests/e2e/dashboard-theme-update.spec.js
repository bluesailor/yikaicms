// 2.0.6：主题更新提醒不再占控制台一栏，收进右上角铃铛（检测结果由服务端记下，铃铛只读本地状态）。
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { observeConsole } = require('./helpers');

const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'admin-notice-fixture.php'), ...args], { cwd: root }).toString();

test.afterAll(() => fixture('clear'));

test('theme updates appear in the notification bell, separate from CMS updates @ci', async ({ page }) => {
  const consoleEntries = observeConsole(page);
  fixture('themes', '1');
  // 控制台的后台检测不出网：CMS 检查回「没有更新」，主题检测原样短路
  await page.route(/\/admin\/upgrade_online\.php$/, (route) => route.fulfill({ json: { code: 0, data: { has_update: false } } }));
  await page.route(/\/admin\/index\.php\?action=theme_updates$/, (route) => route.fulfill({ json: { code: 0, data: { count: 1, updates: [] } } }));

  await page.goto('/admin/index.php', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('dashboard-theme-update')).toHaveCount(0);
  await page.getByTestId('admin-bell-button').click();
  const item = page.getByTestId('admin-notice-themes');
  await expect(item).toBeVisible();
  await expect(item.getByRole('link')).toHaveAttribute('href', '/admin/theme.php?tab=market');
  await expect(page.getByTestId('admin-notice-update')).toHaveCount(0);

  const panel = await page.getByTestId('admin-bell-panel').boundingBox();
  const viewport = page.viewportSize();
  expect(panel.x).toBeGreaterThanOrEqual(0);
  expect(panel.x + panel.width).toBeLessThanOrEqual(viewport.width + 1);
  expect(consoleEntries, 'bell should keep the console clean').toEqual([]);
});
