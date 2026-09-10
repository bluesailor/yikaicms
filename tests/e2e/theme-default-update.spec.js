const { test, expect } = require('./site-diagnostics');

test('Default update explains file replacement and cancelling never installs @ci', async ({ page }) => {
  const installs = [];
  await page.route('**/admin/theme.php*', async route => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    const body = new URLSearchParams(request.postData() || '');
    if (body.get('action') === 'market_install') {
      installs.push(body.get('slug'));
      return route.abort();
    }
    if (body.get('action') !== 'market_list') return route.continue();
    return route.fulfill({ json: { code: 0, data: { themes: [{ slug: 'default', name: 'Default',
      version: '9.9.9', description: 'Default', screenshot: '/themes/default/assets/images/screenshot.jpg' }] } } });
  });
  await page.goto('/admin/theme.php?tab=market&update=default');
  const card = page.getByTestId('theme-market-list').locator('[data-theme-slug="default"]');
  await expect(card).toBeVisible();
  let message = '';
  page.once('dialog', async dialog => { message = dialog.message(); await dialog.dismiss(); });
  await card.getByRole('button', { name: '更新', exact: true }).click();
  await expect.poll(() => message).toContain('备份');
  expect(message).toContain('Blox');
  expect(message).toContain('直接修改');
  expect(installs).toEqual([]);
});
