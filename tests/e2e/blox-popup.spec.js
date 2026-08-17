const { test, expect } = require('@playwright/test');

test('popup template creates, publishes, opens and restores focus @local', async ({ page }, testInfo) => {
  test.setTimeout(60_000);
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop popup lifecycle baseline');

  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
  const csrf = await page.locator('input[name="_token"]').first().inputValue();
  const created = await page.request.post('/admin/blox_templates.php', {
    form: { action: 'create_popup', name: `Popup E2E ${Date.now()}`, _token: csrf },
    maxRedirects: 0,
  });
  expect(created.status()).toBe(302);
  const location = created.headers().location || '';
  const match = location.match(/template=(\d+)/);
  expect(match).not.toBeNull();
  const id = Number(match[1]);

  try {
    await page.goto(location, { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('blox-popup-settings')).toBeVisible();
    await page.getByTestId('blox-popup-settings').locator('summary').click();
    await page.getByTestId('blox-popup-settings').locator('select').nth(0).selectOption('delay');
    await page.getByTestId('blox-popup-settings').locator('input[type="number"]').first().fill('0');
    await page.getByTestId('blox-popup-settings').locator('select').nth(1).selectOption('every');
    await page.getByTestId('blox-popup-settings').locator('summary').click();

    const saved = page.waitForResponse((response) => {
      const body = new URLSearchParams(response.request().postData() || '');
      return response.url().includes('/admin/blox_template_api.php') && body.get('action') === 'save_draft';
    });
    await page.getByTestId('blox-save').click();
    expect((await saved).ok()).toBe(true);
    await expect(page.getByTestId('blox-dirty')).toBeHidden();

    const published = page.waitForResponse((response) => {
      const body = new URLSearchParams(response.request().postData() || '');
      return response.url().includes('/admin/blox_template_api.php') && body.get('action') === 'publish';
    });
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('blox-publish-template').click();
    expect((await published).ok()).toBe(true);

    await page.goto(`/?preview=1&popup=${Date.now()}`, { waitUntil: 'domcontentloaded' });
    const popup = page.locator(`[data-blox-popup="${id}"]`);
    await expect(popup).toBeVisible();
    await expect(popup).toHaveAttribute('aria-hidden', 'false');
    const close = popup.locator('[data-popup-close]').last();
    await expect(close).toBeFocused();
    await close.click();
    await expect(popup).toBeHidden();
    await expect(popup).toHaveAttribute('aria-hidden', 'true');
  } finally {
    await page.request.post('/admin/blox_templates.php', {
      form: { action: 'unpublish', id: String(id), _token: csrf },
      maxRedirects: 0,
    });
    await page.request.post('/admin/blox_templates.php', {
      form: { action: 'delete', id: String(id), _token: csrf },
      maxRedirects: 0,
    });
  }
});
