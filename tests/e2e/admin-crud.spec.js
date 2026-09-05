const { test: base, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const root = path.resolve(__dirname, '../..');
function logs() {
  const dir = path.join(root, 'storage/logs');
  return Object.fromEntries((fs.existsSync(dir) ? fs.readdirSync(dir) : [])
    .filter(name => /^error-\d{6}\.log$/.test(name))
    .map(name => [name, fs.readFileSync(path.join(dir, name), 'utf8')]));
}
const test = base.extend({
  diagnostics: [async ({ page, baseURL }, use, info) => {
    expect(new URL(baseURL).hostname).toBe('127.0.0.1');
    expect(path.basename(root)).toMatch(/^yikai-e2e-/);
    expect(fs.existsSync(path.join(root, 'storage/.smoke-state-backup/manifest.json'))).toBeTruthy();
    const before = logs(), errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    page.on('response', response => {
      if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`);
    });
    await use();
    const after = logs();
    for (const name of Object.keys(before)) expect(after).toHaveProperty(name);
    const added = Object.entries(after).map(([name, value]) => {
      expect(value.startsWith(before[name] || ''), 'Error log must not be cleared during test').toBeTruthy();
      return value.slice((before[name] || '').length);
    }).join('');
    await info.attach('new-php-errors', { body: added || '(none)', contentType: 'text/plain' });
    expect(added, 'No new CMS error log entries').toBe('');
    expect(errors, 'Browser and server errors').toEqual([]);
  }, { auto: true }],
});

async function save(page, kind) {
  const responsePromise = page.waitForResponse(r => r.request().method() === 'POST'
    && new URL(r.url()).pathname === `/admin/${kind}_edit.php`);
  await page.locator('#editForm button[type="submit"]').click();
  const response = await responsePromise;
  expect(response.status()).toBe(200);
  expect(response.headers()['content-type']).toContain('application/json');
  const result = await response.json();
  expect(result.code).toBe(0);
  await page.waitForURL(url => url.pathname === `/admin/${kind}.php`);
  return result.data.id;
}

for (const kind of ['article', 'product']) {
  test(`${kind}: browser create, edit, cancel delete and trash @ci @admin-crud`, async ({ page }) => {
    const title = `E2E中文${Date.now()}`;
    await page.goto(`/admin/${kind}_edit.php`);
    await page.locator('[name="title"]').fill(title);
    await page.locator('[name="summary"]').fill('E2E summary < & " test');
    const category = page.locator('#categoryTree input[type="checkbox"]').last();
    await category.check();
    const categoryId = await page.locator('#categoryIdInput').inputValue();
    expect(Number(categoryId)).toBeGreaterThan(0);
    const bodyEditor = kind === 'article'
      ? page.frameLocator('#contentEditor_ifr').locator('body')
      : page.frameLocator('#editor-container_ta_ifr').locator('body');
    await bodyEditor.fill('E2E body 中文 content');
    if (kind === 'product') await page.locator('[name="model"]').fill('E2E-100');
    const id = await save(page, kind);
    expect(Number(id)).toBeGreaterThan(0);
    const createdRow = page.locator('tr').filter({ has: page.locator(`input[name="ids[]"][value="${id}"]`) });
    await expect(createdRow).toContainText(title);
    await createdRow.hover();
    const link = createdRow.locator(`a[href*="${kind}_edit.php?id=${id}"]`).first();
    await expect(link).toBeVisible();
    await link.click();
    await expect(page.locator('[name="title"]')).toHaveValue(title);
    await expect(page.locator('[name="summary"]')).toHaveValue('E2E summary < & " test');
    await expect(page.locator('#categoryIdInput')).toHaveValue(categoryId);
    await expect(bodyEditor).toContainText('E2E body 中文 content');
    await bodyEditor.fill('E2E body updated');
    if (kind === 'product') await expect(page.locator('[name="model"]')).toHaveValue('E2E-100');
    await page.locator('[name="title"]').fill(title + ' updated');
    await save(page, kind);
    await page.goto(`/admin/${kind}_edit.php?id=${id}`);
    await expect(page.locator('[name="title"]')).toHaveValue(title + ' updated');
    await expect(bodyEditor).toContainText('E2E body updated');
    await page.goto(`/admin/${kind}.php`);
    const row = page.locator('tr').filter({ has: page.locator(`a[href*="${kind}_edit.php?id=${id}"]`) });
    const deleteButton = row.locator(`button[onclick^="${kind === 'article' ? 'deleteItem' : 'deleteProduct'}("]`);
    await row.hover();
    page.once('dialog', dialog => dialog.dismiss());
    await deleteButton.click();
    await expect(row).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await deleteButton.click();
    await expect(row).toHaveCount(0);
    await page.reload();
    await expect(page.locator(`a[href*="${kind}_edit.php?id=${id}"]`)).toHaveCount(0);
    await page.goto(`/admin/recycle.php?type=${kind === 'article' ? 'content' : 'product'}`);
    await expect(page.locator('body')).toContainText(title + ' updated');
  });
}

test('product legacy structured specs render and edit through real inputs @ci @admin-crud', async ({ page }) => {
  const id = execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'admin-spec-fixture.php')], { cwd: root, encoding: 'utf8' }).trim();
  expect(id).toMatch(/^\d+$/);
  await page.goto(`/admin/product_edit.php?id=${id}`);
  await expect(page.locator('#specsList input[data-key="plain"]')).toHaveValue('100mm');
  await expect(page.locator('#specsList input[data-key="nested"]')).toHaveValue('200mm');
  await expect(page.locator('#specsList input[data-key="options"]')).not.toHaveValue('[object Object]');
  const optionsBefore = await page.locator('#specsList input[data-key="options"]').inputValue();
  expect(optionsBefore).toContain('黑');
  const value = page.locator('#specsList input[data-key="nested"]');
  await value.fill('300mm');
  await value.press('Tab');
  await save(page, 'product');
  await page.goto(`/admin/product_edit.php?id=${id}`);
  await expect(value).toHaveValue('300mm');
  await expect(page.locator('#specsList input[data-key="options"]')).toHaveValue(optionsBefore);
  await expect(page.locator('body')).not.toContainText('Array to string conversion');
});

for (const query of ['yk_route=product_list&cat=smart-device', 'yk_route=list&slug=news', 'yk_route=list&slug=cases', 'yk_route=download_list']) {
  test(`catalog form preserves ${query} after search and reload @ci @admin-crud`, async ({ page }) => {
    const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'dynamic-url-fixture.php'), action], { cwd: root });
    fixture('seed');
    try {
      await page.goto('/index.php?' + query);
      const form = page.locator('form').filter({ has: page.locator('input[name="keyword"]') }).first();
      const input = form.locator('input[name="keyword"]');
      for (const keyword of ['智能', 'E2E-no-matches-987654321', '']) {
        await input.fill(keyword);
        await Promise.all([
          page.waitForNavigation({ waitUntil: 'load' }),
          form.locator('button[type="submit"]').click(),
        ]);
        for (const [key, value] of new URLSearchParams(query)) {
          expect(new URL(page.url()).searchParams.get(key)).toBe(value);
        }
        await expect(input).toHaveValue(keyword);
        await page.reload();
        await expect(input).toHaveValue(keyword);
      }
    } finally {
      fixture('cleanup');
    }
  });
}
