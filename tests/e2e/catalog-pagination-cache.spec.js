const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const fs = require('fs');
const root = path.resolve(__dirname, '../..');
const fixture = (file, action) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), action], { cwd: root });
test.beforeAll(() => {
  fixture('catalog-baseline-fixture.php', 'cache-pretty');
  fixture('catalog-search-fixture.php', 'seed');
});
test.afterAll(() => {
  fixture('catalog-search-fixture.php', 'cleanup');
  fixture('catalog-baseline-fixture.php', 'restore');
});

for (const [kind, route, dynamic] of [
  ['product', '/product.html', '/index.php?yk_route=product_list'],
  ['article', '/news.html', '/index.php?yk_route=list&slug=news'],
]) {
  test(`${kind}: anonymous cached pagination invalidates after admin save @ci`, async ({ page, browser, baseURL }, info) => {
    const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, viewport: info.project.use.viewport });
    const publicPage = await visitor.newPage();
    const errors = [];
    publicPage.on('pageerror', e => errors.push(e.message));
    publicPage.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
    publicPage.on('response', r => { if (r.status() >= 500) errors.push(`${r.status()} ${r.url()}`); });
    const evidence = [];
    const cards = publicPage.locator('a h3');
    async function visit(url, cache, count) {
      const response = await publicPage.goto(url);
      expect(response.status()).toBe(200);
      expect(response.headers()['x-cache']).toBe(cache);
      if (count !== null) await expect(cards).toHaveCount(count);
      evidence.push({ url, cache: response.headers()['x-cache'] || 'BYPASS', count: await cards.count() });
    }
    async function save(size) {
      await page.goto('/admin/setting.php?tab=pagination');
      await page.locator(`[name="settings[catalog_${kind}_page_size]"]`).fill(String(size));
      const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/setting.php'));
      await page.locator('#settingForm button[type="submit"]').click();
      expect((await (await response).json()).code).toBe(0);
    }
    try {
      expect(await visitor.cookies()).toEqual([]);
      await save(8);
      await visit(route, 'MISS', 8);
      const first = await cards.allTextContents();
      await visit(route, 'HIT', 8);
      expect(await cards.allTextContents()).toEqual(first);
      await visit(route + '?page=2', 'MISS', 8);
      const second = await cards.allTextContents();
      expect(second.every(title => !first.includes(title))).toBeTruthy();
      await visit(route + '?page=2', 'HIT', 8);
      const english = kind === 'product' ? '/en/product-en.html' : '/en/news-en.html';
      // Language routing injects _lang, which is outside the current cache allowlist.
      await visit(english, undefined, null);
      await expect(publicPage.locator('html')).toHaveAttribute('lang', 'en');
      await expect(publicPage.getByRole('heading', { name: /^Catalog Zero/ })).toHaveCount(0);
      const englishTitles = await cards.allTextContents();
      await visit(english, undefined, null);
      expect(await cards.allTextContents()).toEqual(englishTitles);
      await visit(route, 'HIT', 8);
      expect(await cards.allTextContents()).toEqual(first);
      await save(12);
      await visit(route, 'MISS', 12);
      await visit(route, 'HIT', 12);
      await visit(route + '?page=2', 'MISS', 12);
      await visit(route + '?page=2', 'HIT', 12);
      await visit(english, undefined, null);
      await expect(publicPage.locator('html')).toHaveAttribute('lang', 'en');
      await visit(english, undefined, null);
      await visit(dynamic, undefined, 12);
      await visit(route + '?keyword=Catalog+Zero', undefined, 12);
      await visit(route + '?keyword=Catalog+Zero', undefined, 12);
      if (kind === 'product') {
        const ids = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
        for (const [value, count] of [['6', 6], ['', 12]]) {
          await page.goto(`/admin/setting.php?tab=pagination&channel_id=${ids.product_page}`);
          await page.locator('[name="channel_page_size"]').fill(value);
          const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/setting.php'));
          await page.locator('#channelPaginationForm button[type="submit"]').click();
          expect((await (await response).json()).code).toBe(0);
          await visit(route, 'MISS', count);
          await visit(route, 'HIT', count);
        }
      }
      expect(errors).toEqual([]);
    } finally {
      await info.attach('cache-evidence', { body: JSON.stringify(evidence, null, 2), contentType: 'application/json' });
      await visitor.close();
    }
  });
}
