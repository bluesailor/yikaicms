const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites } = require('./helpers');

test('plugin market deep link filters by slug and renders install label @ci', async ({ page }) => {
  const consoleEntries = observeConsole(page);
  const unsafeWrites = observeUnsafeWrites(page);
  const queries = [];

  await page.route('**/admin/plugin.php**', async (route) => {
    const request = route.request();
    if (request.method() !== 'POST') {
      await route.continue();
      return;
    }
    const body = new URLSearchParams(request.postData() || '');
    if (body.get('action') !== 'market_list') {
      await route.continue();
      return;
    }
    queries.push(body.get('q') || '');
    await route.fulfill({
      status: 200,
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({
        code: 0,
        data: {
          plugins: [
            {
              slug: 'logo-maker-market', name: 'LOGO 制作', description: '离线制作 LOGO',
              version: '1.2.0', author: 'Yikai CMS', category: 'design', size_kb: 756,
              tier: 'free', paid: false, entitled: false,
            },
            {
              slug: 'seo-helper-market', name: 'SEO 助手', description: 'SEO 工具',
              version: '1.1.0', author: 'Yikai CMS', category: 'seo', size_kb: 44,
              tier: 'free', paid: false, entitled: false,
            },
          ],
        },
      }),
    });
  });

  await page.goto('/admin/plugin.php?tab=market&q=logo-maker', { waitUntil: 'domcontentloaded' });

  const list = page.getByTestId('plugin-market-list');
  await expect(page.getByTestId('plugin-market-search')).toHaveValue('logo-maker');
  await expect(list.locator('[data-plugin-slug]')).toHaveCount(1);
  await expect(list.locator('[data-plugin-slug="logo-maker-market"]')).toBeVisible();
  await expect(page.getByTestId('plugin-market-install')).toHaveText('安装');
  await expect.poll(() => queries).toContain('logo-maker');

  expect(unsafeWrites, 'market lookup must not install or activate a plugin').toEqual([]);
  expect(consoleEntries, 'plugin market should keep the browser console clean').toEqual([]);
});
