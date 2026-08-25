const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

test('responsive image candidates select by viewport @ci', async ({ page }, testInfo) => {
  const root = path.resolve(__dirname, '../..');
  const name = `e2e-responsive-${process.pid}-${testInfo.project.name}`.toLowerCase();
  const original = path.join(root, 'uploads', 'images', `${name}.png`);
  const medium = path.join(root, 'uploads', 'images', `${name}_medium.png`);
  const script = [
    '$files = json_decode($argv[1], true);',
    'foreach ($files as $file) {',
    '  $image = imagecreatetruecolor($file[1], $file[2]);',
    '  imagefill($image, 0, 0, imagecolorallocate($image, $file[3], $file[4], $file[5]));',
    '  imagepng($image, $file[0]);',
    '  imagedestroy($image);',
    '}',
  ].join(' ');

  execFileSync('php', ['-r', script, JSON.stringify([
    [original, 1200, 675, 26, 86, 138],
    [medium, 600, 338, 32, 112, 92],
  ])], { cwd: root });

  try {
    await page.goto(`/tests/e2e/fixtures/responsive-image.php?name=${encodeURIComponent(name)}`);
    const image = page.getByTestId('responsive-image');
    await expect(image).toHaveAttribute('width', '600');
    await expect(image).toHaveAttribute('height', '338');
    await expect(image).toHaveAttribute('sizes', '100vw');
    await expect(image).toHaveAttribute('srcset', new RegExp(`${name}_medium\\.png 600w, .*${name}\\.png 1200w`));
    await expect.poll(() => image.evaluate((element) => element.complete && element.naturalWidth > 0)).toBe(true);

    const cardImage = page.locator('a.group img').first();
    await expect(cardImage).toHaveAttribute('decoding', 'async');
    await expect(cardImage).toHaveAttribute('width', '600');
    await expect(cardImage).toHaveAttribute('height', '338');
    await expect(cardImage).toHaveAttribute('srcset', new RegExp(`${name}_medium\\.png 600w, .*${name}\\.png 1200w`));

    const selected = await image.evaluate((element) => new URL(element.currentSrc).pathname);
    const expected = testInfo.project.name === 'mobile-390'
      ? `/uploads/images/${name}_medium.png`
      : `/uploads/images/${name}.png`;
    expect(selected).toBe(expected);
  } finally {
    fs.rmSync(medium, { force: true });
    fs.rmSync(original, { force: true });
  }
});
