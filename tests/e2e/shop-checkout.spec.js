const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');

const fixture = (action) => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'shop-fixture.php'), action],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

// 商城交易闭环任务剧本：原生购买入口 → Blox 购买组件 → 游客加购/下单 →
// 商家收款/发货/完成 → 游客查单 → 停用插件后产品页仍正常。
test('shop checkout loop: native and Blox purchase, fulfillment, lookup, plugin-off fallback', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop shop baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());

  const product = JSON.parse(fixture('products'));
  expect(product.id).toBeTruthy();
  fixture('enable');   // 一次性站插件表为空：先启用，UI 配置销售前页面才可进

  // 启用后必须注册后台侧栏入口；商品管理继续复用核心列表，并提供商城常用快捷操作。
  await page.goto('/admin/plugin_page.php?plugin=shop');
  await expect(page.locator('a[href="/admin/plugin_page.php?plugin=shop"]').first()).toBeVisible();
  await expect(page.locator('a[href="/admin/plugin_page.php?plugin=shop&view=orders"]').first()).toBeVisible();
  await expect(page.getByTestId('shop-integration-links')).toBeVisible();
  await expect(page.getByTestId('shop-member-mode')).toBeVisible();

  await page.goto('/admin/role.php');
  await page.getByRole('button', { name: /添加角色/ }).click();
  await expect(page.getByTestId('role-plugin-presets')).toBeVisible();
  await expect(page.getByTestId('role-plugin-permissions')).toContainText('商城管理');
  await expect(page.getByTestId('role-plugin-permissions')).toContainText('订单处理');
  await page.getByTestId('role-plugin-presets').getByRole('button', { name: /订单处理员/ }).click();
  await expect(page.locator('#editName')).toHaveValue('订单处理员');
  await expect(page.locator('.perm-checkbox[data-perm="shop_orders"]')).toBeChecked();
  await expect(page.locator('.perm-checkbox[data-perm="shop_manage"]')).not.toBeChecked();
  await page.getByTestId('role-edit-modal').getByRole('button', { name: '取消' }).click();

  await page.goto('/admin/product.php');
  const productRow = page.locator(`tr:has(input[name="ids[]"][value="${product.id}"])`);
  await expect(productRow).toBeVisible();
  await productRow.hover();
  const rowActions = productRow.locator('.row-actions');
  await expect(rowActions.locator(`a[href="/admin/product_edit.php?id=${product.id}"]`)).toBeVisible();
  await expect(rowActions.locator(`button[onclick="duplicateItem(${product.id})"]`)).toBeVisible();
  await expect(rowActions.locator('a[target="_blank"]')).toBeVisible();
  await expect(rowActions.locator(`[data-row-status-action="${product.id}"]`)).toBeVisible();
  await expect(rowActions.locator(`button[onclick="deleteProduct(${product.id})"]`)).toBeVisible();
  await expect(rowActions.locator('a[href^="/admin/product_category.php"]')).toBeVisible();

  // M2-a 安全默认值：没有网关验签适配器时，即使正文自称成功也必须拒绝。
  const unverifiedNotify = await page.request.post('/shop/payment-notify/unconfigured', {
    data: '{"status":"succeeded"}',
    headers: { 'content-type': 'application/json' },
  });
  expect(unverifiedNotify.status()).toBe(401);
  expect(await unverifiedNotify.text()).toBe('fail');

  // 商家配置销售（后台 UI 真实点击）。storageState 已带 admin 登录态（global-setup），
  // 无需在此登录——goto(login.php) 会被重定向到 /admin/。
  await page.goto('/admin/plugin_page.php?plugin=shop');
  // 先提交运费设置（独立表单 PRG，单独提交不丢行编辑）
  await page.getByTestId('shop-shipping-fee').fill('10');
  await page.getByTestId('shop-shipping-threshold').fill('100');
  await page.getByTestId('shop-shipping-excluded-regions').fill('海南省/三沙市');
  await page.getByTestId('shop-shipping-settings').locator('button[type="submit"]').click();
  await expect(page.getByTestId('shop-saved-tip')).toBeVisible();
  // 再提交行销售设置
  await page.getByTestId(`shop-price-${product.id}`).fill('19.9');
  await page.getByTestId(`shop-stock-${product.id}`).fill('5');
  await page.getByTestId(`shop-status-${product.id}`).check();
  await page.getByTestId(`shop-row-${product.id}`).locator('button[type="submit"]').click();
  await expect(page.getByTestId('shop-saved-tip')).toBeVisible();

  // 游客：产品页加购 → 购物车 → 结算
  const guest = await page.context().browser().newContext();
  const visitor = await guest.newPage();
  visitor.setDefaultTimeout(15000);
  await visitor.goto(`/product/${product.id}.html`);
  await expect(visitor.getByTestId('shop-buy-form')).toBeVisible();

  // 发布一个只命中当前产品、包含插件节点的 Blox 商品详情模板。重新打开后应由
  // shop/purchase 接管购买入口，同时价格/库存仍来自刚才的真实销售配置。
  const bloxTemplate = JSON.parse(fixture('ensure-blox-template'));
  expect(bloxTemplate.id).toBeTruthy();
  await visitor.reload();
  await expect(visitor.locator('.yk-blox-product-detail .yk-shop-purchase')).toBeVisible();
  await expect(visitor.getByTestId('shop-buy-price')).toContainText('19.90');
  await visitor.getByTestId('shop-buy-qty').fill('2');
  await visitor.getByTestId('shop-buy-submit').click();
  await expect(visitor).toHaveURL(/\/shop\/cart/);
  await expect(visitor.getByTestId('shop-cart-total')).toContainText('39.80');

  await visitor.goto('/shop/checkout');
  await expect(visitor.getByTestId('shop-checkout-total')).toContainText('49.80');

  // 服务区门禁：三沙市被后台配置为不配送，伪造/直接提交同样不能创建订单。
  await visitor.getByTestId('shop-checkout-name').fill('E2E 买家');
  await visitor.getByTestId('shop-checkout-phone').fill('13812345678');
  await visitor.getByTestId('shop-checkout-province').selectOption('海南省');
  await visitor.getByTestId('shop-checkout-city').fill('三沙市');
  await visitor.getByTestId('shop-checkout-district').fill('西沙区');
  await visitor.getByTestId('shop-checkout-address').fill('测试路 1 号');
  await visitor.getByTestId('shop-checkout-submit').click();
  await expect(visitor).toHaveURL(/\/shop\/checkout\?err=/);
  await expect(visitor.getByTestId('shop-checkout-error')).toContainText('不在配送服务范围');

  // 换成服务区内的结构化大陆地址再下单。
  await visitor.getByTestId('shop-checkout-name').fill('E2E 买家');
  await visitor.getByTestId('shop-checkout-phone').fill('13812345678');
  await visitor.getByTestId('shop-checkout-province').selectOption('上海市');
  await visitor.getByTestId('shop-checkout-city').fill('上海市');
  await visitor.getByTestId('shop-checkout-district').fill('浦东新区');
  await visitor.getByTestId('shop-checkout-address').fill('测试路 1 号');
  await visitor.getByTestId('shop-checkout-submit').click();
  await expect(visitor).toHaveURL(/\/shop\/order\?no=/);
  await expect(visitor.getByTestId('shop-order-status')).toContainText('待付款');
  await expect(visitor.getByTestId('shop-order-contact')).toContainText('138****5678');
  const orderNo = new URL(visitor.url()).searchParams.get('no');

  // 落库独立核对：1 单、库存 5-2=3
  let state = JSON.parse(fixture('read'));
  expect(state.orders).toBe(1);
  expect(state.stock).toBe(3);

  // 商家：收款 → 发货 → 完成
  await page.goto('/admin/plugin_page.php?plugin=shop&view=orders');
  await expect(page.getByTestId(`shop-order-row-1`)).toContainText(orderNo);
  await page.goto('/admin/plugin_page.php?plugin=shop&view=orders&detail=1');
  await page.getByTestId('shop-order-paid').click();
  await expect(page.getByTestId('shop-order-status')).toContainText('待发货');
  await page.getByTestId('shop-order-tracking-company').selectOption('顺丰速运');
  await page.getByTestId('shop-order-tracking-no').fill('SF1234567890');
  await page.getByTestId('shop-order-ship').click();
  await expect(page.getByTestId('shop-order-status')).toContainText('已发货');
  await page.getByTestId('shop-order-complete').click();
  await expect(page.getByTestId('shop-order-status')).toContainText('已完成');

  // 游客凭单号 + 手机尾号查询（新会话，验证不依赖下单会话）
  const lookup = await guest.newPage();
  lookup.setDefaultTimeout(15000);
  await lookup.goto('/shop/order');
  await lookup.getByTestId('shop-order-no-input').fill(orderNo);
  await lookup.getByTestId('shop-order-phone-input').fill('5678');
  await lookup.locator('button[type="submit"]').click();
  await expect(lookup.getByTestId('shop-order-status')).toContainText('已完成');
  await expect(lookup.getByTestId('shop-order-tracking')).toContainText('顺丰速运 SF1234567890');
  await expect(lookup.getByTestId('shop-order-address')).toContainText('上海市 浦东新区 测试路 1 号');

  // 停用插件：产品页仍正常展示（无购买表单），CLI 仍可导出订单
  fixture('disable');
  const productAfter = await guest.newPage();
  await productAfter.goto(`/product/${product.id}.html`);
  await expect(productAfter.getByTestId('shop-buy-form')).toHaveCount(0);
  expect(await productAfter.title()).toContain(product.title);
  const exported = execFileSync(process.env.PHP_BINARY || 'php',
    [path.resolve(__dirname, '../..', 'bin/yikai.php'), 'shop:orders', '--out=' + path.join(path.resolve(__dirname, '../..'), 'storage', 'e2e-shop-orders.csv')],
    { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
  expect(exported).toContain('1 条订单');

  // 恢复插件启用状态 + 清理（其余由 fixture reset 处理）
  fixture('enable');
  await guest.close();
  fixture('reset');
});
