const { test, expect } = require('@playwright/test');

test('admin directory resolves its index.php entrypoint @ci', async ({ request }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one HTTP routing pass is sufficient');

  const directory = await request.get('/admin/');
  const explicit = await request.get('/admin/index.php');

  expect(directory.status()).toBe(200);
  expect(explicit.status()).toBe(200);
  expect(await directory.text()).toContain('<title>控制台 - 后台管理</title>');
  expect(await explicit.text()).toContain('<title>控制台 - 后台管理</title>');
});
