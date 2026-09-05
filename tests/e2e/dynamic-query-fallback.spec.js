const { test, expect } = require('@playwright/test');

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
