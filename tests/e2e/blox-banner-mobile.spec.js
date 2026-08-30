const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites, performPreviewUpdate, frame } = require('./helpers');
const { openBanner, undoBanner: undo } = require('./banner-helpers');

async function showSettings(page) {
  const toggle = page.getByTestId('blox-mobile-settings');
  if (await toggle.isVisible()) await toggle.click();
}

test('mobile image fallback and reset match the actual canvas @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  await openBanner(page);
  await performPreviewUpdate(page, () => page.locator('[data-banner-thumb]').first().locator('button').first().click());
  await page.getByTestId('blox-banner-group-mobile').click();
  const control = page.locator('[data-control-key="image_mobile"]');
  const desktopImage = await page.evaluate(() => window.Alpine.$data(document.body).selEl.data.image);
  await expect(control.locator('img')).toHaveAttribute('src', desktopImage);
  await expect(page.getByTestId('blox-banner-mobile-reset')).toHaveCount(0);
  await control.locator('summary').click();
  await performPreviewUpdate(page, () => control.locator('input').fill('/assets/images/demo/banner-2.svg'));
  await expect(page.getByTestId('blox-banner-mobile-reset')).toBeVisible();
  await page.getByTestId('blox-banner-preview-mobile').click();
  const image = (await frame(page)).locator('[data-blox-banner-bg] img').first();
  await expect.poll(() => image.evaluate(img => img.currentSrc)).toContain('/assets/images/demo/banner-2.svg');
  await page.screenshot({ path: testInfo.outputPath('banner-mobile-preview.png') });
  await showSettings(page);
  await expect(page.getByTestId('blox-banner-group-mobile')).toHaveAttribute('aria-pressed', 'true');
  await performPreviewUpdate(page, () => page.getByTestId('blox-banner-mobile-reset').click());
  await expect(control.locator('img')).toHaveAttribute('src', desktopImage);
  await expect(page.getByTestId('blox-banner-mobile-reset')).toHaveCount(0);
  await expect.poll(() => image.evaluate(img => img.currentSrc)).toContain(desktopImage);
  await undo(page);
  await expect(page.getByTestId('blox-banner-mobile-reset')).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('banner-mobile-settings.png') });
  await page.getByTestId('blox-banner-preview-desktop').click();
  await expect.poll(() => image.evaluate(img => img.currentSrc)).toContain(desktopImage);
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
