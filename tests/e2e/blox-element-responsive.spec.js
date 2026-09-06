const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { frame, openPageEditor, waitPreviewSettled } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(
  path.resolve(__dirname, '../smoke/fixtures.json'),
  'utf8'
));

test('nested org chart and stats elements remain usable on narrow canvas @ci', async ({ page }, testInfo) => {
  test.skip(!['mobile-390', 'tablet-768'].includes(testInfo.project.name), 'narrow rendering baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  await openPageEditor(page, fixtures.blox_page);
  await page.getByTestId('blox-mobile-structure').click();
  await page.getByTestId('blox-tree-section').first().click();
  await page.getByTestId('blox-mobile-library').click();
  const search = page.locator('input[placeholder*="搜索元素"], input[placeholder*="Search elements"], input[placeholder*="要素を検索"]').first();
  await expect(search).toBeVisible();
  await search.fill('org');
  await page.getByTestId('blox-add-element-org-chart').press('Enter');
  await page.getByTestId('blox-mobile-library').click();
  await search.fill('stats');
  await page.getByTestId('blox-add-element-stats-group').press('Enter');
  await waitPreviewSettled(page);

  const canvas = await frame(page);
  await expect(canvas.locator('[data-blox-org-chart]')).toBeVisible();
  await expect(canvas.locator('.yk-stats-group')).toBeVisible();

  const overflow = await canvas.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
    nodes: [...document.querySelectorAll('[data-blox-org-chart], .yk-stats-group')].map((node) => {
      const rect = node.getBoundingClientRect();
      return { left: rect.left, right: rect.right };
    }),
  }));
  expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1);
  for (const node of overflow.nodes) {
    expect(node.left).toBeGreaterThanOrEqual(-1);
    expect(node.right).toBeLessThanOrEqual(overflow.clientWidth + 1);
  }
});

test('FAQ and process elements remain visible without horizontal overflow on narrow canvas @ci', async ({ page }, testInfo) => {
  test.skip(!['mobile-390', 'tablet-768'].includes(testInfo.project.name), 'narrow rendering baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  await openPageEditor(page, fixtures.blox_page);
  await page.getByTestId('blox-mobile-structure').click();
  await page.getByTestId('blox-tree-section').first().click();
  await page.getByTestId('blox-mobile-library').click();
  const search = page.locator('input[placeholder*="搜索元素"], input[placeholder*="Search elements"], input[placeholder*="要素を検索"]').first();
  await search.fill('accordion');
  await page.getByTestId('blox-add-element-accordion').press('Enter');
  await waitPreviewSettled(page);
  const canvas = await frame(page);
  await expect(canvas.locator('details').first()).toBeVisible();
  expect(await canvas.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);

  expect(fixtures.process_page).toBeGreaterThan(0);
  await openPageEditor(page, fixtures.process_page);
  await page.getByTestId('blox-mobile-structure').click();
  await page.getByTestId('blox-tree-section').first().click();
  await page.getByTestId('blox-mobile-library').click();
  const processSearch = page.locator('input[placeholder*="搜索元素"], input[placeholder*="Search elements"], input[placeholder*="要素を検索"]').first();
  await processSearch.fill('process');
  await page.getByTestId('blox-add-element-process-steps').press('Enter');
  await waitPreviewSettled(page);
  const processCanvas = await frame(page);
  await expect(processCanvas.locator('.yk-process-steps')).toBeVisible();
  expect(await processCanvas.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
});
