const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const installMarketThemes = require('./theme-market-fixture');
const root = path.resolve(__dirname, '../..');
const fixture = (file, ...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), ...args], { cwd: root, encoding: 'utf8' });
let cleanup = () => {};
test.use({ storageState: { cookies: [], origins: [] }, reducedMotion: 'reduce' });
test.beforeAll(() => {
  cleanup = installMarketThemes(root, ['business', 'minimal']);
  fixture('catalog-baseline-fixture.php', 'pretty');
  fixture('home-language-carousel-fixture.php', 'prepare');
});
test.afterAll(() => {
  try { fixture('home-language-carousel-fixture.php', 'restore'); }
  finally { fixture('catalog-baseline-fixture.php', 'restore'); cleanup(); }
});
for (const theme of ['default', 'business', 'minimal']) {
  for (const language of ['zh-CN', 'en', 'ja']) {
    test(`${theme} ${language} legacy carousel follows translation groups and keeps selection @ci`, async ({ page, baseURL }, info) => {
      fixture('catalog-baseline-fixture.php', theme === 'default' ? 'pretty' : theme);
      const expected = JSON.parse(fixture('home-language-carousel-fixture.php', 'manifest', language));
      const before = fixture('home-language-carousel-fixture.php', 'snapshot');
      expect((await page.goto(language === 'zh-CN' ? '/' : `/${language}/`)).status()).toBe(200);
      await expect(page.locator('#ik-adminbar')).toHaveCount(0);
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      const carousel = page.locator('.yk-pc');
      await expect(carousel.getByRole('heading')).toHaveText('Customer selected products');
      const cards = carousel.locator('.yk-pc-card');
      await expect(cards).toHaveCount(expected.length);
      for (const [i, item] of expected.entries()) {
        await expect(cards.nth(i).locator('img')).toHaveAttribute('alt', item.title);
        await expect(cards.nth(i)).toContainText(item.title);
        await expect(cards.nth(i)).toHaveAttribute('href', item.url);
      }
      await carousel.scrollIntoViewIfNeeded();
      await expect.poll(() => cards.first().locator('img').evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
      await page.screenshot({ path: info.outputPath(`${theme}-${language}.png`) });
      const destination = new URL(expected[0].url, baseURL).href;
      const navigation = page.waitForResponse(response => response.url() === destination && response.request().isNavigationRequest());
      await cards.first().click();
      expect((await navigation).status()).toBe(200);
      await expect(page).toHaveURL(destination);
      await expect(page.locator('main').getByRole('heading', { name: expected[0].title, exact: true })).toBeVisible();
      expect(fixture('home-language-carousel-fixture.php', 'snapshot')).toBe(before);
    });
  }
}
