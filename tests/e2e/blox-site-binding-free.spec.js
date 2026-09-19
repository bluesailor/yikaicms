// 站点数据绑定自 v1.20.2 起为免费能力：未授权站点（免费模式）也能给标题绑定站点资料并保存。
const { test, expect } = require('@playwright/test');
const { addTemporaryHeading, frame, waitPreviewSettled, restoreClean } = require('./helpers');

test('free mode binds a heading to site data and saves the draft @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Binding popover exercised on desktop.');
  test.skip(process.env.SMOKE_BLOX_ADVANCED !== '0', 'Free-mode assertion: runs with --free.');
  const serverErrors = [];
  page.on('response', (r) => { if (r.url().includes('/admin/blox_') && r.status() >= 500) serverErrors.push(`${r.status()} ${r.url()}`); });

  await page.goto('/admin/blox_editor.php?home=1&lang=zh-CN', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  await addTemporaryHeading(page);

  // 绑定入口对免费用户可见、可用（不再位于专业功能区）
  const binding = page.getByTestId('blox-heading-text-binding');
  await expect(binding).toBeVisible();
  await binding.click();
  const source = page.getByTestId('blox-heading-text-source');
  await expect(source).toBeVisible();
  await source.selectOption('site_name');
  await waitPreviewSettled(page);
  expect(await page.evaluate(() => window.Alpine.$data(document.body).selEl.data.site_field)).toBe('site_name');

  // 预览与保存都不应被授权拦截
  const save = page.waitForResponse((r) => r.request().method() === 'POST' && r.url().includes('/admin/blox_home_api.php'));
  await page.getByTestId('blox-save').click();
  const body = await (await save).json();
  expect(body.code, JSON.stringify(body)).toBe(0);
  expect(serverErrors).toEqual([]);

  await restoreClean(page);
});
