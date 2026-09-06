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
  const assignmentMatrix = page.getByTestId('blox-assignment-matrix');
  await expect(assignmentMatrix).toBeVisible();
  const assignmentRows = assignmentMatrix.getByTestId('blox-assignment-row');
  expect(await assignmentRows.count()).toBeGreaterThan(0);
  await expect(assignmentMatrix.locator('h3')).toHaveText(/\S+/);
  const firstAssignmentText = (await assignmentRows.first().locator('td').first().innerText()).trim();
  await assignmentMatrix.getByTestId('blox-assignment-matrix-search').fill(firstAssignmentText);
  await expect(assignmentRows.first()).toBeVisible();
  await assignmentMatrix.getByTestId('blox-assignment-matrix-search').fill('__no_assignment_match__');
  await expect(assignmentRows.first()).toBeHidden();
  await assignmentMatrix.getByTestId('blox-assignment-matrix-search').fill('');
  await expect(assignmentRows.first()).toBeVisible();
  const headerCustomChoice = page.getByTestId('blox-custom-header-choice-custom');
  const headerThemeChoice = page.getByTestId('blox-custom-header-choice-theme');
  await expect(page.getByTestId('blox-custom-header-state')).toHaveText(/自定义网页头：已启用|Custom header: enabled|カスタムヘッダー：有効/);
  await expect(page.getByTestId('blox-custom-header-scope')).toHaveText(/全站.*所有语言|Entire site.*All languages|サイト全体/);
  const headerInUse = page.getByTestId('blox-current-area-in-use');
  const headerThemeDefault = page.getByTestId('blox-current-area-theme-default');
  expect(await headerInUse.count() + await headerThemeDefault.count()).toBe(1);
  await expect(headerCustomChoice).toBeVisible();
  await expect(headerCustomChoice).toHaveAttribute('aria-pressed', 'true');
  await expect(headerThemeChoice).toHaveAttribute('aria-pressed', 'false');
  const headerToggleForm = headerCustomChoice.locator('xpath=ancestor::form');
  await expect(headerToggleForm.locator('input[name="action"]')).toHaveValue('set_custom_area_enabled');
  await expect(headerToggleForm.locator('input[name="area"]')).toHaveValue('header');
  await expect(headerToggleForm.locator('input[name="enabled"]')).toHaveValue('1');
  page.once('dialog', dialog => dialog.accept());
  await Promise.all([
    page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/admin/blox_templates.php')),
    headerThemeChoice.click(),
  ]);
  await expect(page.getByTestId('blox-custom-header-state')).toHaveText(/自定义网页头：已停用|Custom header: disabled|カスタムヘッダー：無効/);
  await expect(page.getByTestId('blox-current-area-theme-default')).toHaveText(/主题默认|Theme default|テーマ標準/);
  await expect(headerThemeChoice).toHaveAttribute('aria-pressed', 'true');
  await Promise.all([
    page.waitForResponse(response => response.request().method() === 'POST' && response.url().includes('/admin/blox_templates.php')),
    headerCustomChoice.click(),
  ]);
  await expect(page.getByTestId('blox-custom-header-state')).toHaveText(/自定义网页头：已启用|Custom header: enabled|カスタムヘッダー：有効/);

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
  const footerCustomChoice = page.getByTestId('blox-custom-footer-choice-custom');
  const footerThemeChoice = page.getByTestId('blox-custom-footer-choice-theme');
  await expect(page.getByTestId('blox-custom-footer-state')).toHaveText(/自定义网页尾：已启用|Custom footer: enabled|カスタムフッター：有効/);
  await expect(footerCustomChoice).toBeVisible();
  await expect(footerCustomChoice).toHaveAttribute('aria-pressed', 'true');
  await expect(footerThemeChoice).toHaveAttribute('aria-pressed', 'false');
  const footerToggleForm = footerCustomChoice.locator('xpath=ancestor::form');
  await expect(footerToggleForm.locator('input[name="action"]')).toHaveValue('set_custom_area_enabled');
  await expect(footerToggleForm.locator('input[name="area"]')).toHaveValue('footer');
  await expect(footerToggleForm.locator('input[name="enabled"]')).toHaveValue('1');
});
