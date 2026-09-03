const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites, performPreviewUpdate, frame } = require('./helpers');
const { openBanner } = require('./banner-helpers');

test('a banner slide can switch to video with poster-first mobile fallback @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  const labels = {
    'zh-CN': { video: '视频', poster: '只显示封面', play: '播放视频' },
    en: { video: 'Videos', poster: 'Show poster only', play: 'Play video' },
    ja: { video: '動画', poster: 'ポスターのみ表示', play: '動画を再生' },
  }[process.env.BLOX_E2E_SITE_LANG || 'zh-CN'];

  await page.route('**/uploads/videos/banner-test.mp4', route => route.fulfill({
    status: 200,
    contentType: 'video/mp4',
    body: Buffer.alloc(32),
  }));

  await openBanner(page);
  await performPreviewUpdate(page, () => page.locator('[data-banner-thumb]').first().locator('button').first().click());
  const field = key => page.locator('[data-control-key="' + key + '"]');

  await performPreviewUpdate(page, () => field('media_type').getByRole('button', { name: labels.video, exact: true }).click());
  await expect(field('video')).toBeVisible();
  await field('video').locator('summary').click();
  await performPreviewUpdate(page, () => field('video').locator('input').fill('/uploads/videos/banner-test.mp4'));

  const video = (await frame(page)).locator('[data-blox-banner-video]').first();
  await expect(video).toHaveAttribute('src', '/uploads/videos/banner-test.mp4');
  await expect(video).toHaveAttribute('data-blox-mobile-video', 'poster');
  await page.screenshot({ path: testInfo.outputPath('banner-video-controls.png') });

  await page.getByTestId('blox-banner-group-mobile').click();
  await expect(field('video_mobile_mode').getByRole('button', { name: labels.poster, exact: true })).toHaveAttribute('aria-pressed', 'true');
  await performPreviewUpdate(page, () => field('video_mobile_mode').getByRole('button', { name: labels.play, exact: true }).click());
  await expect(video).toHaveAttribute('data-blox-mobile-video', 'video');

  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
