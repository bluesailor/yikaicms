const { test, expect } = require('./site-diagnostics');

// 客户评价管理（2.1.0）：安装数据带三条中文评价；新增、显示开关、删除；英文视图是另一组。
test('testimonials are managed per language @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  const name = `E2E 客户 ${Date.now()}`;

  await page.goto('/admin/testimonial.php?lang=zh-CN');
  const rows = page.locator('#testimonialRows tr[data-sort-row]');
  await expect(rows).toHaveCount(3);
  await expect(rows.first()).toContainText('陈思远');

  await page.getByTestId('testimonial-add').click();
  await page.locator('#tmName').fill(name);
  await page.locator('#tmRole').fill('测试经理');
  await page.locator('#tmContent').fill('E2E 评价内容');
  await page.locator('#testimonialForm button[type="submit"]').click();
  const row = page.locator('#testimonialRows tr', { hasText: name });
  await expect(row).toBeVisible();
  await expect(rows).toHaveCount(4);

  const status = row.locator('td').nth(4).locator('button');
  const before = (await status.textContent()).trim();
  await status.click();
  await expect(status).not.toHaveText(before);

  page.once('dialog', (d) => d.accept());
  await row.getByRole('button', { name: /删除|Delete/ }).click();
  await expect(page.locator('#testimonialRows tr', { hasText: name })).toHaveCount(0);

  await page.goto('/admin/testimonial.php?lang=en');
  await expect(page.locator('#testimonialRows tr[data-sort-row]').first()).toContainText('Siyuan Chen');
});
