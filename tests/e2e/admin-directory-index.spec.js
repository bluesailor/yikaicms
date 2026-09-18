const { test, expect } = require('@playwright/test');

test('admin directory resolves its index.php entrypoint @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one HTTP routing pass is sufficient');

  const directory = await request.get('/admin/');
  const explicit = await request.get('/admin/index.php');

  expect(directory.status()).toBe(200);
  expect(explicit.status()).toBe(200);
  // 持有付费模块时后台品牌可自定义（专业模式测试站签有 blox 模块 → 种子里的「后台管理」），免费模式为出厂品牌。
  const title = /<title>控制台 - (?:后台管理|Yikai CMS)<\/title>/;
  expect(await directory.text()).toMatch(title);
  expect(await explicit.text()).toMatch(title);
});
