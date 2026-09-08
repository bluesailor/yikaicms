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
  fixture('theme-site-baseline-fixture.php', 'prepare');
});
test.afterAll(() => {
  try { fixture('theme-site-baseline-fixture.php', 'restore'); }
  finally { try { fixture('catalog-baseline-fixture.php', 'restore'); } finally { cleanup(); } }
});
for (const mode of ['pretty', 'query']) {
  test(`Business ${mode} contact entry keeps current language @ci`, async ({ page, baseURL }, info) => {
    fixture('catalog-baseline-fixture.php', 'business', mode);
    const labels = { en: 'Inquire Now', ja: 'お問い合わせ', 'zh-CN': '立即咨询' };
    for (const language of ['en', 'ja', 'zh-CN']) {
      const home = mode === 'query' ? `/index.php?yk_route=home${language === 'zh-CN' ? '' : `&lang=${language}`}`
        : language === 'zh-CN' ? '/' : `/${language}/`;
      const contact = JSON.parse(fixture('theme-site-baseline-fixture.php', 'manifest', language)).find(r => r.kind === 'contact');
      expect((await page.goto(home)).status()).toBe(200);
      const mobile = info.project.name !== 'desktop-1440';
      if (mobile) await page.locator('#mobileMenuBtn').click();
      const scope = page.locator(mobile ? '#mobileMenu' : '#siteHeader nav:visible');
      const link = mobile ? scope.getByRole('link', { name: contact.title, exact: true })
        : scope.getByTestId('theme-header-consult');
      await expect(link).toHaveCount(1);
      if (!mobile) await expect(link).toHaveText(labels[language]);
      const response = page.waitForResponse(r => r.request().isNavigationRequest() && r.request().frame() === page.mainFrame());
      await link.click();
      expect((await response).status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page).toHaveURL(new URL(contact.url, baseURL).href);
      await expect(page.locator('main')).toContainText(contact.title);
    }
  });
}
