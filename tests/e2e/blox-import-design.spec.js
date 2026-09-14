const { test, expect } = require('@playwright/test');

test('template import reviews dependencies before creating a draft @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop template management flow');
  const name = 'Import design review ' + Date.now();
  const json = JSON.stringify({
    format: 'yikaicms-blox-template', version: 1, type: 'section', name,
    document: [{ type: 'section', settings: { bg_color: 'var(--yk-color-remote_test)' },
      columns: [{ elements: [{ type: 'heading', data: { text: 'Import review', _global_style: 'remote_style' } }] }] }],
  });
  await page.goto('/admin/blox_templates.php');
  await page.locator('textarea[name="template_json"]').fill(json);
  await page.locator('form').filter({ has: page.locator('input[name="action"][value="import"]') }).locator('button[type="submit"]').click();
  const review = page.getByTestId('blox-import-review');
  await expect(review).toBeVisible();
  await expect(page.getByTestId('blox-import-missing-tokens')).toContainText('remote_test');
  await expect(page.getByTestId('blox-import-missing-styles')).toContainText('remote_style');
  await expect(page.locator('tr').filter({ hasText: name })).toHaveCount(0);
  await review.locator('select[name="design_tokens[remote_test]"]').selectOption('primary');
  await review.locator('input[value="detach"]').check();
  await expect(review.locator('select[name="design_styles[remote_style]"]')).toBeHidden();
  await review.screenshot({ path: testInfo.outputPath('import-design-review.png') });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect(review).toBeVisible();
  await expect.poll(() => review.evaluate(el => el.scrollWidth > el.clientWidth + 1)).toBe(false);
  await review.screenshot({ path: testInfo.outputPath('import-design-review-mobile.png') });
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.getByTestId('blox-import-confirm').click();
  await expect(page).toHaveURL(/imported=\d+/);
  const row = page.locator('tr').filter({ hasText: name });
  await expect(row).toHaveCount(1);
  await expect(row.getByTestId('blox-template-design-missing')).toHaveCount(0);
});
