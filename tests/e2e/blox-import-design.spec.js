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

// T07：同 id 不同值的设计刻度，检查页说明两种模式的去向；选「保留模板外观」后改名新增并改写本模板的引用
test('template import lets the user keep the template look for conflicting design scales @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop template management flow');
  const name = 'Import scales review ' + Date.now();
  const extra = 'sp_t07_' + Date.now().toString(36);
  const json = JSON.stringify({
    format: 'yikaicms-blox-template', version: 1, type: 'section', name,
    design: { scales: { space: [{ id: 'md', name: 'Template M', value: '13px' }, { id: extra, name: 'Template extra', value: '44px' }] } },
    document: [{ type: 'section', settings: {}, columns: [{ elements: [
      { type: 'heading', data: { text: 'Scale review', style_padding: { d: 'token:md' }, style_margin_top: 'token:' + extra } },
    ] }] }],
  });
  await page.goto('/admin/blox_templates.php');
  await page.locator('textarea[name="template_json"]').fill(json);
  await page.locator('form').filter({ has: page.locator('input[name="action"][value="import"]') }).locator('button[type="submit"]').click();
  const scales = page.getByTestId('blox-import-scales');
  await expect(scales).toBeVisible();
  const conflict = scales.locator('[data-action="substitute"]');
  await expect(conflict).toContainText('md');
  await expect(conflict).toContainText('13px');
  await expect(scales.locator('[data-action="add"]')).toContainText(extra);
  await expect(scales.locator('input[name="scale_mode"][value="site"]')).toBeChecked();
  await scales.locator('input[name="scale_mode"][value="template"]').check();
  const renamed = (await conflict.locator('span:visible').innerText()).match(/md-[0-9a-f]{6}/);
  expect(renamed).not.toBeNull();
  await scales.screenshot({ path: testInfo.outputPath('import-scales-review.png') });
  await page.getByTestId('blox-import-confirm').click();
  await expect(page).toHaveURL(/imported=\d+/);
  const id = new URL(page.url()).searchParams.get('imported');

  // 再导出：文档改用改名后的刻度，包里带上它与来源值；本站原来的 md 不受影响
  const exported = JSON.parse(await (await page.request.get(`/admin/blox_templates.php?action=export&id=${id}`)).text());
  const data = exported.document.sections ? exported.document.sections[0].columns[0].elements[0].data : exported.document[0].columns[0].elements[0].data;
  expect(data.style_padding.d).toBe('token:' + renamed[0]);
  expect(data.style_margin_top).toBe('token:' + extra);
  const spaces = Object.fromEntries(exported.design.scales.space.map((item) => [item.id, item.value]));
  expect(spaces[renamed[0]]).toBe('13px');
  expect(spaces[extra]).toBe('44px');
});
