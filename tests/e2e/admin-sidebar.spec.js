const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

test('desktop sidebar disclosures and collapsed flyout support the keyboard @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop sidebar interaction');
  const consoleEntries = observeConsole(page);

  await page.goto('/admin/', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('a[aria-current="page"]').first()).toBeVisible();

  const group = page.locator('[data-testid^="admin-sidebar-group-"]').first();
  await expect(group).toHaveAttribute('aria-expanded', 'false');
  await group.click();
  await expect(group).toHaveAttribute('aria-expanded', 'true');

  const toggle = page.getByTestId('admin-sidebar-toggle');
  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');

  await group.focus();
  await page.keyboard.press('Enter');
  const flyout = page.getByTestId('admin-sidebar-flyout');
  await expect(flyout).toBeVisible();
  const menuItems = flyout.locator('[role="menuitem"]');
  await expect(menuItems.first()).toBeFocused();
  await page.keyboard.press('ArrowDown');
  await expect(menuItems.nth(1)).toBeFocused();
  await page.keyboard.press('Home');
  await expect(menuItems.first()).toBeFocused();

  await page.keyboard.press('Escape');
  await expect(flyout).toBeHidden();
  await expect(group).toBeFocused();
  expect(consoleEntries, 'sidebar interactions must keep the console clean').toEqual([]);
});

test('mobile sidebar traps focus and restores the opener on Escape @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile-390', 'mobile drawer interaction');
  const consoleEntries = observeConsole(page);

  await page.goto('/admin/', { waitUntil: 'domcontentloaded' });
  const sidebar = page.getByTestId('admin-sidebar');
  const toggle = page.getByTestId('admin-sidebar-toggle');
  const close = page.getByTestId('admin-sidebar-close');

  await expect(sidebar).toHaveAttribute('aria-hidden', 'true');
  expect(await sidebar.evaluate((element) => element.inert)).toBe(true);

  await toggle.click();
  await expect(sidebar).toHaveAttribute('aria-hidden', 'false');
  expect(await sidebar.evaluate((element) => element.inert)).toBe(false);
  await expect(close).toBeFocused();
  expect(await page.evaluate(() => document.body.style.overflow)).toBe('hidden');

  const wrappedToFirst = await sidebar.evaluate((element) => {
    const focusable = Array.from(element.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'))
      .filter((item) => item.offsetParent !== null && !item.hasAttribute('inert'));
    focusable[focusable.length - 1].focus();
    return focusable[0].getAttribute('href') || focusable[0].getAttribute('data-testid');
  });
  await page.keyboard.press('Tab');
  const activeIdentity = await page.evaluate(() => document.activeElement.getAttribute('href')
    || document.activeElement.getAttribute('data-testid'));
  expect(activeIdentity).toBe(wrappedToFirst);

  await page.keyboard.press('Escape');
  await expect(sidebar).toHaveAttribute('aria-hidden', 'true');
  expect(await sidebar.evaluate((element) => element.inert)).toBe(true);
  expect(await page.evaluate(() => document.body.style.overflow)).toBe('');
  await expect(toggle).toBeFocused();
  expect(consoleEntries, 'sidebar interactions must keep the console clean').toEqual([]);
});
