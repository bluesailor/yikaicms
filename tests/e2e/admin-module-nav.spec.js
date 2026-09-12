const { test, expect } = require('./site-diagnostics');

// Navigation tests must not contact an update server or start a real upgrade.
test.beforeEach(async ({ page }) => {
  await page.route('**/admin/upgrade_online.php', async route => {
    const request = route.request();
    if (request.method() !== 'POST') return route.continue();
    const form = await new Request(request.url(), {
      method: 'POST', headers: request.headers(), body: request.postDataBuffer(),
    }).formData();
    const action = String(form.get('action'));
    expect(['precheck', 'check']).toContain(action);
    await route.fulfill({ json: action === 'precheck'
      ? { code: 0, all_ok: true, checks: [] }
      : { code: 0, current_version: '1.19.9', data: { has_update: false } } });
  });
});

function observeUnsafeWrites(page) {
  const writes = [];
  page.on('request', request => {
    if (['GET', 'HEAD', 'OPTIONS'].includes(request.method())) return;
    // The route above validates and stubs these two read-only actions.
    if (new URL(request.url()).pathname === '/admin/upgrade_online.php') return;
    writes.push(`${request.method()} ${new URL(request.url()).pathname}`);
  });
  return writes;
}

async function navigate(page, testId) {
  const link = page.getByTestId(testId);
  const url = await link.getAttribute('href');
  if (await link.isVisible()) await link.click();
  else await page.getByTestId('admin-module-select').selectOption(url);
  await expect(link).toHaveAttribute('aria-current', 'page');
}

async function checkLayout(page) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  const content = await page.getByTestId('admin-module-content').boundingBox();
  if (page.viewportSize().width >= 1280) {
    await expect(page.getByTestId('admin-module-select')).toBeHidden();
    const sidebar = await page.locator('.admin-module-sidebar').boundingBox();
    expect(sidebar.x + sidebar.width).toBeLessThan(content.x);
    expect(sidebar.width).toBe(176);
  } else {
    await expect(page.getByTestId('admin-module-select')).toBeVisible();
    await expect(page.locator('.admin-module-links')).toBeHidden();
  }
}

test('workflow navigation covers cases forms members and administrators @ci', async ({ page }, info) => {
  test.setTimeout(120000);
  const writes = observeUnsafeWrites(page);
  for (const routes of [
    ['case', 'case_category'], ['form', 'form_design'],
    ['member', 'setting_member'], ['user', 'role'],
  ]) {
    const localized = ['case', 'form'].includes(routes[0]);
    await page.goto(`/admin/${routes[0]}.php${localized ? '?lang=en' : ''}`);
    for (const route of [...routes, routes[0]]) {
      await navigate(page, `admin-module-${route}`);
      if (localized) expect(new URL(page.url()).searchParams.get('lang')).toBe('en');
      await checkLayout(page);
    }
    await expect(page.locator('.admin-workflow-table')).toBeVisible();
    await page.screenshot({ path: info.outputPath(`${routes[0]}-module.png`), fullPage: false });
  }
  expect(writes).toEqual([]);
});

test('settings task navigation retains language and save boundaries @ci', async ({ page }, info) => {
  test.setTimeout(180000);
  const writes = observeUnsafeWrites(page);
  for (const [route, tabs] of [
    ['setting', ['basic', 'url', 'pagination', 'header', 'footer', 'code', 'lang']],
    ['setting_contact', ['info', 'form', 'map']],
    ['setting_seo', ['basic', 'social', 'verify', 'sitemap', 'robots']],
    ['setting_email', ['smtp', 'register', 'forgot', 'reset', 'inquiry']],
    ['setting_security', ['login', 'login_logs', 'upload', 'logs']],
  ]) {
    await page.goto(`/admin/${route}.php?lang=en`);
    for (const tab of tabs) {
      // SMTP is global; enter translated templates explicitly when leaving it.
      if (route === 'setting_email' && tab === 'register') {
        await page.goto('/admin/setting_email.php?tab=register&lang=en');
      }
      await navigate(page, `admin-module-tab-${tab}`);
      await checkLayout(page);
      if (!['setting_security', 'setting_email'].includes(route) || (route === 'setting_email' && tab !== 'smtp')) {
        expect(new URL(page.url()).searchParams.get('lang')).toBe('en');
      }
      if (route === 'setting_email') {
        await expect(page.locator('input[name="_save_tab"]')).toHaveValue(tab);
        if (tab === 'smtp') expect(new URL(page.url()).searchParams.has('lang')).toBe(false);
      }
    }
    await page.screenshot({ path: info.outputPath(`${route}-module.png`), fullPage: false });
  }
  expect(writes).toEqual([]);
});

test('maintenance navigation never executes backup import cleanup or upgrade @ci', async ({ page }, info) => {
  test.setTimeout(180000);
  const writes = observeUnsafeWrites(page);
  for (const [route, tabs] of [
    ['database', ['backup', 'export', 'import', 'logs', 'tables']],
    ['system', ['info', 'log', 'errorlog']],
    ['site_health', ['status', 'info']],
    ['upgrade', ['config', 'manual', 'history', 'online']],
  ]) {
    await page.goto(`/admin/${route}.php`);
    for (const tab of tabs) {
      await navigate(page, `admin-module-tab-${tab}`);
      await checkLayout(page);
    }
    await page.screenshot({ path: info.outputPath(`${route}-module.png`), fullPage: false });
  }
  await page.goto('/admin/upgrade.php?tab=check');
  await expect(page.getByTestId('admin-module-tab-check')).toHaveAttribute('aria-current', 'page');
  await checkLayout(page);
  expect(writes).toEqual([]);
});

test('product module navigation keeps language and list controls across five pages @ci', async ({ page }, info) => {
  test.setTimeout(60000);
  const writes = observeUnsafeWrites(page);
  await page.goto('/admin/product.php?lang=en');
  await expect(page.getByTestId('admin-module-product')).toHaveAttribute('aria-current', 'page');
  for (const route of ['product_category', 'product_brand', 'product_tag', 'product_setting', 'product']) {
    await navigate(page, `admin-module-${route}`);
    expect(new URL(page.url()).searchParams.get('lang')).toBe('en');
    await checkLayout(page);
  }
  await expect(page.locator('input[name="keyword"]')).toBeVisible();
  await expect(page.locator('select[name="status"]')).toBeVisible();
  expect((await page.locator('.admin-product-table').boundingBox()).width).toBeGreaterThanOrEqual(1040);
  await expect(page.locator('.admin-product-table th').last()).toHaveCSS('white-space', 'nowrap');
  await page.screenshot({ path: info.outputPath('product-module.png'), fullPage: false });
  if (page.viewportSize().width >= 1280) {
    await page.locator('header button[aria-label]').first().click();
    await expect.poll(async () => (await page.locator('.admin-module-sidebar').boundingBox()).x).toBeLessThan(150);
    await checkLayout(page);
    await page.screenshot({ path: info.outputPath('product-module-collapsed.png'), fullPage: false });
    await page.locator('header button[aria-label]').first().click();
  }
  expect(writes).toEqual([]);
});

test('workflow dialogs remain editable and cancel without saving @ci', async ({ page }, info) => {
  test.setTimeout(90000);
  const writes = observeUnsafeWrites(page);
  for (const [route, opener, field] of [
    ['case_category', 'openEditModal()', '#editName'],
    ['form_design', 'openEditModal()', '#editName'],
    ['member', 'openAddModal()', '#editUsername'],
    ['user', 'openEditModal()', '#editUsername'],
    ['role', 'openEditModal()', '#editName'],
  ]) {
    await page.goto(`/admin/${route}.php`);
    await page.locator(`button[onclick="${opener}"]`).click();
    const modal = page.locator('#editModal');
    await expect(modal).toBeVisible();
    await page.locator(field).fill('Navigation layout check');
    const fits = await modal.locator(':scope > div.bg-white').evaluate(element => {
      const rect = element.getBoundingClientRect();
      return rect.left >= 0 && rect.right <= innerWidth && element.scrollWidth <= element.clientWidth;
    });
    expect(fits, `${route} dialog fits viewport`).toBe(true);
    if (route === 'role') {
      await page.locator('input[data-perm="blox_edit"]').check();
      await expect(page.locator('input[data-perm="edit_page"]')).toBeChecked();
      await page.screenshot({ path: info.outputPath('role-dialog.png'), fullPage: false });
    }
    await modal.locator('button[type="button"][onclick="closeModal()"]').click();
    await expect(modal).toBeHidden();
    await checkLayout(page);
  }
  expect(writes).toEqual([]);
});

test('limited workflow role cannot see or enter administrator-only settings @ci', async ({ page }) => {
  test.setTimeout(90000);
  await page.goto('/admin/role.php');
  const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  const username = `nav_staff_${Date.now()}`;
  const roleForm = new URLSearchParams({ _token: token, action: 'save', name: username });
  for (const permission of ['member', 'form', 'edit_case']) roleForm.append('permissions[]', permission);
  const roleResponse = await page.request.post('/admin/role.php', {
    data: roleForm.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  });
  const role = await roleResponse.json();
  expect(role.code).toBe(0);
  const userResponse = await page.request.post('/admin/user.php', {
    form: { _token: token, action: 'save', username, password: 'Nav@Test123', role_id: String(role.data.id), status: '1' },
  });
  expect((await userResponse.json()).code).toBe(0);
  // Discard the copied cookie, not the shared server session used by other tests.
  await page.context().clearCookies();
  await page.goto('/admin/login.php');
  await page.locator('input[name="username"]').fill(username);
  await page.locator('input[name="password"]').fill('Nav@Test123');
  await Promise.all([
    page.waitForURL(url => !url.pathname.endsWith('/admin/login.php')),
    page.locator('button[type="submit"]').click(),
  ]);
  const writes = observeUnsafeWrites(page);
  await page.goto('/admin/member.php');
  await expect(page.getByTestId('admin-module-member')).toHaveAttribute('aria-current', 'page');
  await expect(page.getByTestId('admin-module-setting_member')).toHaveCount(0);
  await expect(page.getByTestId('admin-module-select').locator('option')).toHaveCount(1);
  for (const [route, other] of [['case', 'case_category'], ['form', 'form_design']]) {
    await page.goto(`/admin/${route}.php`);
    await navigate(page, `admin-module-${other}`);
    await checkLayout(page);
  }
  for (const route of ['setting_member', 'user', 'role', 'setting', 'database', 'upgrade']) {
    const response = await page.request.get(`/admin/${route}.php`);
    expect(await response.text()).toContain('没有操作权限');
  }
  expect(writes).toEqual([]);
});

test('template types remain reachable through desktop and mobile module navigation @ci', async ({ page }, info) => {
  test.setTimeout(60000);
  const writes = observeUnsafeWrites(page);
  await page.goto('/admin/blox_templates.php');
  for (const type of ['section', 'page', 'header', 'footer', 'popup', 'product-detail', 'all']) {
    await navigate(page, `blox-template-filter-${type}`);
    expect(new URL(page.url()).searchParams.get('type') || 'all').toBe(type);
    await checkLayout(page);
  }
  await page.screenshot({ path: info.outputPath('template-module.png'), fullPage: false });
  expect(writes).toEqual([]);
});
