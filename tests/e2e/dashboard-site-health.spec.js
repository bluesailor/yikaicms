const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

const SESSION_KEY = 'yikaicms.dashboardHealth.closed';

test('dashboard Site Health notice keeps all actions usable across viewports @ci', async ({ page }) => {
  const consoleEntries = observeConsole(page);
  await page.goto('/admin/index.php', { waitUntil: 'domcontentloaded' });

  const notice = page.getByTestId('dashboard-health-notice');
  const actions = page.getByTestId('dashboard-health-actions');
  await expect(notice).toBeVisible();
  await expect(actions).toBeVisible();
  await expect(page.getByTestId('dashboard-health-view')).toBeVisible();
  await expect(page.getByTestId('dashboard-health-dismiss')).toBeVisible();
  await expect(page.getByTestId('dashboard-health-close')).toBeVisible();
  await expect(page.getByTestId('dashboard-health-close')).toHaveAttribute('aria-label', /.+/);

  expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);

  await page.getByTestId('dashboard-health-close').click();
  await expect(notice).toHaveCount(0);
  expect(await page.evaluate((key) => sessionStorage.getItem(key), SESSION_KEY)).toBe('1');
  expect(consoleEntries, 'dashboard notice must keep the console clean').toEqual([]);
});

test('dashboard Site Health notice can be disabled persistently @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile-390', 'single persistent settings check after responsive coverage');
  await page.goto('/admin/index.php', { waitUntil: 'domcontentloaded' });

  const responsePromise = page.waitForResponse((response) => response.url().endsWith('/admin/index.php')
    && response.request().method() === 'POST');
  await page.getByTestId('dashboard-health-dismiss').click();
  const response = await responsePromise;
  expect((await response.json()).code).toBe(0);
  await expect(page.getByTestId('dashboard-health-notice')).toHaveCount(0);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('dashboard-health-notice')).toHaveCount(0);
});
