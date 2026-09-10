const { test, expect } = require('./site-diagnostics');

test.use({ storageState: { cookies: [], origins: [] }, reducedMotion: 'reduce' });

for (const lang of ['zh-CN', 'en', 'ja']) {
  test(`Default translucent badges remain readable in ${lang} @ci`, async ({ page }, info) => {
    await page.goto(`/tests/e2e/default-badges-page.php?lang=${lang}`);
    await expect(page.locator('body')).not.toContainText(/Warning:|Fatal error:|already defined/);
    for (const image of await page.locator('main img').all()) {
      await image.scrollIntoViewIfNeeded();
      await expect.poll(() => image.evaluate(node => node.complete && node.naturalWidth > 0)).toBe(true);
    }
    await expect(page.locator('link[href*="/themes/default/assets/css/theme.css"]')).toHaveCount(1);
    for (const selector of ['.yk-default-about-badge', '.yk-default-recommend-badge']) {
      const badge = page.locator(selector);
      await badge.scrollIntoViewIfNeeded();
      await expect(badge).toBeVisible();
      const values = await badge.evaluate(node => {
        const css = getComputedStyle(node);
        // Canvas normalizes color-mix() into sRGB; test worst-case black and white photo backgrounds.
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = 1;
        const ctx = canvas.getContext('2d');
        const color = value => {
          ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = value; ctx.fillRect(0, 0, 1, 1);
          return Array.from(ctx.getImageData(0, 0, 1, 1).data);
        };
        const luminance = rgb => rgb.slice(0, 3).map(v => v / 255)
          .map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4)
          .reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
        const bg = color(css.backgroundColor), fg = luminance(color(css.color)), alpha = bg[3] / 255;
        const contrasts = [0, 255].map(back => {
          const l = luminance(bg.slice(0, 3).map(v => v * alpha + back * (1 - alpha)));
          return (Math.max(l, fg) + .05) / (Math.min(l, fg) + .05);
        });
        const box = node.getBoundingClientRect(), parent = node.parentElement.getBoundingClientRect();
        return { bg, opacity: css.opacity, contrasts, overflow: node.scrollWidth - node.clientWidth,
          inside: box.left >= parent.left && box.right <= parent.right, text: node.textContent.trim() };
      });
      expect(values.text).not.toBe('');
      expect(values.opacity).toBe('1');
      expect(values.bg[3]).toBeLessThan(255);
      expect(Math.min(...values.contrasts)).toBeGreaterThanOrEqual(4.5);
      expect(values.inside).toBe(true);
      expect(values.overflow).toBeLessThanOrEqual(1);
      await info.attach(selector, { body: JSON.stringify(values), contentType: 'application/json' });
    }
    await page.locator('.yk-default-about-badge').scrollIntoViewIfNeeded();
    await page.screenshot({ path: info.outputPath(`default-badges-${lang}.png`), fullPage: true });
  });
}
