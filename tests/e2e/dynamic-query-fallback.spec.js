const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'dynamic-url-fixture.php'), action], {
  cwd: path.resolve(__dirname, '../..'),
  stdio: 'inherit',
});

test.beforeAll(() => fixture('seed'));
test.afterAll(() => fixture('cleanup'));

// 查询模式（fixture 设 url_mode=query）下，canonical 跟随 URL 模式转成可用的查询地址（2026-09-21 起，
// deploy/URL-COMPATIBILITY.md）：没有伪静态时漂亮地址是 404，canonical 不能指向它。只保留页面身份参数。
const canonicalOf = (body) => {
  const raw = body.match(/<link rel="canonical" href="([^"]+)"/i)?.[1] || '';
  return new URL(raw.replace(/&amp;/g, '&'), 'http://canonical.test');
};

test('index.php query fallback renders pages without rewrite and emits a working query canonical @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one HTTP routing pass is sufficient');

  const cases = [
    {
      path: '/index.php?yk_route=home',
      marker: 'Yikai CMS',
      canonical: { path: '/', params: {} },
    },
    {
      path: '/index.php?yk_route=page&parent=service-ja&slug=process-ja&lang=ja',
      marker: 'サービスフロー',
      canonical: { path: '/index.php', params: { yk_route: 'page', parent: 'service-ja', slug: 'process-ja', lang: 'ja' } },
    },
    {
      path: '/index.php?yk_route=search&keyword=%E6%99%BA%E8%83%BD',
      marker: '搜索',
      canonical: { path: '/index.php', params: { yk_route: 'search' } },
    },
  ];

  for (const item of cases) {
    const response = await request.get(item.path);
    expect(response.status(), item.path).toBe(200);
    const body = await response.text();
    expect(body, item.path).toContain(item.marker);
    expect(response.headers()['x-yikai-render'], item.path).toBe('dynamic');
    const canonical = canonicalOf(body);
    expect(canonical.pathname, item.path).toBe(item.canonical.path);
    expect(Object.fromEntries(canonical.searchParams), item.path).toEqual(item.canonical.params);
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

test('query mode canonical URLs stay on working query routes and conflicting languages are rejected @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one HTTP routing pass is sufficient');

  for (const [pathName, route] of [
    ['/index.php?yk_route=product&id=1', 'product'],
    ['/index.php?yk_route=page&id=2', 'page'],
    ['/index.php?yk_route=detail&id=1', 'article'], // 内容 1 是文章：canonical 规范到文章路由
  ]) {
    const response = await request.get(pathName);
    expect(response.status(), pathName).toBe(200);
    const canonical = canonicalOf(await response.text());
    expect(canonical.pathname, pathName).toBe('/index.php');
    expect(canonical.searchParams.get('yk_route'), pathName).toBe(route);
    expect(canonical.href, pathName).not.toMatch(/\.html(?:$|[?#])/);
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
