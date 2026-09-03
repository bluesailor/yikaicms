const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { observeConsole, observeUnsafeWrites, performPreviewUpdate, frame } = require('./helpers');
const { openBanner } = require('./banner-helpers');

const REAL_VIDEO_SAMPLES = [
  { file: 'blox-test-flower.mp4', url: '/uploads/videos/blox-test-flower.mp4' },
  { file: 'blox-test-friday.mp4', url: '/uploads/videos/blox-test-friday.mp4' },
];

test('a banner slide can switch to video with poster-first mobile fallback @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  const labels = {
    'zh-CN': { chooseMedia: '从媒体库选择', image: '图片', video: '视频', poster: '只显示封面', play: '播放视频', tooLarge: '超过站点上限' },
    en: { chooseMedia: 'Choose from media library', image: 'Images', video: 'Videos', poster: 'Show poster only', play: 'Play video', tooLarge: 'exceeding the site limit' },
    ja: { chooseMedia: 'メディアライブラリから選択', image: '画像', video: '動画', poster: 'ポスターのみ表示', play: '動画を再生', tooLarge: 'サイトの上限' },
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
  await page.getByTestId('blox-banner-replace-video').click();
  await expect(page.getByRole('dialog', { name: labels.chooseMedia, exact: true })).toBeVisible();
  const mediaTypes = page.getByTestId('blox-media-type-tabs');
  await expect(mediaTypes).toBeVisible();
  await mediaTypes.getByRole('tab', { name: labels.image, exact: true }).click();
  await expect(mediaTypes.getByRole('tab', { name: labels.image, exact: true })).toHaveAttribute('aria-selected', 'true');
  await mediaTypes.getByRole('tab', { name: labels.video, exact: true }).click();
  await expect(mediaTypes.getByRole('tab', { name: labels.video, exact: true })).toHaveAttribute('aria-selected', 'true');
  await page.locator('[x-ref="mediaDialog"] input[type="file"]').setInputFiles({
    name: 'too-large.mp4',
    mimeType: 'video/mp4',
    buffer: Buffer.alloc(12 * 1024 * 1024),
  });
  await expect(page.getByTestId('blox-toast')).toContainText(labels.tooLarge);
  await page.keyboard.press('Escape');
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

test('two real local videos decode, play, and hand off between slides @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'Real media playback is sampled once on desktop.');
  test.skip(
    REAL_VIDEO_SAMPLES.some(sample => !fs.existsSync(path.join(process.cwd(), 'uploads', 'videos', sample.file))),
    'Download the documented local MDN samples before running this acceptance test.'
  );

  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);
  await openBanner(page);
  const field = key => page.locator('[data-control-key="' + key + '"]');

  async function configureVideo(index, url) {
    const thumb = page.locator('[data-banner-thumb]').nth(index).locator('button').first();
    await thumb.click();
    await expect(thumb).toHaveAttribute('aria-pressed', 'true');
    await performPreviewUpdate(page, () => field('media_type').locator('button').last().click());
    await field('video').locator('summary').click();
    await performPreviewUpdate(page, () => field('video').locator('input').fill(url));
  }

  await configureVideo(0, REAL_VIDEO_SAMPLES[0].url);
  const preview = await frame(page);
  const firstVideo = preview.locator('.swiper-slide').nth(0).locator('[data-blox-banner-video]');
  await expect.poll(() => firstVideo.evaluate(video => video.readyState)).toBeGreaterThanOrEqual(3);
  await expect.poll(() => firstVideo.evaluate(video => Number.isFinite(video.duration) && video.duration > 0)).toBe(true);
  await expect.poll(() => firstVideo.evaluate(video => video.paused)).toBe(false);
  await expect.poll(() => firstVideo.evaluate(video => video.currentTime)).toBeGreaterThan(0.05);

  await configureVideo(1, REAL_VIDEO_SAMPLES[1].url);
  const secondVideo = preview.locator('.swiper-slide').nth(1).locator('[data-blox-banner-video]');
  await expect.poll(() => secondVideo.evaluate(video => video.readyState)).toBeGreaterThanOrEqual(3);
  await expect.poll(() => secondVideo.evaluate(video => Number.isFinite(video.duration) && video.duration > 0)).toBe(true);
  await expect.poll(() => secondVideo.evaluate(video => video.paused)).toBe(false);
  await expect.poll(() => secondVideo.evaluate(video => video.currentTime)).toBeGreaterThan(0.05);
  await expect.poll(() => firstVideo.evaluate(video => ({ paused: video.paused, currentTime: video.currentTime })))
    .toEqual({ paused: true, currentTime: 0 });

  await page.screenshot({ path: testInfo.outputPath('banner-two-real-videos.png') });
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
