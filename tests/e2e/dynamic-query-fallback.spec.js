const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'dynamic-url-fixture.php'), action], {
  cwd: path.resolve(__dirname, '../..'),
  stdio: 'inherit',
});

test.beforeAll(() => fixture('seed'));
test.afterAll(() => fixture('cleanup'));

test('index.php query fallback renders pages without rewrite and emits a pretty canonical @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one HTTP routing pass is sufficient');

  const cases = [
    {
      path: '/index.php?yk_route=home',
      marker: 'Yikai CMS',
      canonical: '/',
    },
    {
      path: '/index.php?yk_route=page&parent=service-ja&slug=process-ja&lang=ja',
      marker: 'サービスフロー',
      canonical: '/ja/service-ja/process-ja.html',
    },
    {
      path: '/index.php?yk_route=search&keyword=%E6%99%BA%E8%83%BD',
      marker: '搜索',
      canonical: '/search.html',
    },
  ];

  for (const item of cases) {
    const response = await request.get(item.path);
    expect(response.status(), item.path).toBe(200);
    const body = await response.text();
    expect(body, item.path).toContain(item.marker);
    const canonical = response.headers()['x-yikai-render'] === 'dynamic'
      ? body.match(/<link rel="canonical" href="([^"]+)"/i)?.[1]
      : null;
    expect(canonical, item.path).toContain(item.canonical);
    expect(canonical, item.path).not.toContain('yk_route');
  }
});

test('invalid index.php query route is a real 404, not the home page @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one HTTP routing pass is sufficient');

  const response = await request.get('/index.php?yk_route=unknown');
  expect(response.status()).toBe(404);
});

test('query-mode GET forms retain their route, language and category @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one browser form pass is sufficient');

  await page.goto('/index.php?yk_route=search&lang=en');
  await page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first().locator('input[name="keyword"]').fill('Smart');
  await Promise.all([
    page.waitForURL(url => url.searchParams.get('yk_route') === 'search'),
    page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first().locator('button[type="submit"]').click(),
  ]);
  expect(new URL(page.url()).searchParams.get('lang')).toBe('en');
  await expect(page.locator('h1')).toContainText(/Search|搜索/);

  await page.goto('/index.php?yk_route=news&cat=company-news-en&lang=en');
  const newsForm = page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first();
  await newsForm.locator('input[name="keyword"]').fill('Digital');
  await Promise.all([
    page.waitForURL(url => url.searchParams.get('yk_route') === 'news'),
    newsForm.locator('button[type="submit"]').click(),
  ]);
  const newsUrl = new URL(page.url());
  expect(newsUrl.searchParams.get('cat')).toBe('company-news-en');
  expect(newsUrl.searchParams.get('lang')).toBe('en');
});

test('query mode keeps canonical URLs pretty and rejects conflicting languages @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one HTTP routing pass is sufficient');

  for (const pathName of [
    '/index.php?yk_route=product&id=1',
    '/index.php?yk_route=page&id=2',
    '/index.php?yk_route=detail&id=1',
  ]) {
    const response = await request.get(pathName);
    expect(response.status(), pathName).toBe(200);
    const body = await response.text();
    const canonical = body.match(/<link rel="canonical" href="([^"]+)"/i)?.[1] || '';
    expect(canonical, pathName).not.toContain('yk_route');
    expect(canonical, pathName).not.toContain('/index.php');
    expect(canonical, pathName).toMatch(/\.html$/);
  }

  const conflict = await request.get('/index.php?yk_route=home&lang=ja&_lang=zh-CN');
  expect(conflict.status()).toBe(404);
  const same = await request.get('/index.php?yk_route=home&lang=ja&_lang=ja');
  expect(same.status()).toBe(200);
  expect(await same.text()).toContain('<html lang="ja"');
});

test('search results and counters stay in the current language @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one multilingual search pass is sufficient');

  const response = await request.get('/index.php?yk_route=search&type=product&keyword=%E6%99%BA%E8%83%BD&lang=en');
  expect(response.status()).toBe(200);
  const body = await response.text();
  expect(body).toContain('<html lang="en"');
  expect(body).not.toContain('智能电能表');
  expect(body).not.toContain('智能显示终端');
  expect(body).not.toContain('智能物联网网关');
});
