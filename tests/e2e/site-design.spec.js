const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites } = require('./helpers');

test('website design dashboard routes to existing design capabilities @ci', async ({ page }) => {
  const consoleEntries = observeConsole(page);
  const unsafeWrites = observeUnsafeWrites(page);

  await page.goto('/admin/site_design.php', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('site-design-dashboard')).toBeVisible();
  await expect(page.getByTestId('site-design-home')).toBeVisible();
  await expect(page.getByTestId('site-design-pages')).toBeVisible();
  await expect(page.getByTestId('site-design-area-header')).toBeVisible();
  await expect(page.getByTestId('site-design-area-footer')).toBeVisible();
  await expect(page.getByTestId('site-design-area-popup')).toBeVisible();

  const designLink = page.getByTestId('site-design-system').locator('a');
  if (await designLink.count()) {
    await designLink.click();
    await expect(page).toHaveURL(/\/admin\/blox_design\.php/);
    await expect(page.getByTestId('blox-design-page')).toBeVisible();
    await expect(page.getByTestId('blox-design-page-colors')).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth), 'design page must not create body-level horizontal overflow').toBeLessThanOrEqual(1);
  }

  expect(unsafeWrites, 'dashboard navigation must not save or publish').toEqual([]);
  expect(consoleEntries, 'dashboard and design deep link must keep the console clean').toEqual([]);
});

test('template library separates site-area types @ci', async ({ page }) => {
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'template management is an advanced feature');

  await page.goto('/admin/blox_templates.php?type=popup', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-template-filter-popup')).toHaveAttribute('aria-current', 'page');
  await expect(page.getByTestId('blox-popup-create')).toBeVisible();
  await expect(page.getByTestId('blox-area-presets')).toHaveCount(0);

  await page.getByTestId('blox-template-filter-header').click();
  await expect(page).toHaveURL(/type=header/);
  await expect(page.getByTestId('blox-template-filter-header')).toHaveAttribute('aria-current', 'page');
  await expect(page.getByTestId('blox-popup-create')).toHaveCount(0);
  await expect(page.getByTestId('blox-area-presets')).toBeVisible();
  await expect(page.getByTestId('blox-current-areas')).toBeVisible();
  await expect(page.getByTestId('blox-current-area-header')).toBeVisible();
  await expect(page.getByTestId('blox-current-area-footer')).toHaveCount(0);
  await expect(page.getByTestId('blox-current-area-header').getByTestId('blox-current-area-source-theme')).toBeVisible();
  await expect(page.getByTestId('blox-current-area-header').getByTestId('blox-current-area-draft')).toBeVisible();
  const headerToggle = page.getByTestId('blox-custom-header-toggle');
  await expect(headerToggle).toBeVisible();
  await expect(headerToggle).toHaveAttribute('role', 'switch');
  await expect(headerToggle).toHaveAttribute('aria-checked', 'true');
  await expect(headerToggle.locator('input[name="action"]')).toHaveCount(0);
  const headerToggleForm = headerToggle.locator('xpath=ancestor::form');
  await expect(headerToggleForm.locator('input[name="action"]')).toHaveValue('set_custom_area_enabled');
  await expect(headerToggleForm.locator('input[name="area"]')).toHaveValue('header');
  await expect(headerToggleForm.locator('input[name="enabled"]')).toHaveValue('0');

  const pageContext = page.getByTestId('blox-current-context').locator('option[value^="page:"]').first();
  const pageContextValue = await pageContext.getAttribute('value');
  expect(pageContextValue).toMatch(/^page:\d+$/);
  await page.getByTestId('blox-current-context').selectOption(pageContextValue);
  await expect(page).toHaveURL(/context=page%3A\d+/);
  await expect(page.getByTestId('blox-current-area-header')).toBeVisible();

  await page.getByTestId('blox-template-filter-footer').click();
  await expect(page).toHaveURL(/type=footer/);
  await expect(page.getByTestId('blox-current-area-footer')).toBeVisible();
  await expect(page.getByTestId('blox-current-area-header')).toHaveCount(0);
  const footerToggle = page.getByTestId('blox-custom-footer-toggle');
  await expect(footerToggle).toBeVisible();
  await expect(footerToggle).toHaveAttribute('role', 'switch');
  await expect(footerToggle).toHaveAttribute('aria-checked', 'true');
  const footerToggleForm = footerToggle.locator('xpath=ancestor::form');
  await expect(footerToggleForm.locator('input[name="action"]')).toHaveValue('set_custom_area_enabled');
  await expect(footerToggleForm.locator('input[name="area"]')).toHaveValue('footer');
  await expect(footerToggleForm.locator('input[name="enabled"]')).toHaveValue('0');
});
