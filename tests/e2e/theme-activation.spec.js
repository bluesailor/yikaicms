const path = require('path');
const { test, expect } = require('@playwright/test');
const installMarketThemes = require('./theme-market-fixture');

const root = path.resolve(__dirname, '../..');
let cleanup = () => {};

test.beforeAll(() => {
  cleanup = installMarketThemes(root, ['business']);
});

test.afterAll(() => cleanup());

test('activating a local theme redirects to its fresh active state @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one focused activation check is sufficient');

  await page.goto('/admin/theme.php', { waitUntil: 'domcontentloaded' });
  const business = page.getByTestId('theme-local-list').locator('[data-theme-slug="business"]');
  const defaultTheme = page.getByTestId('theme-local-list').locator('[data-theme-slug="default"]');
  page.on('dialog', (dialog) => dialog.accept());

  try {
    await Promise.all([
      page.waitForURL((url) => url.pathname === '/admin/theme.php' && url.search === ''),
      business.locator('button[type="submit"]').click(),
    ]);

    await expect(page.locator('body')).toContainText('business');
    await expect(business).toHaveClass(/ring-2/);
    await expect(business.locator('button[type="submit"]')).toHaveCount(0);
  } finally {
    if (await defaultTheme.locator('button[type="submit"]').count()) {
      await Promise.all([
        page.waitForURL((url) => url.pathname === '/admin/theme.php' && url.search === ''),
        defaultTheme.locator('button[type="submit"]').click(),
      ]);
    }
  }
});
