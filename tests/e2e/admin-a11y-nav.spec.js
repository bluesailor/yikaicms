const { test, expect } = require('./site-diagnostics');

// 后台导航无障碍（2.0.3）：跳到正文、当前页、分组展开按钮、手机抽屉是模态对话框。

test('skip link, current page and group toggles are exposed to keyboard and screen readers @ci', async ({ page }) => {
  await page.goto('/admin/product.php');

  // 第一个 Tab 落在「跳到正文」，回车后焦点到正文区
  await page.keyboard.press('Tab');
  const skip = page.locator('a.yk-skip-link');
  await expect(skip).toBeFocused();
  await page.keyboard.press('Enter');
  await expect(page.locator('#admin-main-content')).toBeFocused();

  // 当前页在侧栏里带 aria-current
  await expect(page.locator('#admin-sidebar a[aria-current="page"]')).toHaveCount(1);
  await expect(page.locator('#admin-sidebar a[aria-current="page"]')).toHaveAttribute('href', '/admin/product.php');

  if (page.viewportSize().width >= 1024) {
    // 展开/收起分组是真正的按钮，状态写在 aria-expanded 上
    const toggle = page.locator('#admin-sidebar .sidebar-group-toggle:visible').first();
    const before = await toggle.getAttribute('aria-expanded');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', before === 'true' ? 'false' : 'true');
    const panel = page.locator('#' + await toggle.getAttribute('aria-controls'));
    await expect(panel).toHaveCount(1);
  }
});

test('mobile menu behaves as a modal dialog and returns focus on Escape @ci', async ({ page }) => {
  test.skip(page.viewportSize().width >= 1024, 'drawer only exists below 1024px');
  await page.goto('/admin/content.php');
  const trigger = page.locator('button[aria-controls="admin-sidebar"]');
  const sidebar = page.locator('#admin-sidebar');

  // 关着的抽屉对读屏和 Tab 不可见
  await expect(sidebar).toHaveAttribute('inert', /.*/);
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');

  await trigger.click();
  await expect(sidebar).toHaveAttribute('role', 'dialog');
  await expect(sidebar).toHaveAttribute('aria-modal', 'true');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  // 焦点进入抽屉，正文区此时 inert
  expect(await sidebar.evaluate((el) => el.contains(document.activeElement))).toBe(true);
  await expect(page.locator('#admin-main-content').locator('xpath=ancestor::div[@inert]')).toHaveCount(1);

  // Tab 走一圈也不会跑出抽屉
  for (let i = 0; i < 40; i++) await page.keyboard.press('Tab');
  expect(await sidebar.evaluate((el) => el.contains(document.activeElement))).toBe(true);

  // Esc 关闭，焦点回到菜单按钮
  await page.keyboard.press('Escape');
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await expect(trigger).toBeFocused();
});
