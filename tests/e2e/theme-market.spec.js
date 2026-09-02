const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites } = require('./helpers');

const marketThemes = ['aurora', 'business', 'minimal', 'trade'].map((slug) => ({
  slug,
  name: slug[0].toUpperCase() + slug.slice(1),
  name_en: slug,
  name_ja: slug,
  description: `${slug} theme`,
  description_en: `${slug} theme`,
  description_ja: `${slug} theme`,
  version: '1.0.0',
  author: 'Yikai CMS',
  category: 'general',
  download_url: `https://update.yikaicms.com/packages/themes/${slug}-v1.0.0.zip`,
}));

test('core install keeps only default locally and discovers optional market themes @ci', async ({ page }) => {
  const consoleEntries = observeConsole(page);
  const unsafeWrites = observeUnsafeWrites(page);

  await page.route('**/admin/theme.php', async (route) => {
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
    await route.fulfill({
      status: 200,
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({ code: 0, data: { updated_at: '2026-08-15', themes: marketThemes } }),
    });
  });

  await page.goto('/admin/theme.php', { waitUntil: 'domcontentloaded' });
  const localCards = page.getByTestId('theme-local-list').locator('[data-theme-slug]');
  await expect(localCards).toHaveCount(1);
  await expect(localCards.first()).toHaveAttribute('data-theme-slug', 'default');

  await page.getByTestId('theme-market-tab').click();
  const marketList = page.getByTestId('theme-market-list');
  for (const slug of ['aurora', 'business', 'minimal', 'trade']) {
    await expect(marketList.locator(`[data-theme-slug="${slug}"]`)).toBeVisible();
  }

  expect(unsafeWrites, 'theme discovery must not activate or install a theme').toEqual([]);
  expect(consoleEntries, 'theme manager should keep the browser console clean').toEqual([]);
});

test('theme update link opens the market and highlights its theme @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one focused theme-link check is sufficient');
  const consoleEntries = observeConsole(page);

  await page.route('**/admin/theme.php', async (route) => {
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
    await route.fulfill({
      status: 200,
      contentType: 'application/json; charset=utf-8',
      body: JSON.stringify({ code: 0, data: { updated_at: '2026-09-01', themes: marketThemes } }),
    });
  });

  await page.goto('/admin/theme.php?tab=market&update=business', { waitUntil: 'domcontentloaded' });
  const business = page.getByTestId('theme-market-list').locator('[data-theme-slug="business"]');
  await expect(business).toBeVisible();
  await expect(business).toHaveClass(/ring-amber-400/);
  expect(consoleEntries, 'focused theme market should keep the console clean').toEqual([]);
});
