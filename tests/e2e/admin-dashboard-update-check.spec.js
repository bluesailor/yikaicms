// 2.0.4–2.0.5 回归：控制台「检查更新」用 GET 调 upgrade_online.php，而端点自 7cafd8f4 起动作只认 POST + CSRF，
// 拿回整页 HTML、状态静默空白。这里确认控制台发 POST 并拿到 JSON（更新服务器连不连得上都应是 JSON）。
const { test, expect } = require('./site-diagnostics');

test('dashboard update check posts and gets JSON back @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'admin dashboard baseline');
  const check = page.waitForResponse(response => new URL(response.url()).pathname === '/admin/upgrade_online.php'
    && response.request().method() === 'POST'
    && new URLSearchParams(response.request().postData() || '').get('action') === 'check', { timeout: 30000 });
  await page.addInitScript(() => { try { localStorage.clear(); } catch (e) {} });
  await page.goto('/admin/index.php');
  const response = await check;
  const body = await response.text();
  expect(() => JSON.parse(body), body.slice(0, 120)).not.toThrow();
  expect(JSON.parse(body)).toHaveProperty('code');

  // GET 不再执行动作：拿回的是页面而不是 JSON（防 CSRF 的行为本身保留）
  const get = await page.request.get('/admin/upgrade_online.php?action=check');
  expect((await get.text()).trimStart().startsWith('{')).toBe(false);
});

test('notification bell collects reminders on every admin page @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'admin dashboard baseline');
  await page.goto('/admin/index.php');
  const bell = page.getByTestId('admin-bell');
  await expect(bell).toBeVisible();
  // 控制台顶部不再堆提醒卡片
  await expect(page.locator('#cronHealthNotice, #updateMailPrompt, #onbCard, #uoBar')).toHaveCount(0);

  const items = bell.locator('[data-notice]');
  const before = await items.count();
  const count = page.getByTestId('admin-bell-count');
  if (before > 0) await expect(count).toHaveText(before > 9 ? '9+' : String(before));

  // 控制台后台检查发现新版本：铃铛当场加一条
  await page.evaluate(() => window.dispatchEvent(new CustomEvent('yk-update-found', { detail: { version: '9.9.9' } })));
  await expect(page.getByTestId('admin-notice-update')).toBeVisible({ visible: false });
  await page.getByTestId('admin-bell-button').click();
  await expect(page.getByTestId('admin-bell-panel')).toBeVisible();
  await expect(page.getByTestId('admin-notice-update')).toContainText('9.9.9');
  const withUpdate = await items.count();
  expect(withUpdate).toBe(before + (before === withUpdate ? 0 : 1));

  // 能关的提醒可以在铃铛里关掉
  const dismiss = bell.locator('[data-testid^="admin-notice-dismiss-"]').first();
  if (await dismiss.count()) {
    await dismiss.click();
    await expect(items).toHaveCount(withUpdate - 1);
  }

  // 其它后台页同样有铃铛
  await page.goto('/admin/article.php');
  await expect(page.getByTestId('admin-bell')).toBeVisible();
});
