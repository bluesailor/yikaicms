const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const installMarketThemes = require('./theme-market-fixture');
const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'language-boundary-fixture.php'), ...args], { cwd: root, encoding: 'utf8' });
let cleanup = () => {};
test.use({ storageState: { cookies: [], origins: [] }, reducedMotion: 'reduce' });
test.beforeAll(() => { cleanup = installMarketThemes(root, ['business', 'minimal']); });
test.afterEach(() => fixture('restore'));
test.afterAll(() => cleanup());

const route = (kind, row, mode, lang = '') => mode === 'query'
  ? `/index.php?yk_route=${kind}&id=${row.id}${lang ? `&lang=${lang}` : ''}`
  : `/${kind}.php?id=${row.id}${lang ? `&_lang=${lang}` : ''}`;

for (const mode of ['pretty', 'query']) for (const kind of ['article', 'product']) {
  test(`${mode} numeric ${kind} switches published translations @ci`, async ({ page }, info) => {
    const rows = JSON.parse(fixture('prepare', mode, 'zh-CN', 'default'))[kind];
    expect((await page.goto(route(kind, rows['zh-CN'], mode))).status()).toBe(200);
    for (const language of ['en', 'ja', 'zh-CN']) {
      const mobile = info.project.name !== 'desktop-1440';
      if (mobile) await page.locator('#mobileMenuBtn').click();
      const switcher = page.locator(mobile ? '#mobileMenu' : '#siteHeader')
        .locator('[data-yk-language-switcher]').filter({ visible: true });
      await switcher.locator('summary').click();
      const response = page.waitForResponse(r => r.request().isNavigationRequest() && r.request().frame() === page.mainFrame());
      await switcher.locator(`a[hreflang="${language}"]`).click();
      expect((await response).status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page.getByRole('heading', { name: rows[language].title, exact: true }).first()).toBeVisible();
    }
  });
}
for (const kind of ['article', 'product']) for (const state of ['missing', 'draft', 'deleted']) {
  test(`${kind} ${state} translation never exposes unpublished content @ci`, async ({ page }) => {
    const rows = JSON.parse(fixture('prepare', 'query', 'zh-CN', 'default', state))[kind];
    expect((await page.goto(route(kind, rows['zh-CN'], 'query', 'en'))).status()).toBe(200);
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.getByRole('heading', { name: rows['zh-CN'].title, exact: true }).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: rows.en.title, exact: true })).toHaveCount(0);
  });
}
for (const mode of ['pretty', 'query']) for (const language of ['en', 'ja']) for (const theme of ['default', 'business', 'minimal']) {
  test(`${theme} ${mode} ${language}-only detail returns to same-language home @ci`, async ({ page }) => {
    const rows = JSON.parse(fixture('prepare', mode, language, theme));
    for (const kind of ['article', 'product']) {
      expect((await page.goto(route(kind, rows[kind][language], mode))).status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      await expect(page.getByRole('heading', { name: rows[kind][language].title, exact: true }).first()).toBeVisible();
      await expect(page.locator('[data-yk-language-switcher]')).toHaveCount(0);
      const response = page.waitForResponse(r => r.request().isNavigationRequest() && r.request().frame() === page.mainFrame());
      await page.locator('#siteHeader a').filter({ has: page.locator('img') }).first().click();
      expect((await response).status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', language);
      expect(new URL(page.url()).pathname).toBe(mode === 'query' ? '/index.php' : '/');
    }
  });
}
