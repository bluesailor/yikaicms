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
  fixture('theme-site-baseline-fixture.php', 'prepare');
});
test.afterAll(() => {
  try { fixture('theme-site-baseline-fixture.php', 'restore'); }
  finally { fixture('catalog-baseline-fixture.php', 'restore'); cleanup(); }
});
const home = lang => lang === 'zh-CN' ? '/' : `/${lang}/`;
const labels = { en: 'English', ja: '日本語', 'zh-CN': '中文' };
const selectTheme = theme => {
  fixture('catalog-baseline-fixture.php', theme.startsWith('default') ? 'pretty' : theme);
  fixture('theme-site-baseline-fixture.php', theme === 'default-below' ? 'nav-below' : 'nav-right');
};
for (const theme of ['default', 'default-below', 'business', 'minimal']) {
  test(`${theme} home language menu is reachable and changes actual language @ci`, async ({ page, baseURL }, info) => {
    selectTheme(theme);
    await page.goto('/');
    for (const language of ['en', 'ja', 'zh-CN']) {
      if (info.project.name !== 'desktop-1440') {
        await page.locator('#mobileMenuBtn').click();
        await expect(page.locator('#mobileMenu')).toBeVisible();
      }
      const scope = info.project.name === 'desktop-1440' ? page.locator('#siteHeader') : page.locator('#mobileMenu');
      const switcher = scope.locator('[data-yk-language-switcher]:visible, #langSwitcher:visible');
      await expect(switcher).toHaveCount(1);
      const trigger = switcher.locator('summary, button');
      if (await trigger.count()) {
        if (language === 'en') { await trigger.focus(); await trigger.press('Enter'); }
        else await trigger.click();
      }
      await page.screenshot({ path: info.outputPath(`${theme}-${language}-menu.png`) });
      const link = switcher.getByRole('link', { name: labels[language], exact: true });
      await expect(link).toBeVisible();
      if (info.project.name !== 'desktop-1440') {
        // Use layout pixels; viewport rectangles can round 44px to 43.99994 after scrolling.
        expect(await trigger.evaluate(node => node.offsetHeight)).toBeGreaterThanOrEqual(44);
        expect(await link.evaluate(node => node.offsetHeight)).toBeGreaterThanOrEqual(44);
      }
      expect(await page.evaluate(() => document.documentElement.scrollWidth - innerWidth)).toBeLessThanOrEqual(1);
      const navigation = page.waitForResponse(response => response.request().isNavigationRequest()
        && response.request().frame() === page.mainFrame());
      if (language === 'en') { await link.focus(); await link.press('Enter'); }
      else await link.click();
      expect((await navigation).status()).toBe(200);
      await expect(page).toHaveURL(new URL(home(language), baseURL).href);
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page.locator('#ik-adminbar, [data-yk-area="header"]')).toHaveCount(0);
    }
  });
  test(`${theme} logo returns from localized product to same-language home @ci`, async ({ page, baseURL }) => {
    selectTheme(theme);
    for (const language of ['en', 'ja', 'zh-CN']) {
      const routes = JSON.parse(fixture('theme-site-baseline-fixture.php', 'manifest', language));
      const detail = routes.find(route => route.kind === 'product-detail');
      expect((await page.goto(detail.url)).status()).toBe(200);
      const logo = page.locator('#siteHeader a').filter({ has: page.locator('img') }).first();
      await expect(logo).toHaveAttribute('href', home(language));
      const navigation = page.waitForResponse(response => response.request().isNavigationRequest()
        && response.request().frame() === page.mainFrame());
      await logo.click();
      expect((await navigation).status()).toBe(200);
      await expect(page).toHaveURL(new URL(home(language), baseURL).href);
      await expect(page.locator('html')).toHaveAttribute('lang', language);
    }
  });
}
test('Minimal Japanese header preserves logo proportions and navigation gap @ci', async ({ page }, info) => {
  fixture('catalog-baseline-fixture.php', 'minimal');
  await page.goto('/ja/');
  const logo = page.locator('#siteHeader > div img').first();
  await expect.poll(() => logo.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
  const metrics = await page.evaluate(() => {
    const img = document.querySelector('#siteHeader > div img');
    const logo = img.getBoundingClientRect();
    const desktop = document.querySelector('#siteHeader > div nav');
    const nav = (desktop.getBoundingClientRect().width > 0 ? desktop : document.querySelector('#mobileMenuBtn')).getBoundingClientRect();
    return { width: logo.width, height: logo.height, naturalRatio: img.naturalWidth / img.naturalHeight,
      gap: nav.left - logo.right, overflow: document.documentElement.scrollWidth - innerWidth };
  });
  await info.attach('header-geometry', { body: JSON.stringify(metrics), contentType: 'application/json' });
  await page.screenshot({ path: info.outputPath('minimal-ja-header.png') });
  expect(metrics.width / metrics.height).toBeCloseTo(metrics.naturalRatio, 1);
  expect(metrics.gap).toBeGreaterThanOrEqual(16);
  expect(metrics.overflow).toBeLessThanOrEqual(1);
});
