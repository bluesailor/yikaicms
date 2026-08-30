const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites } = require('./helpers');
const { openBanner } = require('./banner-helpers');

test('banner settings groups stay searchable without modifying the document @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  await openBanner(page);
  const original = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections));
  const field = key => page.locator('[data-control-key="' + key + '"]');
  await expect(field('banner_height_mode')).toBeVisible();
  await expect(field('banner_speed')).toHaveCount(0);
  await page.getByTestId('blox-banner-group-playback').click();
  await expect(field('banner_autoplay')).toBeVisible();
  await expect(field('banner_height_mode')).toHaveCount(0);
  await page.getByTestId('blox-banner-group-motion').press('Enter');
  await expect(field('banner_speed')).toBeVisible();
  await page.locator('input[x-model="ctrlQuery"]').fill('banner_height_mode');
  await expect(field('banner_height_mode')).toBeVisible();
  await expect(page.getByTestId('blox-banner-control-groups')).toHaveCount(0);
  await page.locator('input[x-model="ctrlQuery"]').fill('');
  await page.getByTestId('blox-banner-group-common').click();
  expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections))).toBe(original);
  await page.screenshot({ path: testInfo.outputPath('banner-groups.png') });
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
