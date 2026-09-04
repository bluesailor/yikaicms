const fs = require('node:fs');
const path = require('node:path');
const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

async function scanMedia(page) {
  await page.goto('/admin/media.php', { waitUntil: 'domcontentloaded' });
  const result = await page.evaluate(async () => {
    const response = await fetch('/admin/media_api.php?action=scan', { method: 'POST' });
    return response.json();
  });
  expect(result.code).toBe(0);
  await page.waitForLoadState('networkidle');
}

test('standalone media library filters, sorts, and paginates video cards @ci', async ({ page }, testInfo) => {
  const keyword = `media-page-${process.pid}-${testInfo.project.name}`.toLowerCase();
  const videoDir = path.join(process.cwd(), 'uploads', 'videos');
  fs.mkdirSync(videoDir, { recursive: true });
  for (let index = 1; index <= 25; index += 1) {
    fs.writeFileSync(path.join(videoDir, `${keyword}-${String(index).padStart(2, '0')}.mp4`), Buffer.alloc(index));
  }

  try {
    await scanMedia(page);
    const errors = observeConsole(page);
    await page.goto(`/admin/media.php?type=video&keyword=${encodeURIComponent(keyword)}&sort=largest`, { waitUntil: 'domcontentloaded' });

    await expect(page.getByTestId('media-sort')).toHaveValue('largest');
    await expect(page.getByTestId('media-type-tabs').locator('[data-media-type="video"]')).toHaveAttribute('aria-current', 'page');
    const cards = page.locator('#mediaGrid [data-media-card]');
    await expect(cards).toHaveCount(24);
    await expect(cards.first()).toContainText(`${keyword}-25.mp4`);
    await expect(cards.first().locator('[data-media-created-date]')).toContainText(/\d{4}-\d{2}-\d{2}/);

    const preview = cards.first().locator('[data-media-video-preview]');
    await expect(preview).toHaveAttribute('data-src', new RegExp(`${keyword}-25\\.mp4$`));
    await expect(cards.first()).toHaveAttribute('data-video-status', 'error', { timeout: 12_000 });

    const next = page.locator('a[href*="page=2"]');
    await expect(next).toHaveAttribute('href', /type=video/);
    await expect(next).toHaveAttribute('href', /sort=largest/);
    await expect(next).toHaveAttribute('href', new RegExp(`keyword=${encodeURIComponent(keyword)}`));
    await next.click();
    await expect(page.locator('#mediaGrid [data-media-card]')).toHaveCount(1);
    await expect(page.locator('#mediaGrid [data-media-card]').first()).toContainText(`${keyword}-01.mp4`);

    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('standalone-video-library.png'), fullPage: true });
    expect(errors).toEqual([]);
  } finally {
    for (const name of fs.readdirSync(videoDir)) {
      if (name.startsWith(keyword)) fs.rmSync(path.join(videoDir, name), { force: true });
    }
  }
});

test('standalone media library extracts first frames from two real videos @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'Real video decoding is sampled once on desktop.');
  const samples = ['blox-test-flower.mp4', 'blox-test-friday.mp4'];
  const videoDir = path.join(process.cwd(), 'uploads', 'videos');
  test.skip(samples.some(name => !fs.existsSync(path.join(videoDir, name))), 'Local video samples are not available.');

  await scanMedia(page);
  const errors = observeConsole(page);
  await page.goto('/admin/media.php?type=video&keyword=blox-test-&sort=name', { waitUntil: 'domcontentloaded' });

  const cards = page.locator('#mediaGrid [data-media-card]');
  await expect(cards).toHaveCount(2);
  await expect(cards.locator('[data-media-video-preview]')).toHaveCount(2);
  await expect(cards.first()).toHaveAttribute('data-video-status', 'ready', { timeout: 12_000 });
  await expect(cards.nth(1)).toHaveAttribute('data-video-status', 'ready', { timeout: 12_000 });
  await expect(cards.first().locator('[data-media-video-meta]')).toHaveText(/^\d+x\d+ · \d+:\d{2}$/);
  await expect(cards.nth(1).locator('[data-media-video-meta]')).toHaveText(/^\d+x\d+ · \d+:\d{2}$/);
  await cards.first().locator('[data-media-preview-button]').click();
  await expect(page.locator('#previewModal')).toBeVisible();
  await page.locator('#previewFrame video').click({ position: { x: 8, y: 8 } });
  await expect(page.locator('#previewModal')).toBeVisible();
  await page.evaluate(() => window.closePreview());

  const uploadResponse = page.waitForResponse(response => (
    response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/admin/media_api.php'
      && new URL(response.url()).searchParams.get('action') === 'upload'
  ));
  await page.locator('#fileInput').setInputFiles(path.join(videoDir, samples[0]));
  const uploadPayload = await (await uploadResponse).json();
  expect(uploadPayload.code).toBe(0);
  expect(uploadPayload.data.url).toMatch(/^\/uploads\/videos\//);
  await expect(cards).toHaveCount(3, { timeout: 8_000 });

  await page.screenshot({ path: testInfo.outputPath('standalone-real-video-first-frames.png'), fullPage: true });
  expect(errors).toEqual([]);
});
