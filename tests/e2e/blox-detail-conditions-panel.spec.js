const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled } = require('./helpers');

const fixture = (action, id = '') => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), action, String(id)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

// 只跑桌面项目：条件面板在设置折叠区里，窄屏需要额外开合路径，另开一版再覆盖。
test('detail condition panel round-trips complete rules without the legacy ui_scope path', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop condition-panel baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const products = JSON.parse(fixture('products'));
  expect(products.length).toBeGreaterThan(0);
  let id;
  try {
    await page.goto('/admin/site_design.php');
    await page.getByTestId('site-design-products').click();
    await page.getByTestId('product-design-name').fill(`TB-R2 cond ${info.project.name}`);
    await page.locator('select[name="language"]').selectOption('zh-CN');
    await page.getByTestId('product-design-create').click();
    await expect(page).toHaveURL(/blox_editor.php\?template=/);
    id = new URL(page.url()).searchParams.get('template');
    const editor = page.url();
    await waitPreviewSettled(page);
    await page.getByTestId('product-template-settings').locator('summary').click();
    await expect(page.getByTestId('blox-detail-conditions')).toBeVisible();
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();

    // 纳入：指定分类 + 含子级
    await page.getByTestId('blox-cond-add-include-category').click();
    const categoryIds = await page.getByTestId('blox-cond-include-target-0')
      .locator('option').evaluateAll((options) => options.map((option) => option.value));
    expect(categoryIds.length, 'demo site needs a product category').toBeGreaterThan(0);
    await page.getByTestId('blox-cond-include-target-0').selectOption(categoryIds[0]);
    await page.getByTestId('blox-cond-include-children-0').check();

    // 排除：指定产品（resolver 不允许 exclude 用 all，面板也不提供该按钮）
    await page.getByTestId('blox-cond-add-exclude-item').click();
    const productIds = await page.getByTestId('blox-cond-exclude-target-0')
      .locator('option').evaluateAll((options) => options.map((option) => option.value));
    expect(productIds.length).toBeGreaterThan(0);
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(productIds[0]);
    await expect(page.getByTestId('blox-cond-dirty')).toBeVisible();

    const expectedInclude = [{ kind: 'category', ids: [Number(categoryIds[0])], include_children: true }];
    const expectedExclude = [{ kind: 'item', ids: [Number(productIds[0])], include_children: false }];

    // 保存：请求必须带完整条件，且不得再走旧的 ui_scope 简化投影
    const saved = page.waitForRequest((request) => request.url().includes('/admin/blox_template_api.php')
      && new URLSearchParams(request.postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    const body = new URLSearchParams((await saved).postData() || '');
    expect(body.get('ui_scope'), '完整条件提交不能与 ui_scope 并存').toBeNull();
    const posted = JSON.parse(body.get('conditions_json') || 'null');
    expect(posted.include).toEqual(expectedInclude);
    expect(posted.exclude).toEqual(expectedExclude);

    // 落库：由 PHP 侧独立读取，不依赖界面自述
    const stored = JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template;
    expect(stored.version).toBe(2);
    expect(stored.content_type).toBe('product');
    expect(stored.include).toEqual(expectedInclude);
    expect(stored.exclude).toEqual(expectedExclude);

    // 重开：字段逐项一致，且不再显示"条件已修改"
    await page.goto(editor);
    await waitPreviewSettled(page);
    // 本地恢复稿会遮住设置区；本次要验的是服务器版本，所以明确放弃恢复稿
    if (await page.getByTestId('blox-recovery-dialog').isVisible().catch(() => false)) {
      await page.getByTestId('blox-recovery-discard').click();
    }
    await page.getByTestId('product-template-settings').locator('summary').click();
    await expect(page.getByTestId('blox-cond-include-kind-0')).toHaveValue('category');
    await expect(page.getByTestId('blox-cond-include-children-0')).toBeChecked();
    await expect(page.getByTestId('blox-cond-exclude-kind-0')).toHaveValue('item');
    await expect(page.getByTestId('blox-cond-include-target-0')).toHaveValues([categoryIds[0]]);
    await expect(page.getByTestId('blox-cond-exclude-target-0')).toHaveValues([productIds[0]]);
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();
  } finally {
    if (id) fixture('restore', id);
  }
});
