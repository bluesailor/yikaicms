const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

test('role modal explains Blox scopes and keeps dependencies coherent @ci @shard-locale', async ({ page }, testInfo) => {
  const consoleEntries = observeConsole(page);
  await page.goto('/admin/role.php', { waitUntil: 'domcontentloaded' });
  await page.getByRole('button', { name: /添加角色|Add Role|ロールを追加/ }).click();

  const modal = page.getByTestId('role-edit-modal');
  const group = page.getByTestId('role-blox-permissions');
  const checkbox = (permission) => group.locator(`input[data-perm="${permission}"]`);
  await expect(modal).toBeVisible();
  await expect(group).toBeVisible();
  await expect(checkbox('blox_edit')).toBeVisible();
  await expect(checkbox('blox_home')).toBeVisible();
  await expect(checkbox('blox_global')).toBeVisible();
  await expect(checkbox('blox_code')).toBeVisible();

  await checkbox('blox_edit').check();
  await expect(page.locator('input[data-perm="edit_page"]')).toBeChecked();
  await page.locator('input[data-perm="edit_page"]').uncheck();
  await expect(checkbox('blox_edit')).not.toBeChecked();

  await checkbox('blox_code').check();
  await expect(page.getByTestId('role-blox-permission-notice')).toBeVisible();
  await checkbox('blox_home').check();
  await expect(page.getByTestId('role-blox-permission-notice')).toBeHidden();

  const modalFitsViewport = await modal.locator(':scope > div.relative').evaluate((element) => {
    const rect = element.getBoundingClientRect();
    return rect.left >= 0 && rect.right <= window.innerWidth && element.scrollWidth <= element.clientWidth;
  });
  expect(modalFitsViewport).toBe(true);
  expect(consoleEntries, 'role permission interaction must keep the console clean').toEqual([]);

  if (testInfo.project.name === 'desktop-1440') {
    await page.screenshot({ path: testInfo.outputPath('role-blox-permissions.png'), fullPage: true });
  }
});

test('role save rejects a code-only Blox permission set @ci @shard-locale', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one authenticated server-side validation is sufficient');
  await page.goto('/admin/role.php', { waitUntil: 'domcontentloaded' });

  const result = await page.evaluate(async () => {
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const body = new FormData();
    body.set('_token', token);
    body.set('action', 'save');
    body.set('id', '0');
    body.set('name', 'invalid-blox-role');
    body.append('permissions[]', 'blox_code');
    const response = await fetch('/admin/role.php', {
      method: 'POST',
      body,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    return response.json();
  });

  expect(Number(result.code)).not.toBe(0);
  expect(String(result.msg || '')).toMatch(/Blox|代码|Code|コード/);
});
