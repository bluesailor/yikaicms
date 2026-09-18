const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

test('free download restrictions remain visible without install requests @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one desktop/mobile state pass');
  const errors = observeConsole(page);
  const writes = [];
  const message = '下载次数暂已用完，请稍后刷新列表再试。';
  for (const kind of ['theme', 'plugin']) {
    await page.route(`**/admin/${kind}.php*`, async route => {
      if (route.request().method() !== 'POST') return route.continue();
      const action = new URLSearchParams(route.request().postData() || '').get('action');
      if (action !== 'market_list') {
        writes.push(action);
        return route.fulfill({ json: { code: 1, msg: 'Unexpected write' } });
      }
      const item = {
        slug: 'community-limited', name: 'Community example', description: 'Download state example',
        author: 'Example author', version: '1.0.0', tier: 'free', paid: false, entitled: true,
        download_url: '', locked_reason: 'rate_limited', download_blocked: true, download_message: message,
      };
      await route.fulfill({ json: { code: 0, data: { [kind === 'theme' ? 'themes' : 'plugins']: [item] } } });
    });
    await page.goto(`/admin/${kind}.php?tab=market`, { waitUntil: 'domcontentloaded' });
    const restriction = page.getByTestId(`${kind}-market-restriction`);
    const install = page.getByTestId(`${kind}-market-install`);
    await expect(restriction).toHaveText(message);
    await expect(restriction).toBeVisible();
    await expect(install).toBeDisabled();
    const card = page.locator(`[data-${kind}-slug="community-limited"]`);
    await card.screenshot({ path: testInfo.outputPath(`${kind}-restriction-desktop.png`) });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(restriction).toBeVisible();
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
    await card.screenshot({ path: testInfo.outputPath(`${kind}-restriction-mobile.png`) });
    await page.setViewportSize({ width: 1440, height: 900 });
  }
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
