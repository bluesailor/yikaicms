const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const installMarketThemes = require('./theme-market-fixture');
const root = path.resolve(__dirname, '../..');
const fixture = (file, action) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), action], { cwd: root });
let cleanup;
test.beforeAll(() => {
  expect(path.basename(root)).toMatch(/^yikai-e2e-/);
  cleanup = installMarketThemes(root, ['business', 'minimal']);
  fixture('catalog-search-fixture.php', 'seed');
});
test.afterAll(() => {
  fixture('catalog-search-fixture.php', 'cleanup');
  fixture('catalog-baseline-fixture.php', 'restore');
  cleanup?.();
});

for (const mode of ['pretty', 'query']) {
  for (const [kind, start] of [['product', '/product.html'], ['article', '/news.html']]) {
    test(`${kind} ${mode}: matching results, pagination and empty results @ci`, async ({ page }) => {
      fixture('catalog-baseline-fixture.php', mode);
      await page.goto(start);
      const form = page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first();
      await form.locator('input[name="keyword"]').fill('Catalog Zero');
      await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
      const results = page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ });
      await expect(results.first()).toBeVisible();
      await expect(page.locator('body')).not.toContainText('Deprecated:');
      const first = await results.allTextContents();
      expect(first.length).toBeGreaterThan(0);
      expect(first.length).toBeLessThan(22);
      await expect(page.locator('body')).not.toContainText('Catalog Zero 0 0 ');
      await expect(page.locator('body')).not.toContainText('Catalog Unrelated');
      const seen = first.map(title => title.trim());
      for (let number = 2; number <= Math.ceil(22 / first.length); number++) {
        const next = page.getByRole('link', { name: String(number), exact: true });
        await expect(next).toHaveCount(1);
        await next.click();
        await expect(form.locator('input[name="keyword"]')).toHaveValue('Catalog Zero');
        await expect(results.first()).toBeVisible();
        const titles = (await results.allTextContents()).map(title => title.trim());
        expect(titles.every(title => !seen.includes(title))).toBeTruthy();
        seen.push(...titles);
        await page.reload();
        await expect(results).toHaveText(titles);
      }
      expect(seen.sort()).toEqual(Array.from({ length: 22 }, (_, i) => `Catalog Zero 0 1 ${i + 1}`).sort());
      await form.locator('input[name="keyword"]').fill('E2E-no-match-123456');
      await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
      await expect(results).toHaveCount(0);
      expect(new URL(page.url()).pathname).not.toBe('/');
      if (mode === 'query') expect(new URL(page.url()).searchParams.has('yk_route')).toBeTruthy();
      else expect(new URL(page.url()).searchParams.has('yk_route')).toBeFalsy();
    });
  }
}

for (const theme of ['business', 'minimal']) {
  for (const lang of ['en', 'ja']) {
    test(`${theme} ${lang} homepage uses localized about and advantage copy @ci`, async ({ page }) => {
      fixture('catalog-baseline-fixture.php', theme);
      const response = await page.goto(`/${lang}/`);
      expect(response.status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', lang);
      const body = page.locator('body');
      await expect(body).not.toContainText('我们是一家专注于企业数字化转型');
      await expect(body).not.toContainText('专业团队，优质服务，值得信赖');
      await expect(body).toContainText(lang === 'en'
        ? 'We are a technology company focused on enterprise digital transformation'
        : '当社は企業のデジタルトランスフォーメーションに特化した');
      await expect(body).toContainText(lang === 'en'
        ? 'Professional team, quality service, trusted partner'
        : '優れたサービス・信頼のパートナー');
    });
  }
}
