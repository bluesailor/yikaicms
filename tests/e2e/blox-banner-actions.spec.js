const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites, performPreviewUpdate, canvasScrollTop, waitPreviewSettled, frame } = require('./helpers');
const { openBanner, undoBanner: undo } = require('./banner-helpers');

const items = page => page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).bannerItems())));

test('slide actions preserve identity, undo as one step and keep canvas position @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  await openBanner(page);
  await performPreviewUpdate(page, () => page.locator('[data-banner-thumb]').first().locator('button').first().click());
  const original = await items(page);
  expect(original.length).toBeGreaterThan(1);
  const action = name => page.getByTestId('blox-banner-action-' + name);
  await expect(action('previous')).toBeDisabled();
  await waitPreviewSettled(page);
  await (await frame(page)).evaluate(() => {
    document.documentElement.style.scrollBehavior = 'auto';
    window.scrollTo(0, document.documentElement.scrollHeight);
    document.documentElement.style.scrollBehavior = 'smooth';
  });
  const beforeScroll = await canvasScrollTop(page);
  const geometry = () => page.evaluate(() => {
    const f = document.querySelector('iframe');
    return { scroll: f.contentWindow.scrollY, height: f.contentDocument.documentElement.scrollHeight, viewport: f.contentWindow.innerHeight, width: f.contentWindow.innerWidth };
  });
  const beforeGeometry = await geometry();
  await performPreviewUpdate(page, () => action('next').click());
  expect((await items(page))[1].id).toBe(original[0].id);
  await waitPreviewSettled(page);
  await testInfo.attach('scroll-geometry', { body: JSON.stringify({ before: beforeGeometry, after: await geometry() }), contentType: 'application/json' });
  expect(Math.abs(await canvasScrollTop(page) - beforeScroll)).toBeLessThan(8);
  await undo(page);
  expect(await items(page)).toEqual(original);

  await performPreviewUpdate(page, () => action('duplicate').click());
  const copies = await items(page);
  expect(copies.length).toBe(original.length + 1);
  expect(new Set(copies.map(item => item.id)).size).toBe(copies.length);
  expect(copies[1].data).toEqual(copies[0].data);
  await page.screenshot({ path: testInfo.outputPath('banner-actions.png') });
  await undo(page);
  expect(await items(page)).toEqual(original);
  await performPreviewUpdate(page, () => action('delete').click());
  expect((await items(page)).length).toBe(original.length - 1);
  await undo(page);
  expect(await items(page)).toEqual(original);
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});

test('deleting the final slide stays empty and is undoable @ci', async ({ page }) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  await openBanner(page);
  await performPreviewUpdate(page, () => page.locator('[data-banner-thumb]').first().locator('button').first().click());
  const total = (await items(page)).length;
  for (let i = 0; i < total; i++) {
    await performPreviewUpdate(page, () => page.getByTestId('blox-banner-action-delete').click());
  }
  await expect(page.locator('[data-banner-thumb]')).toHaveCount(0);
  await expect(page.getByTestId('blox-banner-slide-actions')).toHaveCount(0);
  await expect(page.getByTestId('blox-banner-restore')).toBeVisible();
  expect(await page.evaluate(() => window.Alpine.$data(document.body).hasCustomBannerItems())).toBe(true);
  await undo(page);
  await expect(page.locator('[data-banner-thumb]')).toHaveCount(1);
  await expect(page.getByTestId('blox-banner-action-previous')).toBeDisabled();
  await expect(page.getByTestId('blox-banner-action-next')).toBeDisabled();
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
