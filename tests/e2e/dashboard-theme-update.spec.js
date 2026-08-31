const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

test('dashboard presents theme updates separately from CMS updates @ci', async ({ page }) => {
  const consoleEntries = observeConsole(page);
  await page.addInitScript(() => localStorage.clear());

  await page.route(/\/admin\/upgrade_online\.php\?action=check$/, (route) => route.fulfill({
    json: { code: 0, data: { has_update: false } },
  }));
  await page.route(/\/admin\/index\.php\?action=theme_updates$/, (route) => route.fulfill({
    json: {
      code: 0,
      data: {
        count: 1,
        updates: [{
          slug: 'business', name: 'Business', name_en: 'Business', name_ja: 'Business ビジネス',
          current_version: '1.0.0', latest_version: '1.0.1',
        }],
      },
    },
  }));

  await page.goto('/admin/index.php', { waitUntil: 'domcontentloaded' });
  const row = page.getByTestId('dashboard-theme-update');
  await expect(row).toBeVisible();
  await expect(row).toContainText('Business');
  await expect(row).toContainText('v1.0.0');
  await expect(row).toContainText('v1.0.1');
  await expect(page.getByTestId('dashboard-theme-update-go')).toHaveAttribute(
    'href', '/admin/theme.php?tab=market&update=business'
  );
  const rowBox = await row.boundingBox();
  const viewport = page.viewportSize();
  expect(rowBox, 'theme update row should have a measurable layout').not.toBeNull();
  expect(viewport, 'test project should define a viewport').not.toBeNull();
  expect(rowBox.x).toBeGreaterThanOrEqual(0);
  expect(rowBox.x + rowBox.width).toBeLessThanOrEqual(viewport.width + 1);
  expect(consoleEntries, 'theme update reminder should keep the console clean').toEqual([]);
});
