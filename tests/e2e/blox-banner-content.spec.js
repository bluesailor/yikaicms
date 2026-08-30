const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites } = require('./helpers');
const { openBanner } = require('./banner-helpers');

test('slide editing starts with image and copy, preserving links across groups @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  await openBanner(page);
  await page.locator('[data-banner-thumb]').first().locator('button').first().click();
  const keys = () => page.locator('[data-testid="blox-element-property-grid"] > [data-control-key]').evaluateAll(nodes => nodes.map(node => node.dataset.controlKey));
  expect(await keys()).toEqual(['image', 'title', 'subtitle', 'image_mobile']);
  const field = key => page.locator('[data-control-key="' + key + '"]');
  await field('title').locator('input').fill('A long enterprise carousel title');
  await page.getByTestId('blox-banner-group-playback').click();
  await field('btn1_text').locator('input').fill('Contact us');
  await field('btn1_url').locator('input').first().fill('/contact.html');
  await page.getByTestId('blox-banner-group-motion').click();
  await expect(field('content_motion')).toBeVisible();
  await page.getByTestId('blox-banner-group-playback').click();
  await expect(field('btn1_url').locator('input').first()).toHaveValue('/contact.html');
  await page.getByTestId('blox-banner-group-common').click();
  await expect(field('title').locator('input')).toHaveValue('A long enterprise carousel title');
  await page.screenshot({ path: testInfo.outputPath('banner-content.png') });
  await page.getByTestId('blox-banner-overall-settings').click();
  await expect(field('banner_height_mode')).toBeVisible();
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
