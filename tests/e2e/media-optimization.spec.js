const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

test('media library repairs responsive image derivatives @ci', async ({ page }, testInfo) => {
  const root = path.resolve(__dirname, '../..');
  const name = `media-health-${process.pid}-${testInfo.project.name}`.toLowerCase();
  const original = path.join(root, 'uploads', 'images', `${name}.png`);
  const createImage = [
    '$path = $argv[1];',
    '$image = imagecreatetruecolor(1200, 675);',
    'imagefill($image, 0, 0, imagecolorallocate($image, 38, 112, 92));',
    'imagepng($image, $path);',
    'imagedestroy($image);',
  ].join(' ');
  execFileSync('php', ['-r', createImage, original], { cwd: root });

  try {
    await page.goto('/admin/media.php', { waitUntil: 'domcontentloaded' });
    const scan = await page.evaluate(async () => {
      const response = await fetch('/admin/media_api.php?action=scan', { method: 'POST' });
      return response.json();
    });
    expect(scan.code).toBe(0);

    await page.goto(`/admin/media.php?type=image&keyword=${encodeURIComponent(name)}`, { waitUntil: 'domcontentloaded' });
    const card = page.locator('#mediaGrid [data-id]').filter({ hasText: `${name}.png` });
    await expect(card).toHaveCount(1);
    await expect(card).toHaveAttribute('data-health', 'pending');
    await expect(card.getByTestId('media-health-status')).toHaveAttribute('title', /.+/);
    await expect(page.getByTestId('media-opt-summary')).toBeVisible();
    await expect(page.getByTestId('media-opt-selected')).toBeDisabled();
    await expect(page.getByTestId('media-opt-select-pending')).toContainText('1');
    await expect(page.getByTestId('media-opt-current')).toContainText('1');

    await page.getByTestId('media-opt-select-pending').click();
    await expect(card.locator('[data-media-check]')).toBeChecked();
    await expect(page.getByTestId('media-opt-selected')).toBeEnabled();

    await page.screenshot({ path: testInfo.outputPath('media-pending.png'), fullPage: true });

    page.once('dialog', (dialog) => dialog.accept());
    const responsePromise = page.waitForResponse((response) => {
      if (response.request().method() !== 'POST') return false;
      return new URL(response.url()).pathname === '/admin/media.php';
    });
    await page.getByTestId('media-opt-current').click();
    const optimizeResponse = await responsePromise;
    const optimize = await optimizeResponse.json();
    expect(optimize.code).toBe(0);
    expect(optimize.data.repaired).toBe(1);

    await expect(card).toHaveAttribute('data-health', 'healthy', { timeout: 10_000 });
    const preview = card.locator('img');
    await expect(preview).toHaveAttribute('src', `/uploads/images/${name}_thumb.png`);
    await expect(preview).toHaveAttribute('width', '300');
    await expect(preview).toHaveAttribute('height', '300');
    await expect(preview).toHaveAttribute('decoding', 'async');
    expect(fs.existsSync(path.join(root, 'uploads', 'images', `${name}_thumb.webp`))).toBe(true);
    expect(fs.existsSync(path.join(root, 'uploads', 'images', `${name}.webp`))).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('media-healthy.png'), fullPage: true });
  } finally {
    for (const file of fs.readdirSync(path.dirname(original))) {
      if (file.startsWith(name)) fs.rmSync(path.join(path.dirname(original), file), { force: true });
    }
  }
});
