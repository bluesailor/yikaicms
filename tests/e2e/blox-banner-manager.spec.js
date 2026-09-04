const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites, openEditor } = require('./helpers');

const language = process.env.BLOX_E2E_SITE_LANG || 'zh-CN';
const labels = {
  'zh-CN': { live: '同步轮播图管理', custom: '本页独立轮播', edit: '编辑轮播', confirm: '放弃本页的轮播修改' },
  en: { live: 'Synced with Banner management', custom: 'Page-specific slides', edit: 'Edit slide', confirm: 'Discard this page' },
  ja: { live: 'バナー管理と同期中', custom: 'このページ専用のスライド', edit: 'スライドを編集', confirm: 'このページのスライド変更を破棄' },
}[language];

test('banner images edit directly without a takeover step @ci @shard-media', async ({ page }, testInfo) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  await openEditor(page);
  if (await page.getByTestId('blox-mobile-structure').isVisible()) {
    await page.getByTestId('blox-mobile-structure').click();
  }
  const banner = page.locator('[data-testid="blox-tree-element"][data-home-block-type="banner"]').first();
  const section = banner.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]');
  await section.locator('[data-section-drag-handle]').first().click();
  // Selecting a section opens its settings drawer on narrow screens.
  if (await page.getByTestId('blox-mobile-structure').isVisible()) {
    await page.getByTestId('blox-mobile-structure').click();
  }
  await banner.locator('[data-element-drag-handle]').click();

  const manager = page.getByTestId('blox-banner-manager');
  const source = manager.getByTestId('blox-banner-source');
  const restore = manager.getByTestId('blox-banner-restore');
  const thumbnails = manager.locator('[data-banner-thumb]');
  await expect(manager).toBeVisible();
  await expect(source).toHaveText(labels.live);
  await expect(restore).toHaveCount(0);
  await expect(manager.locator('p')).toHaveCount(0);
  const initialCount = await thumbnails.count();
  expect(initialCount).toBeGreaterThan(0);
  await expect(thumbnails.first().locator('img')).toBeVisible();
  expect(await thumbnails.first().locator('img').evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
  expect(await manager.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('banner-live.png') });

  // Keyboard entry follows the same direct-edit path as a thumbnail click.
  await manager.getByRole('button', { name: labels.edit + ' 1', exact: true }).press('Enter');
  await expect(source).toHaveText(labels.custom);
  await expect(page.getByTestId('blox-banner-overall-settings')).toBeVisible();
  await expect(thumbnails).toHaveCount(initialCount);
  await expect(restore).toBeVisible();
  await page.screenshot({ path: testInfo.outputPath('banner-edit.png') });

  await manager.getByTestId('blox-banner-add').click();
  await expect(thumbnails).toHaveCount(initialCount + 1);
  page.once('dialog', async dialog => {
    expect(dialog.message()).toContain(labels.confirm);
    await dialog.dismiss();
  });
  await restore.click();
  await expect(thumbnails).toHaveCount(initialCount + 1);
  await expect(source).toHaveText(labels.custom);
  page.once('dialog', dialog => dialog.accept());
  await restore.click();
  await expect(source).toHaveText(labels.live);
  await expect(thumbnails).toHaveCount(initialCount);
  await expect(restore).toHaveCount(0);

  // An empty inherited source still has an add action, with no import prerequisite.
  await page.evaluate(() => { window.Alpine.$data(document.body).homeBannerSeeds = []; });
  await expect(thumbnails).toHaveCount(0);
  await manager.getByTestId('blox-banner-add').click();
  await expect(thumbnails).toHaveCount(1);
  await expect(source).toHaveText(labels.custom);
  expect(writes, 'editing must not save or publish the homepage').toEqual([]);
  expect(errors, 'the editor must not produce browser errors').toEqual([]);
});
