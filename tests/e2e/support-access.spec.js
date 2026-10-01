const { test, expect } = require('./site-diagnostics');

// 官方技术支持临时访问（2.0.3）：站长开启 → 一次性链接登录 → 只有系统维护权限 → 撤销即登出。

test('owner grants time-limited support access, support sees maintenance only, revoke signs it out @ci', async ({ page, browser }) => {
  test.skip(page.viewportSize().width < 1024, 'one viewport is enough for this server-side flow');
  page.on('dialog', (dialog) => dialog.accept());

  await page.goto('/admin/support_access.php');
  await expect(page.getByTestId('support-inactive')).toBeVisible();
  await page.getByTestId('support-enable').click();
  const linkInput = page.getByTestId('support-link');
  await expect(linkInput).toBeVisible();
  const link = await linkInput.inputValue();
  expect(link).toMatch(/\/admin\/support_login\.php#[a-f0-9]{48}$/);

  // 支持人员：全新浏览器会话，凭链接登录
  const supportContext = await browser.newContext({ storageState: { cookies: [], origins: [] } });   // 与站长不共用会话
  const support = await supportContext.newPage();
  await support.goto(link);
  await expect(support).toHaveURL(/\/admin\/support_login\.php$/, { timeout: 5000 });  // 令牌已从地址栏抹掉
  await support.locator('#supportLogin button[type="submit"]').click();
  await expect(support).toHaveURL(/\/admin\/upgrade_online\.php/);
  await expect(support.getByTestId('support-banner')).toBeVisible();

  // 侧栏只剩系统维护的几项
  const hrefs = await support.locator('#admin-sidebar a[href^="/admin/"]').evaluateAll((links) => links.map((a) => a.getAttribute('href')));
  for (const allowed of ['/admin/upgrade_online.php', '/admin/site_health.php', '/admin/database.php', '/admin/system.php']) {
    expect(hrefs).toContain(allowed);
  }
  for (const hidden of ['/admin/user.php', '/admin/member.php', '/admin/setting.php', '/admin/support_access.php', '/admin/license.php']) {
    expect(hrefs).not.toContain(hidden);
  }

  // 业务数据、用户、站长设置、本页都进不去
  for (const url of ['/admin/member.php', '/admin/form.php', '/admin/user.php', '/admin/setting.php', '/admin/support_access.php', '/admin/system.php?tab=log']) {
    await support.goto(url);
    await expect(support.locator('body'), url).toContainText(/没有操作权限|permission|権限/i);
  }
  // 维护页面能进
  await support.goto('/admin/site_health.php');
  await expect(support.locator('#healthRun')).toBeVisible();

  // 数据库：能在服务器上备份，不能导出下载
  await support.goto('/admin/database.php');
  const csrf = await support.locator('meta[name="csrf-token"]').getAttribute('content');
  const exported = await support.request.post('/admin/database.php', {
    form: { action: 'export', _token: csrf }, headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(exported.status()).toBe(403);
  // 改升级授权（是否允许远程升级）同样拒绝
  const consent = await support.request.post('/admin/upgrade.php', {
    form: { action: 'save_managed_upgrade', enabled: '1', _token: csrf }, headers: { 'X-Requested-With': 'XMLHttpRequest' },
  });
  expect(consent.status()).toBe(403);

  // 链接只能用一次
  const second = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const replay = await second.newPage();
  await replay.goto(link);
  await replay.locator('#supportLogin button[type="submit"]').click();
  await expect(replay.locator('[role="alert"]')).toBeVisible();
  await second.close();

  // 站长看得到提醒和支持人员的操作记录，撤销后支持会话立即失效
  await page.goto('/admin/');
  await expect(page.getByTestId('support-banner')).toBeVisible();
  await page.goto('/admin/support_access.php');
  await expect(page.getByTestId('support-active')).toBeVisible();
  await expect(page.locator('body')).toContainText('support / login');
  await page.getByTestId('support-revoke').click();
  await expect(page.getByTestId('support-inactive')).toBeVisible();

  await support.goto('/admin/upgrade.php');
  await expect(support).toHaveURL(/\/admin\/login\.php/);
  await supportContext.close();
});
