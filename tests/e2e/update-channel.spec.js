const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

function multipartFields(request) {
  const contentType = request.headers()['content-type'] || '';
  const boundary = /boundary=([^;]+)/i.exec(contentType)?.[1];
  if (!boundary) return {};
  const fields = {};
  for (const part of (request.postData() || '').split(`--${boundary}`)) {
    const separator = part.indexOf('\r\n\r\n');
    if (separator < 0) continue;
    const headers = part.slice(0, separator);
    const name = /name="([^"]+)"/i.exec(headers)?.[1];
    if (name) fields[name] = part.slice(separator + 4).replace(/\r\n$/, '');
  }
  return fields;
}

test('beta update subscription persists and can return to stable @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one persistent settings check is sufficient');
  const consoleEntries = observeConsole(page);

  await page.goto('/admin/upgrade.php?tab=config', { waitUntil: 'domcontentloaded' });
  const toggle = page.getByTestId('update-beta-toggle');
  const control = page.getByTestId('update-beta-control');
  await expect(toggle).toBeVisible();

  try {
    if (await toggle.isChecked()) {
      await control.click();
      await expect(toggle).toBeEnabled();
    }
    await control.click();
    await expect(toggle).toBeChecked();
    await expect(toggle).toBeEnabled();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('update-beta-toggle')).toBeChecked();

    await page.getByTestId('update-beta-control').click();
    await expect(page.getByTestId('update-beta-toggle')).not.toBeChecked();
    await expect(page.getByTestId('update-beta-toggle')).toBeEnabled();
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('update-beta-toggle')).not.toBeChecked();
  } finally {
    if (!page.isClosed()) {
      await page.goto('/admin/upgrade.php?tab=config', { waitUntil: 'domcontentloaded' });
      const current = page.getByTestId('update-beta-toggle');
      if (await current.isChecked()) {
        await page.getByTestId('update-beta-control').click();
        await expect(current).toBeEnabled();
      }
    }
  }

  expect(consoleEntries, 'update channel settings must keep the console clean').toEqual([]);
});

test('online upgrade forwards the selected delta signature @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one upgrade request contract check is sufficient');
  const consoleEntries = observeConsole(page);
  let submittedSignature = null;

  await page.route('**/admin/upgrade_online.php', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue();
      return;
    }
    const body = multipartFields(route.request());
    const action = body.action;
    if (action === 'precheck') {
      await route.fulfill({ json: { code: 0, all_ok: true, checks: [] } });
      return;
    }
    if (action === 'check') {
      await route.fulfill({ json: {
        code: 0,
        current_version: '1.18.1',
        data: {
          has_update: true,
          latest_version: '1.18.2',
          download_url: 'https://update.yikaicms.com/packages/yikaicms-v1.18.2.zip',
          hash: `sha256:${'a'.repeat(64)}`,
          sig: 'full-signature',
          delta: {
            from: '1.18.1',
            download_url: 'https://update.yikaicms.com/packages/delta-1.18.1-to-1.18.2.zip',
            hash: `sha256:${'b'.repeat(64)}`,
            sig: 'delta-signature',
            size: '100KB',
          },
        },
      } });
      return;
    }
    // 下载自 v1.18.8 起是分块续传（download_chunk，前端循环调用到 done）；
    // 仍接受旧的 download 动作名，两者都必须把 sig 带上——这条断言的本意是
    // 「客户端不得丢掉增量包的签名」，与分不分块无关。
    if (action === 'download' || action === 'download_chunk') {
      submittedSignature = body.sig || null;
      await route.fulfill({ json: { code: 1, msg: 'contract captured' } });
      return;
    }
    await route.abort();
  });

  await page.goto('/admin/upgrade_online.php', { waitUntil: 'domcontentloaded' });
  await expect(page.locator('#uo-upgrade')).toBeVisible();
  await page.locator('#uo-upgrade').click();
  await expect.poll(() => submittedSignature).toBe('delta-signature');
  expect(consoleEntries, 'online upgrade contract must keep the console clean').toEqual([]);
});
