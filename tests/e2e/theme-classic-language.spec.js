const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const installMarketThemes = require('./theme-market-fixture');
const root = path.resolve(__dirname, '../..');
const fixture = (file, ...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), ...args], { cwd: root, encoding: 'utf8' });
let cleanup = () => {};
test.use({ storageState: { cookies: [], origins: [] }, reducedMotion: 'reduce' });
test.beforeAll(() => {
  cleanup = installMarketThemes(root, ['business']);
  fixture('catalog-baseline-fixture.php', 'business');
});
test.afterAll(() => {
  try { fixture('theme-classic-language-fixture.php', 'restore'); }
  finally { fixture('catalog-baseline-fixture.php', 'restore'); cleanup(); }
});
for (const language of ['zh-CN', 'en', 'ja']) {
  test(`Business classic copy honors ${language} overrides and preserves custom text @ci`, async ({ page }, info) => {
    test.setTimeout(120000);
    for (const mode of ['explicit', 'factory', 'custom']) {
      const expected = JSON.parse(fixture('theme-classic-language-fixture.php', mode, language));
      const before = JSON.parse(fixture('theme-classic-language-fixture.php', 'snapshot'));
      const response = await page.goto(language === 'zh-CN' ? '/' : `/${language}/`);
      expect(response.status()).toBe(200);
      await expect(page.locator('#ik-adminbar')).toHaveCount(0);
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      for (const [index, text] of expected.entries()) {
        const section = page.locator('main section').filter({ has: page.locator(index % 3 === 0 ? '.stat-number' : '.business-card h3') });
        const label = section.getByText(text, { exact: true });
        await expect(label).toHaveCount(1);
        await label.scrollIntoViewIfNeeded();
        await expect(label).toBeVisible();
      }
      const after = JSON.parse(fixture('theme-classic-language-fixture.php', 'snapshot'));
      await info.attach(`${mode}-settings`, { body: JSON.stringify({ before, after }), contentType: 'application/json' });
      const homeSettings = rows => rows.filter(row => /^(home_|blox_custom_)/.test(row.key));
      expect(homeSettings(after)).toEqual(homeSettings(before));
      expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
      await page.screenshot({ path: info.outputPath(`${language}-${mode}.png`), fullPage: true });
    }
  });
}
