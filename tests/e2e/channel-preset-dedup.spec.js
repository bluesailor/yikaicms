const { test, expect } = require('@playwright/test');

test('existing channel identities cannot be selected again @ci', async ({ page }, info) => {
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const response = await page.goto('/admin/channel_batch.php');
  expect(response.status()).toBe(200);
  for (const key of ['products', 'solutions', 'services', 'jobs']) {
    const checkbox = page.locator(`.js-ch[value="${key}"]`);
    await expect(checkbox).toBeDisabled();
    await expect(page.locator('label').filter({ has: checkbox })).toContainText('已存在');
  }
  const faq = page.locator('.js-ch[value="faq"]');
  await expect(page.locator('label').filter({ has: page.locator('.js-ch[value="products"]') }))
    .toContainText('现有栏目：产品中心（/product）');
  await expect(faq).toBeDisabled();
  await expect(page.locator('label').filter({ has: faq })).toContainText('存在冲突');
  await page.locator('#selAll').click();
  expect(await page.locator('.js-ch:disabled:checked').count()).toBe(0);
  for (const width of [1440, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await expect(page.locator('#btnGenerate')).toBeVisible();
    await page.screenshot({ path: info.outputPath(`channel-presets-${width}.png`), fullPage: true });
  }
  expect(errors).toEqual([]);
});
