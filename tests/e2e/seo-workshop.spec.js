const { test, expect } = require('@playwright/test');

test.describe('SEO workshop @ci', () => {
  let scriptErrors;

  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/plugin.php');
    const activate = page.locator('#plugin-seo button[onclick*="pluginAction(\'activate\'"]');
    if (await activate.isVisible()) {
      // 插件页加载时还会 POST 拉市场列表（action=market_list，CI 无外网时返回 code 1），只认本次启用请求。
      const activated = page.waitForResponse((response) => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/admin/plugin.php'
        && new URLSearchParams(response.request().postData() || '').get('action') === 'activate');
      await activate.click();
      expect((await (await activated).json()).code).toBe(0);
    }
    scriptErrors = [];
    page.on('pageerror', (error) => scriptErrors.push(error.message));
    page.on('dialog', (dialog) => dialog.accept());
    await page.goto('/admin/plugin_page.php?plugin=seo');
  });

  test.afterEach(() => {
    expect(scriptErrors).toEqual([]);
  });

  test('generation controls and URL count initialize', async ({ page }, testInfo) => {
    const generate = page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true });
    await expect(generate).toBeVisible();
    await expect(generate).toBeEnabled();
    await expect(page.locator('strong[x-text="urlCount"]')).toHaveText(/^\d+$/);
    expect(Number(await page.locator('strong[x-text="urlCount"]').textContent())).toBeGreaterThan(0);
    await expect(page.getByRole('button', { name: /生成密钥/ })).toBeVisible();
    expect(await page.evaluate(() => typeof window.seoAutopush)).toBe('function');
    await page.screenshot({ path: testInfo.outputPath('seo-workshop.png') });
  });

  test('generate writes a readable file and persists after reopening', async ({ page, request }) => {
    const generated = page.waitForResponse((response) => response.request().method() === 'POST'
      && response.request().postData()?.includes('action=gen_llms'));
    await page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true }).click();
    expect((await (await generated).json()).code).toBe(0);
    await expect(page.getByRole('link', { name: '查看 /llms.txt', exact: true })).toBeVisible();
    const file = await request.get('/llms.txt');
    expect(file.ok()).toBe(true);
    expect(await file.text()).toMatch(/^# .+/m);
    await page.reload();
    await expect(page.getByRole('link', { name: '查看 /llms.txt', exact: true })).toBeVisible();
    await expect(page.locator('[x-show="genAt"]')).toContainText(/上次生成：\d{4}-\d{2}-\d{2}/);
    await expect(page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true })).toBeEnabled();
  });

  test('generation failure shows feedback and restores the button', async ({ page }) => {
    await page.route('**/admin/plugin_page.php?plugin=seo', async (route) => {
      if (route.request().method() === 'POST') {
        expect(new URLSearchParams(route.request().postData()).get('action')).toBe('gen_llms');
        await route.fulfill({ status: 200, json: { code: 1, msg: 'Fixture: generation refused' } });
      } else {
        await route.continue();
      }
    });
    const feedback = page.waitForEvent('dialog');
    await page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true }).click();
    expect((await feedback).message()).toBe('Fixture: generation refused');
    await expect(page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true })).toBeEnabled();
  });
});
