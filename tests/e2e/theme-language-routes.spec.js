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
  finally { try { fixture('catalog-baseline-fixture.php', 'restore'); } finally { cleanup(); } }
});
async function switchTo(page, language, info) {
  const mobile = info.project.name !== 'desktop-1440';
  if (mobile) await page.locator('#mobileMenuBtn').click();
  const scope = page.locator(mobile ? '#mobileMenu' : '#siteHeader');
  const switcher = scope.locator('[data-yk-language-switcher]').filter({ visible: true });
  await switcher.locator('summary').click();
  const response = page.waitForResponse(r => r.request().isNavigationRequest() && r.request().frame() === page.mainFrame());
  await switcher.locator(`a[hreflang="${language}"]`).click();
  expect((await response).status()).toBe(200);
  await expect(page.locator('html')).toHaveAttribute('lang', language);
}
for (const theme of ['default', 'business', 'minimal']) {
  test(`${theme} query home language switch keeps index endpoint @ci`, async ({ page }, info) => {
    fixture('catalog-baseline-fixture.php', theme === 'default' ? 'query' : theme, 'query');
    expect((await page.goto('/index.php?yk_route=home')).status()).toBe(200);
    for (const language of ['en', 'ja', 'zh-CN']) {
      await switchTo(page, language, info);
      const url = new URL(page.url());
      expect(url.pathname).toBe('/index.php');
      expect(url.searchParams.get('yk_route')).toBe('home');
      expect(url.searchParams.get('lang')).toBe(language === 'zh-CN' ? null : language);
    }
  });
}
for (const mode of ['pretty', 'query']) for (const kind of ['list', 'article-detail', 'product-detail']) {
  test(`default ${mode} ${kind} language switch reaches translated content @ci`, async ({ page }, info) => {
    fixture('catalog-baseline-fixture.php', mode);
    const routes = language => JSON.parse(fixture('theme-site-baseline-fixture.php', 'manifest', language));
    const source = routes('zh-CN').find(route => route.kind === kind);
    expect((await page.goto(source.url)).status()).toBe(200);
    for (const language of ['en', 'ja', 'zh-CN']) {
      const target = routes(language).find(route => route.kind === kind);
      await switchTo(page, language, info);
      await expect(page.locator('main')).toContainText(target.title);
      if (mode === 'query') {
        expect(new URL(page.url()).pathname).toBe('/index.php');
      }
    }
  });
}
