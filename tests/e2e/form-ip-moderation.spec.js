const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'form-ip-fixture.php'), action], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

test('message details block IP with optional deletion and allow unblocking after cleanup @ci', async ({ page, context, browser, baseURL }, info) => {
  test.setTimeout(90000);
  const ids = JSON.parse(fixture('setup'));
  const state = () => JSON.parse(fixture('result'));
  const open = async () => {
    await page.goto(`/admin/form.php?view=${ids[0]}`);
    await page.locator('#blockIpButton').click();
    await expect(page.getByTestId('form-ip-confirm')).toBeVisible();
    await expect(page.locator('#ipBlockSummary')).toContainText('127.0.0.1');
    await expect(page.locator('#ipDeleteMessages')).not.toBeChecked();
  };
  const apply = async () => {
    const result = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/form.php');
    await page.locator('#ipBlockApply').click();
    expect((await (await result).json()).code).toBe(0);
    await page.waitForEvent('load');
  };
  const unblock = async () => {
    const list = page.getByTestId('form-ip-blocklist');
    await list.locator('summary').click();
    await expect(list).toContainText('127.0.0.1');
    page.once('dialog', dialog => dialog.accept());
    const result = page.waitForResponse(r => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/form.php');
    await list.getByRole('button').click();
    expect((await (await result).json()).code).toBe(0);
    await page.waitForEvent('load');
  };
  try {
    await open();
    await info.attach('block-confirmation', { body: await page.getByRole('dialog').screenshot(), contentType: 'image/png' });
    const close = page.getByRole('dialog').getByRole('button', { name: '关闭', exact: true });
    const target = await close.boundingBox();
    expect(target.width).toBeGreaterThanOrEqual(44);
    expect(target.height).toBeGreaterThanOrEqual(44);
    await close.click();
    await expect(page.getByRole('dialog')).not.toBeVisible();
    await open();
    await page.getByTestId('form-ip-confirm').getByRole('button').last().click();
    expect(state().blocked).toBe(false); expect(state().rows).toHaveLength(3);
    const noToken = await context.request.post('/admin/form.php', { form: { action: 'block_ip', id: String(ids[0]) }, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    expect(noToken.status()).toBe(403);
    const anonymous = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
      const denied = await anonymous.request.post('/admin/form.php', { form: { action: 'block_ip', id: String(ids[0]) }, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      expect(denied.status()).toBe(401);
    } finally { await anonymous.close(); }
    await page.locator('#blockIpButton').click();
    await expect(page.getByTestId('form-ip-confirm')).toBeVisible();
    await apply();
    expect(state().blocked).toBe(true); expect(state().rows).toHaveLength(3);
    const rejected = await context.request.post('/form_submit.php', { form: { form_slug: 'product-inquiry' }, headers: { 'X-Forwarded-For': '192.0.2.20' } });
    expect(rejected.status()).toBe(403);
    expect((await context.request.get('/admin/')).status()).toBe(200);
    expect((await context.request.get('/')).status()).toBe(200);
    await unblock();
    expect(state().blocked).toBe(false);
    expect((await context.request.post('/form_submit.php', { form: { form_slug: 'contact' } })).status()).not.toBe(403);
    await open();
    await page.locator('#ipDeleteMessages').check();
    await apply();
    expect(state().blocked).toBe(true);
    expect(state().rows.map(row => Number(row.id))).toEqual([ids[2]]);
    await unblock();
    expect(state().blocked).toBe(false);
  } finally { fixture('restore'); }
});
