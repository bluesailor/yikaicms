const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');

function settings(values) {
  return JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [
    'tests/e2e/security-settings-fixture.php', JSON.stringify(values),
  ], { cwd: root, encoding: 'utf8' }));
}

test('sandbox blocks static output and infrastructure pages on GET and POST @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'shared disposable settings');
  const previous = settings({ demo_mode: '0', demo_owner_token: 'owner-fixture-token', cron_token: 'cron-fixture-token' });
  try {
    await page.goto('/admin/setting_demo.php');
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    settings({ demo_mode: '2' });
    for (const name of ['static_html.php', 'setting_seo.php', 'system.php']) {
      for (const response of [
        await page.request.get('/admin/' + name),
        await page.request.post('/admin/' + name, { form: { action: 'save', _token: token } }),
      ]) {
        const denied = await response.json();
        expect(denied.code).toBe(1);
        expect(denied.msg).toContain('演示沙盒');
      }
    }
    const rejected = await page.request.post('/admin/setting_demo.php', {
      form: { action: 'save_mode', demo_mode: '0', owner_token: 'cron-fixture-token', _token: token },
    });
    expect((await rejected.json()).code).not.toBe(0);
    const allowed = await page.request.post('/admin/setting_demo.php', {
      form: { action: 'save_mode', demo_mode: '0', owner_token: 'owner-fixture-token', _token: token },
    });
    expect((await allowed.json()).code).toBe(0);
    settings({ demo_mode: '2', demo_owner_token: '' });
    await page.goto('/admin/setting_demo.php');
    await expect(page.locator('body')).toContainText('demo:owner-token');
    const readBack = settings({ demo_owner_token: '' });
    expect(readBack.demo_owner_token).toBe('');
  } finally {
    settings(previous);
  }
});

test('static base URL rejects cross-origin settings through the real endpoint @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'shared disposable settings');
  await page.goto('/admin/static_html.php');
  const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
  for (const base of ['http://169.254.169.254', 'https://example.invalid', 'file:///etc/passwd']) {
    const response = await page.request.post('/admin/static_html.php', {
      form: { action: 'save', static_html_base_url: base, static_html_enabled: '1', _token: token },
    });
    expect((await response.json()).code).not.toBe(0);
  }
});
