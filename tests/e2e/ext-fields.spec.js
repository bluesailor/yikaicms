const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'ext-fields-fixture.php'), ...args], { cwd: root, encoding: 'utf8' });

// 高级字段（2.0.4）：产品编辑页填写扩展字段——条件显隐、必填、重复器增删排序、关联搜索、链接 / 颜色 / 文件；
// 全站选项页、产品分类弹窗；字段定义页的专业版锁（测试站无注册码）。
test('custom fields are filled in on the product page, site options and category dialog @ci', async ({ page }) => {
  test.setTimeout(120000);
  const ids = JSON.parse(fixture('setup'));
  const saveProduct = async () => {
    const response = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/product_edit.php');
    await page.locator('#editForm button[type=submit]').first().click();
    return (await (await response).json());
  };
  try {
    await page.goto(`/admin/product_edit.php?id=${ids.product}`);
    const box = page.locator('[data-ef-form][data-ef-owner="product"]');
    await expect(box).toBeVisible();
    const size = box.locator('[data-ef-field="e2e_size"]');
    await expect(size).toBeHidden();                                         // 条件：选「定制」才显示
    await expect(box.locator('[data-ef-field="e2e_hidden_cat"]')).toBeHidden(); // 挂载在别的分类

    await box.locator('select[name="ext_fields[e2e_kind]"]').selectOption('custom');
    await expect(size).toBeVisible();
    // 必填：可见时浏览器校验拦下，服务端也会拦
    await size.locator('input').fill('');
    await box.locator('select[name="ext_fields[e2e_kind]"]').selectOption('std');
    await expect(size).toBeHidden();
    await box.locator('select[name="ext_fields[e2e_kind]"]').selectOption('custom');
    await size.locator('input').fill('XL-800');

    await box.locator('input[name="ext_fields[e2e_buy][url]"]').fill('https://shop.example.com/se7');
    await box.locator('input[name="ext_fields[e2e_buy][title]"]').fill('Buy now');
    await box.locator('input[name="ext_fields[e2e_buy][target]"]').check();
    await box.locator('[data-ef-field="e2e_tint"] input[type=text]').fill('#1E40AF');
    await box.locator('[data-ef-field="e2e_sheet"] input[type=text]').fill('/uploads/files/se7.pdf');

    // 重复器：加三行、删中间一行、把最后一行上移
    const repeater = box.locator('[data-ef-field="e2e_specs"]');
    for (const [model, load] of [['A1', '10'], ['B2', '20'], ['C3', '30']]) {
      await repeater.locator('[data-ef-row-add]').click();
      const row = repeater.locator('[data-ef-row]').last();
      await row.locator('input[name$="[model]"]').fill(model);
      await row.locator('input[name$="[load]"]').fill(load);
    }
    await repeater.locator('[data-ef-row]').nth(1).locator('[data-ef-row-remove]').click();
    await repeater.locator('[data-ef-row]').nth(1).locator('[data-ef-row-up]').click();
    await expect(repeater.locator('[data-ef-row-no]').nth(1)).toContainText('2');

    // 关联：搜索并选中另一个产品
    const rel = box.locator('[data-ef-field="e2e_related"]');
    await rel.locator('[data-ef-rel-search]').fill(String(ids.related));
    await rel.locator(`[data-ef-rel-pick="${ids.related}"]`).click();
    await expect(rel.locator(`[data-ef-rel-item="${ids.related}"]`)).toBeVisible();

    expect((await saveProduct()).code).toBe(0);
    let saved = JSON.parse(fixture('inspect', String(ids.product)));
    expect(saved.product.e2e_kind).toBe('custom');
    expect(saved.product.e2e_size).toBe('XL-800');
    expect(JSON.parse(saved.product.e2e_buy)).toEqual({ url: 'https://shop.example.com/se7', title: 'Buy now', target: '_blank' });
    expect(saved.product.e2e_tint).toBe('#1e40af');
    expect(saved.product.e2e_sheet).toBe('/uploads/files/se7.pdf');
    // A1 B2 C3 → 删 B2 → C3 上移
    expect(JSON.parse(saved.product.e2e_specs)).toEqual([{ model: 'C3', load: '30' }, { model: 'A1', load: '10' }]);
    expect(saved.product.e2e_related).toBe(String(ids.related));

    // 重新打开：值都回填，重复器两行
    await page.goto(`/admin/product_edit.php?id=${ids.product}`);
    await expect(box.locator('[data-ef-field="e2e_size"] input')).toHaveValue('XL-800');
    await expect(box.locator('[data-ef-field="e2e_specs"] [data-ef-row]')).toHaveCount(2);
    await expect(box.locator(`[data-ef-rel-item="${ids.related}"]`)).toContainText(ids.related_title);

    // 全站选项
    await page.goto('/admin/site_fields.php');
    await page.locator('input[name="ext_fields[e2e_factory]"]').fill('20,000 m²');
    const siteSaved = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/site_fields.php');
    await page.locator('#siteFieldsForm button[type=submit]').click();
    expect((await (await siteSaved).json()).code).toBe(0);

    // 产品分类弹窗：打开时取字段区
    await page.goto('/admin/product_category.php');
    await page.getByTestId('product-category-edit').first().click();
    const banner = page.locator('#efFields input[name="ext_fields[e2e_banner]"]');
    await expect(banner).toBeVisible();
    await banner.fill('Solar drives');
    const catSaved = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/product_category.php');
    await page.locator('#editForm button[type=submit]').click();
    expect((await (await catSaved).json()).code).toBe(0);

    saved = JSON.parse(fixture('inspect', String(ids.product)));
    expect(saved.site.e2e_factory).toBe('20,000 m²');

    // 字段定义页（测试站带构建器授权）：建一个重复器，子字段编辑器 + 子字段为空时拒绝保存
    await page.goto('/admin/extfield.php?owner_type=product');
    await expect(page.locator('table')).toContainText('e2e_specs');
    await page.getByTestId('ef-add').click();
    const modal = page.locator('form:has(input[name="field_key"])');
    await modal.locator('input[name="field_key"]').fill('e2e_models');
    await modal.locator('input[name="field_name"]').fill('Models');
    await modal.locator('select[name="field_type"]').selectOption('repeater');
    const defSave = () => page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/extfield.php');
    let saving = defSave();
    await modal.locator('button[type=submit]').click();
    expect((await (await saving).json()).code).not.toBe(0);
    await page.getByTestId('ef-add-sub').click();
    await modal.getByTestId('ef-sub-key').last().fill('model');
    await modal.getByTestId('ef-sub-name').last().fill('Model');
    saving = defSave();
    await modal.locator('button[type=submit]').click();
    expect((await (await saving).json()).code).toBe(0);
    await page.waitForLoadState('load');
    await expect(page.locator('table')).toContainText('e2e_models');
  } finally {
    fixture('restore');
  }
});
