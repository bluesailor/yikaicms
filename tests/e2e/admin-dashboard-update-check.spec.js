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
