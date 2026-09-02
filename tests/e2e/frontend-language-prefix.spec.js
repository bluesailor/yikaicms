const { test, expect } = require('@playwright/test');

const routes = [
  {
    path: '/en/download-en.html',
    lang: 'en',
    pageTitle: 'Downloads',
    record: 'Product Manual V2.0',
    category: 'Software Downloads',
    forbidden: ['下载中心', '产品使用手册 V2.0', '软件下载'],
  },
  {
    path: '/ja/download-ja.html',
    lang: 'ja',
    pageTitle: 'ダウンロード',
    record: '製品マニュアル V2.0',
    category: 'ソフトウェア',
    forbidden: ['下载中心', '产品使用手册 V2.0', '软件下载'],
  },
];

test('language-prefixed download pages keep localized content and categories @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one HTTP routing pass is sufficient');

  for (const route of routes) {
    const response = await request.get(route.path);
    expect(response.status(), route.path).toBe(200);

    const body = await response.text();
    expect(body, route.path).toMatch(new RegExp(`<html[^>]+lang=["']${route.lang}["']`, 'i'));
    expect(body, route.path).toContain(route.pageTitle);
    expect(body, route.path).toContain(route.record);
    expect(body, route.path).toContain(route.category);
    for (const forbidden of route.forbidden) {
      expect(body, `${route.path} leaked ${forbidden}`).not.toContain(forbidden);
    }
  }
});
