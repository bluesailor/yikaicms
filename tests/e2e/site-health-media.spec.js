const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

test('site health scans media in bounded batches and opens issue samples @ci @shard-media', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  const root = path.resolve(__dirname, '../..');
  const directory = path.join(root, 'uploads', 'images');
  const prefix = `site-health-batch-${process.pid}-${testInfo.project.name}`.toLowerCase();
  const first = path.join(directory, `${prefix}-01.png`);
  const createImage = [
    '$path = $argv[1];',
    '$image = imagecreatetruecolor(40, 40);',
    'imagefill($image, 0, 0, imagecolorallocate($image, 38, 112, 92));',
    'imagepng($image, $path);',
    'imagedestroy($image);',
  ].join(' ');
  execFileSync('php', ['-r', createImage, first], { cwd: root });
  for (let index = 2; index <= 25; index += 1) {
    fs.copyFileSync(first, path.join(directory, `${prefix}-${String(index).padStart(2, '0')}.png`));
  }

  try {
    await page.goto('/admin/media.php', { waitUntil: 'domcontentloaded' });
    const imported = await page.evaluate(async () => {
      const response = await fetch('/admin/media_api.php?action=scan', { method: 'POST' });
      return response.json();
    });
    expect(imported.code).toBe(0);
    expect(imported.data.added).toBeGreaterThanOrEqual(25);

    let mediaBatchResponses = 0;
    page.on('response', (response) => {
      if (response.request().method() !== 'POST') return;
      const body = new URLSearchParams(response.request().postData() || '');
      if (new URL(response.url()).pathname === '/admin/site_health.php' && body.get('action') === 'scan_media') {
        mediaBatchResponses += 1;
      }
    });

    await page.goto('/admin/site_health.php', { waitUntil: 'domcontentloaded' });
    await page.locator('#healthRun').click();
    const mediaResult = page.locator('[data-health-id="media_optimization"]');
    await expect(mediaResult).toBeVisible({ timeout: 45_000 });
    await expect(mediaResult.locator('a')).toHaveAttribute('href', '/admin/media.php?health=attention');
    expect(mediaBatchResponses).toBeGreaterThanOrEqual(2);
    await page.screenshot({ path: testInfo.outputPath('site-health-media.png'), fullPage: true });

    await mediaResult.locator('a').click();
    await expect(page).toHaveURL(/\/admin\/media\.php\?health=attention$/);
    await expect(page.getByTestId('media-health-samples')).toBeVisible();
    await expect(page.locator('#mediaGrid [data-id]').first()).toBeVisible();
    const missingCards = page.locator('#mediaGrid [data-health="missing"]');
    if (await missingCards.count() > 0) {
      await expect(missingCards.locator('img')).toHaveCount(0);
      await expect(missingCards.first().locator('[role="img"]')).toBeVisible();
    }
    await page.screenshot({ path: testInfo.outputPath('media-health-samples.png'), fullPage: true });
  } finally {
    for (const file of fs.readdirSync(directory)) {
      if (file.startsWith(prefix)) fs.rmSync(path.join(directory, file), { force: true });
    }
  }
});
