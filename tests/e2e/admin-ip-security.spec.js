const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

test('image pixel limit stays on the upload tab and supports an explicit off switch @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single persistent security-settings check');
  const consoleEntries = observeConsole(page);

  await page.goto('/admin/setting_security.php', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('upload-max-megapixels')).toHaveCount(0);
  await page.goto('/admin/setting_security.php?tab=upload', { waitUntil: 'domcontentloaded' });
  const pixelLimit = page.getByTestId('upload-max-megapixels');
  await expect(pixelLimit).toBeVisible();
  await expect(pixelLimit).toHaveAttribute('min', '0');
  await expect(pixelLimit).toHaveAttribute('max', '200');

  const savePixelLimit = (value) => page.evaluate(async (nextValue) => {
    const response = await fetch('/admin/setting_security.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: new URLSearchParams({ 'settings[upload_max_megapixels]': nextValue }),
    });
    return response.json();
  }, value);

  try {
    expect((await savePixelLimit('0')).code).toBe(0);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('upload-max-megapixels')).toHaveValue('0');

    expect((await savePixelLimit('500')).code).toBe(0);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('upload-max-megapixels')).toHaveValue('200');
  } finally {
    expect((await savePixelLimit('40')).code).toBe(0);
  }

  expect(consoleEntries, 'upload security settings must keep the console clean').toEqual([]);
});

test('trusted proxy and admin whitelist settings reject lockout and ignore spoofed headers @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single persistent security-settings check');
  const consoleEntries = observeConsole(page);

  await page.goto('/admin/setting_security.php', { waitUntil: 'domcontentloaded' });
  const proxies = page.getByTestId('trusted-proxies-input');
  const whitelist = page.getByTestId('admin-ip-whitelist-input');
  await expect(proxies).toBeVisible();
  await expect(whitelist).toBeVisible();

  const saveRulesAsForwardedClient = (trustedProxies, allowedIps, forwardedFor = '203.0.113.99') => page.evaluate(
    async ({ trustedProxies: trusted, allowedIps: allowed, forwardedFor: forwarded }) => {
      const response = await fetch('/admin/setting_security.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          'X-Forwarded-For': forwarded,
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: new URLSearchParams({
          'settings[trusted_proxies]': trusted,
          'settings[admin_ip_whitelist]': allowed,
        }),
      });
      return response.json();
    },
    { trustedProxies, allowedIps, forwardedFor },
  );

  try {
    await proxies.fill('');
    await whitelist.fill('203.0.113.99');
    const rejected = page.waitForResponse((response) => response.url().includes('/admin/setting_security.php')
      && response.request().method() === 'POST');
    await page.getByTestId('security-settings-save').click();
    expect((await (await rejected).json()).code).not.toBe(0);

    await whitelist.fill('127.0.0.1');
    const saved = page.waitForResponse((response) => response.url().includes('/admin/setting_security.php')
      && response.request().method() === 'POST');
    await page.getByTestId('security-settings-save').click();
    expect((await (await saved).json()).code).toBe(0);

    const spoofed = await page.request.get('/admin/setting_security.php', {
      headers: { 'X-Forwarded-For': '203.0.113.99' },
    });
    expect(spoofed.status()).toBe(200);

    const restricted = await saveRulesAsForwardedClient('127.0.0.1', '203.0.113.99');
    expect(restricted.code).toBe(0);
    expect((await page.request.get('/admin/setting_security.php')).status()).toBe(403);
    expect((await page.request.get('/admin/setting_security.php', {
      headers: { 'X-Forwarded-For': '203.0.113.99' },
    })).status()).toBe(200);
  } finally {
    expect((await saveRulesAsForwardedClient('', '')).code).toBe(0);
  }

  expect(consoleEntries, 'security settings must keep the console clean').toEqual([]);
});
