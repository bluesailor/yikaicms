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
      // 页头抽屉导航里也有一个隐藏的站内搜索表单（演示站页头自带），只认列表页 <main> 里的那个。
      const form = page.locator('main form').filter({ has: page.locator('input[name="keyword"]') }).first();
      await form.locator('input[name="keyword"]').fill('Catalog Zero');
      if (kind === 'product') {
        await form.locator('button[type="submit"]').click();
        await expect(page).toHaveURL(/keyword=Catalog(?:\+|%20)Zero/);
      } else {
        await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
      }
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
        const before = page.url();
        await next.click();
        if (kind === 'product') await page.waitForURL(url => url.href !== before);
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
      if (kind === 'product') {
        await form.locator('button[type="submit"]').click();
        await expect(page).toHaveURL(/keyword=E2E-no-match-123456/);
      } else {
        await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
      }
      await expect(results).toHaveCount(0);
      expect(new URL(page.url()).pathname).not.toBe('/');
      if (mode === 'query') expect(new URL(page.url()).searchParams.has('yk_route')).toBeTruthy();
      else expect(new URL(page.url()).searchParams.has('yk_route')).toBeFalsy();
    });
  }
}

test('product catalog filters progressively enhance, announce updates and survive history/error paths @ci', async ({ page }) => {
  fixture('catalog-baseline-fixture.php', 'pretty');
  await page.addInitScript(() => {
    const nativeFetch = window.fetch.bind(window);
    window.fetch = (input, options) => {
      const url = typeof input === 'string' ? input : input.url;
      return url.includes('keyword=E2E-AJAX-failure')
        ? Promise.resolve(new Response('', { status: 503, headers: { 'Content-Type': 'text/html; charset=UTF-8' } }))
        : nativeFetch(input, options);
    };
  });
  await page.goto('/product.html');
  const root = page.locator('[data-product-catalog][data-catalog-ajax]');
  const search = root.locator('input[name="keyword"]').first();
  const live = page.locator('[data-catalog-live]');
  await expect(live).toHaveCount(1);
  await live.evaluate(node => { window.__catalogLiveNode = node; });
  const initialHistoryLength = await page.evaluate(() => history.length);
  const firstResponse = page.waitForResponse(response =>
    response.request().headers()['x-requested-with'] === 'XMLHttpRequest'
      && new URL(response.url()).searchParams.get('keyword') === 'Catalog');
  await search.fill('Catalog');
  expect((await firstResponse).status()).toBe(200);
  await expect(page).toHaveURL(/keyword=Catalog(?:$|&)/);
  expect(await page.evaluate(() => history.length)).toBe(initialHistoryLength);

  const ajaxResponse = page.waitForResponse(response =>
    response.request().headers()['x-requested-with'] === 'XMLHttpRequest'
      && new URL(response.url()).searchParams.get('keyword') === 'Catalog Zero');
  await search.fill('Catalog Zero');
  const response = await ajaxResponse;
  expect(response.status()).toBe(200);
  await expect(page).toHaveURL(/keyword=Catalog(?:\+|%20)Zero/);
  await expect(page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ }).first()).toBeVisible();
  await expect(live).toHaveText(await root.getAttribute('data-catalog-updated'));
  expect(await live.evaluate(node => node === window.__catalogLiveNode && node.isConnected)).toBeTruthy();
  expect(await page.evaluate(() => history.length)).toBe(initialHistoryLength);
  await expect(root.locator('input[name="keyword"]').first()).toBeFocused();

  const searchUrl = page.url();
  const next = root.getByRole('link', { name: '2', exact: true });
  await next.click();
  await page.waitForURL(url => url.href !== searchUrl);
  expect(await page.evaluate(() => history.length)).toBe(initialHistoryLength + 1);
  await page.evaluate(() => history.back());
  await expect(page).toHaveURL(searchUrl);
  await expect(page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ }).first()).toBeVisible();

  const beforeFailure = await page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ }).allTextContents();
  await root.locator('input[name="keyword"]').first().fill('E2E-AJAX-failure');
  await expect(page.locator('[data-catalog-error-box]')).toBeVisible();
  await expect(page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ })).toHaveText(beforeFailure);
});

test('catalog category links use full navigation and toggles expose an accessible relationship @ci', async ({ page }) => {
  fixture('catalog-baseline-fixture.php', 'pretty');
  await page.goto('/product.html');
  const root = page.locator('[data-product-catalog][data-catalog-ajax]');
  const toggle = root.locator('.category-toggle').first();
  await expect(toggle).toBeVisible();
  expect(await toggle.getAttribute('aria-label')).toBeTruthy();
  const controlledId = await toggle.getAttribute('aria-controls');
  expect(controlledId).toBeTruthy();
  await expect(page.locator(`#${controlledId}`)).toHaveCount(1);

  const category = root.locator('[data-catalog-categories] a').nth(1);
  const navigation = page.waitForNavigation();
  const request = page.waitForRequest(req => req.isNavigationRequest() && req.frame() === page.mainFrame());
  await category.click();
  await navigation;
  const navigationRequest = await request;
  expect(navigationRequest.headers()['x-requested-with']).toBeUndefined();
  expect(new URL(page.url()).pathname).not.toBe('/product.html');
});

test('out-of-range page and oversized keyword render and canonicalize as normalized defaults @ci', async ({ page }) => {
  fixture('catalog-baseline-fixture.php', 'pretty');
  await page.goto('/product.html');
  const categoryHref = await page.locator('[data-catalog-categories] a').nth(1).getAttribute('href');
  const invalidUrl = new URL(categoryHref, page.url());
  invalidUrl.searchParams.set('page', '10001');
  invalidUrl.searchParams.set('keyword', 'x'.repeat(101));
  await page.goto(invalidUrl.href);
  const root = page.locator('[data-product-catalog][data-catalog-ajax]');
  await expect(root.locator('input[name="keyword"]').first()).toHaveValue('');
  await expect(page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ }).first()).toBeVisible();
  const canonical = await page.locator('link[rel="canonical"]').getAttribute('href');
  expect(new URL(canonical).pathname).toBe(new URL(categoryHref, page.url()).pathname);
  expect(new URL(canonical).search).toBe('');
});

test('product catalog remains usable with JavaScript disabled @ci', async ({ browser }) => {
  fixture('catalog-baseline-fixture.php', 'pretty');
  const context = await browser.newContext({ javaScriptEnabled: false });
  const page = await context.newPage();
  try {
    await page.goto('/product.html');
    const form = page.locator('main form').filter({ has: page.locator('input[name="keyword"]') }).first();
    await form.locator('input[name="keyword"]').fill('Catalog Zero');
    await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
    await expect(page).toHaveURL(/keyword=Catalog(?:\+|%20)Zero/);
    await expect(page.getByRole('heading', { name: /^Catalog Zero 0 1 \d+$/ }).first()).toBeVisible();
  } finally {
    await context.close();
  }
});

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
