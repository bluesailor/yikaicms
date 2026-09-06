const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const installMarketThemes = require('./theme-market-fixture');
const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'minimal-footer-fixture.php'), action], { cwd: root });
let cleanup = () => {};
test.beforeAll(() => { cleanup = installMarketThemes(root, ['minimal']); });
test.afterAll(() => { try { fixture('restore'); } finally { cleanup(); } });

for (const mode of ['native', 'preset']) {
  test(`Minimal ${mode} footer keeps its intended content readable @ci`, async ({ page }, info) => {
    fixture(mode);
    try {
      await page.goto('/?preview=1');
      const footer = page.locator(mode === 'native' ? '.minimal-footer' : '.yk-blox-footer');
      await expect(footer).toBeVisible();
      const logo = footer.locator('img').first();
      if (mode === 'preset') {
        await expect(logo).toBeVisible();
        await expect.poll(() => logo.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
        expect(await footer.locator('a').count()).toBeGreaterThan(3);
      } else {
        await expect(logo).toHaveCount(0);
      }
      await expect(footer).toContainText(/©/);
      await footer.scrollIntoViewIfNeeded();
      const boxes = await footer.evaluate(el => {
        const box = el.getBoundingClientRect();
        return { width: innerWidth, left: box.left, right: box.right,
          overflow: [...el.querySelectorAll('a, p')].some(node => {
            const rect = node.getBoundingClientRect();
            return rect.width > 0 && (rect.left < -1 || rect.right > innerWidth + 1);
          }) };
      });
      expect(boxes.left).toBeGreaterThanOrEqual(-1);
      expect(boxes.right).toBeLessThanOrEqual(boxes.width + 1);
      expect(boxes.overflow).toBe(false);
      await footer.screenshot({ path: info.outputPath(`minimal-${mode}-footer.png`) });
    } finally { fixture('restore'); }
  });
}
