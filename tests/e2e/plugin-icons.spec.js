const { test, expect } = require('./site-diagnostics');

// 插件图标（2.0.3）：已安装列表按插件显示各自的图标，而不是统一的剪贴板。
test('installed plugins show their own icons @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  await page.goto('/admin/plugin.php');
  const icons = page.getByTestId('plugin-installed-icon');
  await expect(icons.first()).toBeVisible();
  const classes = await icons.evaluateAll((els) => els.map((el) => el.className));
  expect(classes.length).toBeGreaterThan(1);
  for (const cls of classes) expect(cls).toMatch(/\bti-[a-z0-9-]+\b/);
  const shop = page.locator('#plugin-shop').getByTestId('plugin-installed-icon');
  if (await shop.count()) await expect(shop).toHaveClass(/ti-shopping-bag/);
  // 不再是所有插件同一个图标
  expect(new Set(classes).size).toBeGreaterThan(1);
});
