const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { frame, waitPreviewSettled } = require('./helpers');
const fixture = (action, id = '') => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), action, String(id)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

async function command(page, action, button) {
  if (!await page.getByTestId(button).isVisible()) {
    await page.getByTestId('blox-mobile-canvas-view').click();
    await page.getByTestId('blox-mobile-actions-open').click();
    button = button.replace('blox-', 'blox-mobile-');
  }
  const response = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_template_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === action);
  await page.getByTestId(button).click();
  expect((await (await response).json()).code).toBe(0);
}

test('product detail template binds preview and published records independently @ci', async ({ page, browser }, info) => {
  test.setTimeout(90000);
  page.setDefaultTimeout(15000);
  page.on('dialog', dialog => dialog.accept());
  const products = JSON.parse(fixture('products'));
  expect(products).toHaveLength(3);
  const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const front = await visitor.newPage();
  const errors = [];
  front.on('pageerror', error => errors.push(error.message));
  front.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
  let id;
  try {
    await page.goto('/admin/site_design.php');
    await page.getByTestId('site-design-products').click();
    await page.getByTestId('product-design-name').fill(`TB-R2 ${info.project.name}`);
    await page.locator('select[name="language"]').selectOption('zh-CN');
    await page.getByTestId('product-design-create').click();
    await expect(page).toHaveURL(/blox_editor.php\?template=/);
    id = new URL(page.url()).searchParams.get('template');
    const editor = page.url();
    await waitPreviewSettled(page);
    await page.getByTestId('product-template-settings').locator('summary').click();
    for (const product of products) {
      await page.getByTestId('product-template-preview').selectOption(String(product.id));
      await waitPreviewSettled(page);
      await expect((await frame(page)).locator('h1')).toHaveText(product.title);
    }
    const afterPreview = JSON.parse(fixture('products'));
    expect(afterPreview.map(p => p.views)).toEqual(products.map(p => p.views));
    // 应用范围只由完整条件面板编辑（旧的简化范围控件已移除）
    await page.getByTestId('blox-cond-add-include-item').click();
    await page.getByTestId('blox-cond-include-target-0').selectOption(String(products[0].id));
    await command(page, 'save_draft', 'blox-save');
    const stored = JSON.parse(fixture('read', id));
    for (const product of products) expect(stored.draft_data).not.toContain(product.title);
    await page.goto(editor);
    await waitPreviewSettled(page);
    await page.getByTestId('product-template-settings').locator('summary').click();
    await expect(page.getByTestId('blox-cond-include-target-0')).toHaveValues([String(products[0].id)]);
    await command(page, 'publish', 'blox-publish-template');
    await front.goto(products[0].url);
    await expect(front.locator('.yk-blox-product-detail h1')).toHaveText(products[0].title);
    await front.goto(products[1].url);
    await expect(front.locator('.yk-blox-product-detail')).toHaveCount(0);
    await page.getByTestId('blox-cond-include-kind-0').selectOption('all');
    await command(page, 'save_draft', 'blox-save');
    await front.reload();
    await expect(front.locator('.yk-blox-product-detail')).toHaveCount(0);
    await command(page, 'publish', 'blox-publish-template');
    for (const product of products) {
      await front.goto(product.url);
      await expect(front.locator('.yk-blox-product-detail h1')).toHaveText(product.title);
      await expect(front.locator('#ik-adminbar, #ik-draft-previewbar')).toHaveCount(0);
      if (product.cover) await expect(front.locator('.yk-blox-product-detail img').first()).toBeVisible();
    }
    await front.screenshot({ path: info.outputPath('product-template.png'), fullPage: true });
    await page.goto('/admin/product_design.php');
    await page.getByTestId(`product-design-row-${id}`).getByTestId('product-design-unpublish').click();
    await front.reload();
    await expect(front.locator('.yk-blox-product-detail')).toHaveCount(0);
    await expect(front.locator('#inquiryForm')).toBeVisible();
    expect(JSON.parse(fixture('read', id)).draft_data).toBeTruthy();
    expect(errors).toEqual([]);
  } finally {
    try { await visitor.close(); } finally { if (id) fixture('restore', id); }
  }
});

test('theme default preview and restore preserve the published design and scope @ci', async ({ page, browser }, info) => {
  test.setTimeout(60000);
  const products = JSON.parse(fixture('products'));
  const ids = JSON.parse(fixture('pair'));
  const before = JSON.parse(fixture('read', ids.selected));
  const visitor = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const front = await visitor.newPage();
  const frontErrors = [];
  front.on('pageerror', error => frontErrors.push(error.message));
  let native;
  try {
    await page.goto('/admin/product_design.php');
    await page.getByTestId('product-native-record').selectOption(String(products[0].id));
    const opened = page.waitForEvent('popup');
    await page.getByTestId('product-native-open').click();
    native = await opened;
    await expect(native.getByTestId('product-native-preview')).toBeVisible();
    // 2026-09-22 询盘表单改为按表单字段渲染（提交按钮不再有 #inquiryBtn）；预览时字段含提交按钮都在 disabled fieldset 里
    await expect(native.locator('#inquiryForm')).toHaveAttribute('data-yk-preview', '1');
    await expect(native.locator('#inquiryForm button[type="submit"]')).toBeDisabled();
    await expect(native.locator('.yk-blox-product-detail')).toHaveCount(0);
    await expect(native.locator('h1').first()).toHaveText(products[0].title);
    expect(JSON.parse(fixture('products')).map(p => p.views)).toEqual(products.map(p => p.views));
    const unauthorized = await visitor.request.get(new URL(native.url()).pathname + new URL(native.url()).search, { maxRedirects: 0 });
    expect(unauthorized.status()).toBe(302);
    expect(unauthorized.headers().location).toContain('login.php');
    for (const product of JSON.parse(fixture('preview-products'))) {
      const response = await native.goto(`/admin/product_native_preview.php?id=${product.id}`);
      expect(response.headers()['cache-control']).toContain('no-store');
      expect(response.headers()['x-robots-tag']).toContain('noindex');
      await expect(native.locator('h1').first()).toHaveText(product.title);
      await expect(native.locator('#inquiryForm button[type="submit"]')).toHaveText(product.submit);
      await expect(native.locator('#inquiryForm')).toHaveAttribute('data-yk-preview', '1');
      await expect(native.locator('#inquiryForm button[type="submit"]')).toBeDisabled();
    }
    await native.close();
    native = null;
    await front.goto(products[0].url);
    await expect(front.locator('.yk-blox-product-detail')).toHaveAttribute('data-template-id', String(ids.selected));

    let row = page.getByTestId(`product-design-row-${ids.selected}`);
    await row.getByTestId('product-design-source-select').selectOption('native');
    await row.getByTestId('product-design-source-apply').click();
    await expect(page.getByRole('status')).toBeVisible();
    row = page.getByTestId(`product-design-row-${ids.selected}`);
    await expect(row.getByTestId('product-design-source-select')).toHaveValue('native');
    await front.reload();
    await expect(front.locator('.yk-blox-product-detail')).toHaveCount(0);
    await expect(front.locator('#inquiryForm button[type="submit"]')).toBeEnabled();
    await front.goto(products[1].url);
    await expect(front.locator('.yk-blox-product-detail')).toHaveAttribute('data-template-id', String(ids.global));
    const restored = JSON.parse(fixture('read', ids.selected));
    // 切到 native 后草稿也会带上 settings.product_template.source（让过期的编辑器版本保存失败），其余必须原样。
    const withoutSource = (json) => { const doc = JSON.parse(json); delete doc.settings.product_template.source; return doc; };
    expect(JSON.parse(restored.draft_data).settings.product_template.source).toBe('native');
    expect(withoutSource(restored.draft_data)).toEqual(withoutSource(before.draft_data));
    expect(JSON.parse(restored.published_data).sections).toEqual(JSON.parse(before.published_data).sections);
    await page.screenshot({ path: info.outputPath('product-native-manager.png'), fullPage: true });

    await row.getByTestId('product-design-source-select').selectOption('custom');
    await row.getByTestId('product-design-source-apply').click();
    await front.goto(products[0].url);
    await expect(front.locator('.yk-blox-product-detail')).toHaveAttribute('data-template-id', String(ids.selected));
    await expect(front.locator('.yk-blox-product-detail h1')).toHaveText(products[0].title);
    expect(JSON.parse(fixture('read', ids.selected)).draft_data).toBe(before.draft_data);
    await page.getByTestId(`product-design-row-${ids.selected}`).getByTestId('product-design-unpublish').click();
    await front.reload();
    await expect(front.locator('.yk-blox-product-detail')).toHaveAttribute('data-template-id', String(ids.global));
    expect(frontErrors).toEqual([]);
  } finally {
    if (native) await native.close();
    await visitor.close();
    fixture('restore', ids.selected);
    fixture('restore', ids.global);
  }
});
